<?php

use App\Enums\UserRole;
use App\Jobs\RunBackupJob;
use App\Models\AuditLog;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Cópias de segurança (Outubro 2026) — botão do Super Admin geral. */

/** Exportador de mentira: escreve um .sql.gz pequeno (ou falha) em vez de chamar o mysqldump. */
class BackupFakeDumper extends DatabaseDumper
{
    public static bool $fail = false;

    public function __construct()
    {
        parent::__construct(['database' => 'x', 'username' => 'x']);
    }

    public function dump(string $target): array
    {
        if (self::$fail) {
            throw new RuntimeException('mysqldump: Access denied');
        }
        file_put_contents($target, gzencode("-- cópia de teste\n-- Dump completed on 2026-10-09\n"));

        return ['bytes' => (int) filesize($target), 'sha256' => (string) hash_file('sha256', $target), 'method' => 'fake', 'note' => null];
    }
}

function backupSuperAdmin(): User
{
    return User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => null, 'municipality_id' => null]);
}

/** Cria e executa uma cópia já (sem fila). */
function backupRunNow(?User $by = null, ?bool $files = null): BackupRun
{
    $service = app(BackupService::class);
    $run = $service->start($by, 'manual', $files, dispatch: false);

    return $service->run($run);
}

beforeEach(function () {
    BackupFakeDumper::$fail = false;
    $this->backupDir = sys_get_temp_dir() . '/kerugas-backup-test-' . uniqid();
    config(['backup.path' => $this->backupDir, 'backup.keep' => 2, 'backup.min_free_mb' => 0, 'backup.include_files' => true]);
    app()->instance(DatabaseDumper::class, new BackupFakeDumper());
    Storage::fake('local');
    Storage::disk('local')->put('avatars/a.webp', 'foto-a');
    Storage::disk('local')->put('community/b.webp', 'foto-b');
});

afterEach(function () {
    File::deleteDirectory($this->backupDir);
});

it('só o Super Admin geral acede às cópias de segurança', function () {
    $this->getJson('/api/v1/admin/backups')->assertUnauthorized();

    foreach ([UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::MODERATOR, UserRole::USER] as $role) {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/v1/admin/backups')->assertForbidden();
        $this->postJson('/api/v1/admin/backups')->assertForbidden();
    }
    expect(BackupRun::count())->toBe(0);

    Sanctum::actingAs(backupSuperAdmin());
    $this->getJson('/api/v1/admin/backups')->assertOk()->assertJsonPath('meta.busy', false);
});

it('o botão regista o pedido, põe-no na fila e deixa rasto na auditoria', function () {
    Bus::fake();
    $admin = backupSuperAdmin();
    Sanctum::actingAs($admin);

    $this->postJson('/api/v1/admin/backups')
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.requested_by', $admin->name);

    Bus::assertDispatched(RunBackupJob::class, fn (RunBackupJob $job) => $job->queue === 'backups');
    expect(AuditLog::where('action', 'backup.started')->where('user_id', $admin->id)->count())->toBe(1);
});

it('só deixa fazer uma cópia de cada vez', function () {
    Bus::fake();
    Sanctum::actingAs(backupSuperAdmin());

    $this->postJson('/api/v1/admin/backups')->assertStatus(202);
    $this->postJson('/api/v1/admin/backups')->assertStatus(409);

    expect(BackupRun::count())->toBe(1);
    $this->getJson('/api/v1/admin/backups')->assertJsonPath('meta.busy', true);
});

it('uma cópia presa há horas dá-se como falhada e deixa fazer outra', function () {
    Bus::fake();
    $stuck = BackupRun::create(['status' => BackupRun::RUNNING, 'trigger' => 'manual', 'include_files' => true]);
    $stuck->forceFill(['created_at' => now()->subHours(10)])->save();
    Sanctum::actingAs(backupSuperAdmin());

    $this->postJson('/api/v1/admin/backups')->assertStatus(202);

    expect($stuck->fresh()->status)->toBe(BackupRun::FAILED);
    expect(BackupRun::count())->toBe(2);
});

it('faz a cópia: base de dados e fotos, com tamanho e checksum', function () {
    $run = backupRunNow(backupSuperAdmin());

    expect($run->status)->toBe(BackupRun::COMPLETED);
    $dbPath = $this->backupDir . '/' . $run->db_file;
    expect(is_file($dbPath))->toBeTrue();
    expect($run->db_sha256)->toBe(hash_file('sha256', $dbPath));
    expect($run->db_bytes)->toBe(filesize($dbPath));

    expect($run->files_count)->toBe(2);
    $zip = new ZipArchive();
    expect($zip->open($this->backupDir . '/' . $run->files_file))->toBeTrue();
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    sort($names);
    expect($names)->toBe(['avatars/a.webp', 'community/b.webp']);

    expect(AuditLog::where('action', 'backup.completed')->count())->toBe(1);
    expect(glob($this->backupDir . '/*.part'))->toBeEmpty();
});

it('sem fotos, guarda só a base de dados', function () {
    $run = backupRunNow(backupSuperAdmin(), false);

    expect($run->status)->toBe(BackupRun::COMPLETED);
    expect($run->files_file)->toBeNull();
});

it('quando a base de dados falha, a cópia fica "falhada" com o motivo e sem ficheiros a meio', function () {
    BackupFakeDumper::$fail = true;

    $run = backupRunNow(backupSuperAdmin());

    expect($run->status)->toBe(BackupRun::FAILED);
    expect($run->error)->toContain('Access denied');
    expect($run->db_file)->toBeNull();
    expect(glob($this->backupDir . '/*'))->toBeEmpty();
    expect(AuditLog::where('action', 'backup.failed')->count())->toBe(1);
});

it('guarda só as últimas cópias e apaga os ficheiros das mais antigas', function () {
    $first = backupRunNow();
    $second = backupRunNow();
    $third = backupRunNow(); // BACKUP_KEEP=2 → a primeira sai

    expect($first->fresh()->purged_at)->not->toBeNull();
    expect(is_file($this->backupDir . '/' . $first->db_file))->toBeFalse();
    expect(is_file($this->backupDir . '/' . $first->files_file))->toBeFalse();
    expect($second->fresh()->purged_at)->toBeNull();
    expect(is_file($this->backupDir . '/' . $third->db_file))->toBeTrue();
});

it('descarrega por um link assinado e temporário', function () {
    $run = backupRunNow();
    Sanctum::actingAs(backupSuperAdmin());

    $url = $this->getJson("/api/v1/admin/backups/{$run->id}/link?kind=db")->assertOk()->json('data.url');
    expect(AuditLog::where('action', 'backup.download_link')->count())->toBe(1);

    $this->get($url)->assertOk()->assertDownload($run->db_file);

    $filesUrl = $this->getJson("/api/v1/admin/backups/{$run->id}/link?kind=files")->assertOk()->json('data.url');
    $this->get($filesUrl)->assertOk()->assertDownload($run->files_file);
});

it('com o nginx à frente, a descarga é entregue ao nginx (X-Accel-Redirect)', function () {
    config(['backup.accel_prefix' => '/_backups/']);
    $run = backupRunNow();
    Sanctum::actingAs(backupSuperAdmin());
    $url = $this->getJson("/api/v1/admin/backups/{$run->id}/link?kind=db")->json('data.url');

    $this->get($url)->assertOk()->assertHeader('X-Accel-Redirect', '/_backups/' . $run->db_file);
});

it('sem assinatura válida, ou com o link expirado, não descarrega', function () {
    $run = backupRunNow();
    Sanctum::actingAs(backupSuperAdmin());
    $url = $this->getJson("/api/v1/admin/backups/{$run->id}/link?kind=db")->json('data.url');

    $this->get("/api/v1/backups/{$run->id}/download/db")->assertForbidden();
    $this->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $url))->assertForbidden();

    $this->travel((int) config('backup.download_link_minutes') + 1)->minutes();
    $this->get($url)->assertForbidden();
});

it('só o Super Admin geral pede links de descarga', function () {
    $run = backupRunNow();

    Sanctum::actingAs(User::factory()->create(['role' => UserRole::ADMIN]));
    $this->getJson("/api/v1/admin/backups/{$run->id}/link?kind=db")->assertForbidden();
});

it('uma cópia já apagada não se descarrega', function () {
    $run = backupRunNow();
    Sanctum::actingAs(backupSuperAdmin());

    $this->deleteJson("/api/v1/admin/backups/{$run->id}")->assertOk()->assertJsonPath('data.purged', true);

    expect(is_file($this->backupDir . '/' . $run->db_file))->toBeFalse();
    $this->getJson("/api/v1/admin/backups/{$run->id}/link?kind=db")->assertNotFound();
    expect(AuditLog::where('action', 'backup.purged')->count())->toBe(1);
});

it('não apaga uma cópia que ainda está a decorrer', function () {
    $running = BackupRun::create(['status' => BackupRun::RUNNING, 'trigger' => 'manual', 'include_files' => true]);
    Sanctum::actingAs(backupSuperAdmin());

    $this->deleteJson("/api/v1/admin/backups/{$running->id}")->assertStatus(409);
});

it('o comando kerugas:backup faz a cópia já (backup noturno)', function () {
    $this->artisan('kerugas:backup')->assertSuccessful();

    $run = BackupRun::first();
    expect($run->trigger)->toBe('scheduled');
    expect($run->status)->toBe(BackupRun::COMPLETED);
    expect($run->files_count)->toBe(2);
});

it('o comando falha com o motivo quando a cópia não é possível', function () {
    BackupFakeDumper::$fail = true;

    $this->artisan('kerugas:backup --no-files')->assertFailed();

    expect(BackupRun::first()->status)->toBe(BackupRun::FAILED);
});
