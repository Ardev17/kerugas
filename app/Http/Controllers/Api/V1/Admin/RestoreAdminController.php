<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackupRun;
use App\Services\Backup\ChunkOffsetMismatch;
use App\Services\Backup\RestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Restaurar uma cópia a partir do painel (Outubro 2026) — SÓ o Super Admin
 * geral. Ver RestoreService para o fluxo completo e as proteções.
 */
class RestoreAdminController extends Controller
{
    public function __construct(private RestoreService $restores) {}

    /** Abre uma sessão de envio. */
    public function store(Request $request): JsonResponse
    {
        $id = $this->restores->create($request->user());
        if (!$id) {
            return $this->busy();
        }

        return response()->json(['success' => true, 'data' => [
            'id' => $id,
            'chunk_bytes' => (int) config('backup.restore_chunk_kb') * 1024,
        ]], 201);
    }

    /** Recebe uma parte de um ficheiro. */
    public function chunk(Request $request, string $restore): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:sql,zip,photo'],
            'name' => ['required', 'string', 'max:500'],
            'offset' => ['required', 'integer', 'min:0'],
            'final' => ['required', 'boolean'],
            'chunk' => ['required', 'file', 'max:' . max(64, (int) config('backup.restore_chunk_kb') + 64)],
        ]);

        $this->own($request, $restore);
        try {
            $result = $this->restores->appendChunk($restore, $data['kind'], $data['name'], (int) $data['offset'], (bool) $data['final'], $request->file('chunk'));
        } catch (ChunkOffsetMismatch $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => ['received' => $e->received]], 409);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $result]);
    }

    /** Confirma que a cópia está inteira e mostra o resumo. Nada é apagado. */
    public function verify(Request $request, string $restore): JsonResponse
    {
        $this->own($request, $restore);
        try {
            $summary = $this->restores->verify($restore);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $summary]);
    }

    /** Começa o restauro (palavra-passe + "RESTAURAR"). */
    public function start(Request $request, string $restore): JsonResponse
    {
        $this->confirmIdentity($request);
        $this->own($request, $restore);
        try {
            $this->restores->start($restore, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => ['status_url' => $this->restores->statusUrl($restore)]], 202);
    }

    /** Restaurar uma cópia que já está no servidor (histórico), sem enviar nada. */
    public function fromBackup(Request $request, BackupRun $backupRun): JsonResponse
    {
        $this->confirmIdentity($request);
        $withFiles = $request->boolean('include_files');
        if (!$backupRun->isDownloadable() || !$backupRun->db_file) {
            return response()->json(['success' => false, 'message' => 'Esta cópia já não está disponível no servidor.'], 404);
        }

        $id = $this->restores->create($request->user(), 'backup', $backupRun, $withFiles && (bool) $backupRun->files_file);
        if (!$id) {
            return $this->busy();
        }
        $this->restores->start($id, $request->user());

        return response()->json(['success' => true, 'data' => ['id' => $id, 'status_url' => $this->restores->statusUrl($id)]], 202);
    }

    public function destroy(Request $request, string $restore): JsonResponse
    {
        $this->own($request, $restore);
        try {
            $this->restores->cancel($restore);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true]);
    }

    /** Estado do restauro — rota ASSINADA, sem sessão (ver routes/api.php). */
    public function status(string $restore): JsonResponse
    {
        try {
            $s = $this->restores->status($restore);
        } catch (RuntimeException) {
            return response()->json(['success' => false, 'message' => 'Restauro não encontrado.'], 404);
        }

        return response()->json(['success' => true, 'data' => [
            'state' => $s['state'],
            'stage' => $s['stage'],
            'message' => $s['message'],
            'error' => $s['error'],
            'progress' => $s['progress'] ?? 0,
            'summary' => $s['summary'],
            'safety_file' => $s['safety_file'],
            'started_at' => $s['started_at'] ?? null,
            'finished_at' => $s['finished_at'] ?? null,
        ]]);
    }

    // ------------------------------------------------------------------

    /** Palavra-passe da conta + a palavra RESTAURAR, escrita à mão. */
    private function confirmIdentity(Request $request): void
    {
        $request->validate([
            'password' => ['required', 'string'],
            'confirm' => ['required', 'string'],
        ]);
        if (!Hash::check((string) $request->input('password'), (string) $request->user()->password)) {
            abort(response()->json(['success' => false, 'message' => 'Palavra-passe errada.', 'errors' => ['password' => ['Palavra-passe errada.']]], 422));
        }
        if (trim((string) $request->input('confirm')) !== RestoreService::CONFIRM_WORD) {
            abort(response()->json(['success' => false, 'message' => 'Escreva RESTAURAR (em maiúsculas) para confirmar.', 'errors' => ['confirm' => ['Escreva RESTAURAR para confirmar.']]], 422));
        }
    }

    /** Só quem abriu a sessão mexe nela. */
    private function own(Request $request, string $restore): void
    {
        if (!$this->restores->exists($restore)) {
            abort(response()->json(['success' => false, 'message' => 'Sessão de restauro não encontrada (expirou?). Comece de novo.'], 404));
        }
        if ((int) $this->restores->status($restore)['user_id'] !== (int) $request->user()->id) {
            abort(response()->json(['success' => false, 'message' => 'Esta sessão de restauro pertence a outra conta.'], 403));
        }
    }

    private function busy(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'Já há uma cópia de segurança ou um restauro a decorrer. Aguarde que termine.'], 409);
    }
}
