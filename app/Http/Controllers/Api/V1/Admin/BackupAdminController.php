<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cópias de segurança (Outubro 2026) — SÓ o Super Admin geral. O botão
 * "Fazer backup" chama `store`; a página mostra o histórico (`index`) e
 * descarrega cada ficheiro por um link temporário (`link` + `download`).
 */
class BackupAdminController extends Controller
{
    public function __construct(private BackupService $backups) {}

    public function index(): JsonResponse
    {
        $this->backups->failStale();

        $runs = BackupRun::with('user:id,name')->orderByDesc('id')->limit(30)->get();

        return response()->json([
            'success' => true,
            'data' => $runs->map(fn (BackupRun $r) => $this->present($r))->values(),
            'meta' => [
                'busy' => $runs->contains(fn (BackupRun $r) => $r->isActive()),
                'keep' => (int) config('backup.keep'),
                'free_bytes' => $this->backups->freeBytes(),
                'auto_enabled' => (bool) config('backup.schedule.enabled'),
                'auto_time' => config('backup.schedule.time'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['include_files' => ['sometimes', 'boolean']]);

        $run = $this->backups->start($request->user(), 'manual', $data['include_files'] ?? null);
        if (!$run) {
            return response()->json(['success' => false, 'message' => 'Já existe uma cópia de segurança a decorrer. Aguarde que termine.'], 409);
        }

        return response()->json(['success' => true, 'data' => $this->present($run->load('user:id,name'))], 202);
    }

    /** Link de descarga que expira em poucos minutos. */
    public function link(Request $request, BackupRun $backupRun): JsonResponse
    {
        $kind = $request->query('kind') === 'files' ? 'files' : 'db';
        if (!$this->backups->path($backupRun, $kind)) {
            return response()->json(['success' => false, 'message' => 'Este ficheiro já não está disponível.'], 404);
        }

        $this->backups->audit($request->user(), 'backup.download_link', $backupRun, ['kind' => $kind]);

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $this->backups->signedUrl($backupRun, $kind),
                'expires_in_minutes' => (int) config('backup.download_link_minutes'),
            ],
        ]);
    }

    /** Descarga (rota assinada, sem sessão: ver routes/api.php). */
    public function download(BackupRun $backupRun, string $kind): Response
    {
        $path = $this->backups->path($backupRun, $kind === 'files' ? 'files' : 'db');
        if (!$path) {
            return response()->json(['success' => false, 'message' => 'Este ficheiro já não está disponível.'], 404);
        }

        // Com BACKUP_ACCEL_PREFIX (ex.: /_backups/) é o nginx quem envia o
        // ficheiro — o PHP não fica preso a servir cópias de muitos GB.
        if ($prefix = config('backup.accel_prefix')) {
            return response('', 200, [
                'Content-Type' => 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . basename($path) . '"',
                'X-Accel-Redirect' => rtrim((string) $prefix, '/') . '/' . basename($path),
                'Cache-Control' => 'no-store',
            ]);
        }

        return response()->download($path, basename($path), [
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Apaga os ficheiros de uma cópia (a linha fica no histórico). */
    public function destroy(Request $request, BackupRun $backupRun): JsonResponse
    {
        if ($backupRun->isActive()) {
            return response()->json(['success' => false, 'message' => 'Esta cópia ainda está a decorrer.'], 409);
        }
        if ($backupRun->purged_at === null) {
            $this->backups->purge($backupRun);
            $this->backups->audit($request->user(), 'backup.purged', $backupRun);
        }

        return response()->json(['success' => true, 'data' => $this->present($backupRun->refresh()->load('user:id,name'))]);
    }

    private function present(BackupRun $r): array
    {
        return [
            'id' => $r->id,
            'status' => $r->status,
            'trigger' => $r->trigger,
            'include_files' => $r->include_files,
            'db_bytes' => $r->db_bytes,
            'files_bytes' => $r->files_bytes,
            'files_count' => $r->files_count,
            'has_files' => (bool) $r->files_file && $r->isDownloadable(),
            'downloadable' => $r->isDownloadable(),
            'purged' => $r->purged_at !== null,
            'error' => $r->error,
            'warning' => $r->warning,
            'requested_by' => $r->user?->name,
            'created_at' => $r->created_at?->toIso8601String(),
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
        ];
    }
}
