<?php

use App\Enums\UserRole;
use App\Jobs\RunRestoreJob;
use App\Models\BackupRun;
use App\Models\User;
use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\RestoreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** Restaurar uma cópia pelo painel (Outubro 2026) — só o Super Admin geral. */

/** Exportador de mentira (não chama o mysqldump). */
class RestoreFakeDumper extends DatabaseDumper
{
    public function __construct()
    {
        parent::__construct(['database' => 'x', 'username' => 'x']);
    }

    public function dump(string $target): array
    {
        file_put_contents($target, gzencode("-- estado atual\n-- Dump completed on 2026-10-09\n"));

        return ['bytes' => (int) filesize($target), 'sha256' => (string) hash_file('sha256', $target), 'method' => 'fake', 'note' => null];
    }
}

function restoreSuperAdmin(): User
{
    return User::factory()->create(['role' => UserRole::SUPER_ADMIN, 'province_id' => null, 'municipality_id' => null]);
}

/** Envia um ficheiro inteiro numa só parte. */
function restoreSend($test, string $id, string $kind, string $name, string $bytes, int $offset = 0, bool $final = true)
{
    return $test->post("/api/v1/admin/restores/{$id}/chunk", [
        'kind' => $kind, 'name' => $name, 'offset' => $offset, 'final' => $final ? '1' : '0',
        'chunk' => UploadedFile::fake()->createWithContent('chunk', $bytes),
    ], ['Accept' => 'application/json']);
}

beforeEach(function () {
    $this->tmp = sys_get_temp_dir() . '/kerugas-restore-test-' . uniqid();
    config([
        'backup.path' => $this->tmp . '/backups',
        'backup.restore_path' => $this->tmp . '/restore',
        'backup.min_free_mb' => 0,
    ]);
    app()->instance(DatabaseDumper::class, new RestoreFakeDumper());
    Queue::fake();
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

it('só o Super Admin geral pode restaurar', function () {
    foreach ([UserRole::ADMIN, UserRole::PROVINCE_SUPER_ADMIN, UserRole::USER] as $role) {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $this->postJson('/api/v1/admin/restores')->assertForbidden();
    }
});

it('envia o .sql.gz em partes, retoma onde ficou, verifica e começa com palavra-passe + RESTAURAR', function () {
    $admin = restoreSuperAdmin();
    Sanctum::actingAs($admin);

    $id = $this->postJson('/api/v1/admin/restores')->assertCreated()->json('data.id');
    expect($id)->toMatch('/^[a-z0-9]{24}$/');

    $gz = gzencode("CREATE TABLE t (id int);\n-- Dump completed on 2026-10-09\n");
    $half = intdiv(strlen($gz), 2);

    restoreSend($this, $id, 'sql', 'kerugas-bd.sql.gz', substr($gz, 0, $half), 0, false)->assertOk();
    // Uma parte fora de ordem é recusada e diz onde retomar.
    restoreSend($this, $id, 'sql', 'kerugas-bd.sql.gz', 'xx', 3, false)->assertStatus(409)->assertJsonPath('data.received', $half);
    restoreSend($this, $id, 'sql', 'kerugas-bd.sql.gz', substr($gz, $half), $half, true)->assertOk()->assertJsonPath('data.complete', true);

    // Fotos por pastas: o caminho é normalizado; nada fora de avatars/community.
    restoreSend($this, $id, 'photo', 'backup/avatars/a.webp', 'img')->assertOk();
    restoreSend($this, $id, 'photo', 'avatars/../../.env', 'x')->assertStatus(422);
    restoreSend($this, $id, 'photo', 'avatars/shell.php', 'x')->assertStatus(422);

    $this->postJson("/api/v1/admin/restores/{$id}/verify")
        ->assertOk()
        ->assertJsonPath('data.photos_mode', 'folders')
        ->assertJsonPath('data.photos', 1);

    $this->postJson("/api/v1/admin/restores/{$id}/start", ['password' => 'errada', 'confirm' => 'RESTAURAR'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson("/api/v1/admin/restores/{$id}/start", ['password' => 'password', 'confirm' => 'restaurar'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    Queue::assertNothingPushed();

    $url = $this->postJson("/api/v1/admin/restores/{$id}/start", ['password' => 'password', 'confirm' => 'RESTAURAR'])
        ->assertStatus(202)->json('data.status_url');
    Queue::assertPushed(RunRestoreJob::class, fn ($job) => $job->sessionId === $id);

    // O estado lê-se pelo link assinado, sem sessão (os tokens vão ser substituídos).
    auth()->forgetGuards();
    $this->getJson($url)->assertOk()->assertJsonPath('data.state', 'queued');
    $this->getJson("/api/v1/restores/{$id}/status")->assertForbidden(); // sem assinatura
});

it('recusa uma cópia incompleta antes de pedir confirmação (nada é apagado)', function () {
    Sanctum::actingAs(restoreSuperAdmin());
    $id = $this->postJson('/api/v1/admin/restores')->json('data.id');

    restoreSend($this, $id, 'sql', 'meia.sql.gz', gzencode("CREATE TABLE t (id int);\n"))->assertOk();
    $this->postJson("/api/v1/admin/restores/{$id}/verify")->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'incompleto'));
    $this->postJson("/api/v1/admin/restores/{$id}/start", ['password' => 'password', 'confirm' => 'RESTAURAR'])->assertStatus(422);
    Queue::assertNothingPushed();
});

it('aceita só .sql/.sql.gz e .zip', function () {
    Sanctum::actingAs(restoreSuperAdmin());
    $id = $this->postJson('/api/v1/admin/restores')->json('data.id');

    restoreSend($this, $id, 'sql', 'dados.txt', 'x')->assertStatus(422);
    restoreSend($this, $id, 'zip', 'fotos.rar', 'x')->assertStatus(422);
});

it('não deixa outra conta mexer na sessão', function () {
    Sanctum::actingAs(restoreSuperAdmin());
    $id = $this->postJson('/api/v1/admin/restores')->json('data.id');

    Sanctum::actingAs(restoreSuperAdmin());
    $this->postJson("/api/v1/admin/restores/{$id}/verify")->assertForbidden();
});

it('um de cada vez: não começa com uma cópia de segurança a decorrer', function () {
    Sanctum::actingAs(restoreSuperAdmin());
    BackupRun::create(['trigger' => 'manual', 'status' => BackupRun::RUNNING, 'include_files' => false, 'started_at' => now()]);

    $this->postJson('/api/v1/admin/restores')->assertStatus(409);
});

it('restaura uma cópia do histórico sem enviar ficheiros', function () {
    $admin = restoreSuperAdmin();
    Sanctum::actingAs($admin);
    File::ensureDirectoryExists($this->tmp . '/backups');
    file_put_contents($this->tmp . '/backups/kerugas-bd-1.sql.gz', gzencode("-- Dump completed\n"));
    $run = BackupRun::create(['trigger' => 'manual', 'status' => BackupRun::COMPLETED, 'include_files' => false,
        'db_file' => 'kerugas-bd-1.sql.gz', 'started_at' => now(), 'finished_at' => now()]);

    $this->postJson("/api/v1/admin/backups/{$run->id}/restore", ['password' => 'errada', 'confirm' => 'RESTAURAR'])->assertStatus(422);
    $res = $this->postJson("/api/v1/admin/backups/{$run->id}/restore", ['password' => 'password', 'confirm' => 'RESTAURAR'])->assertStatus(202);

    Queue::assertPushed(RunRestoreJob::class, fn ($job) => $job->sessionId === $res->json('data.id'));
});

it('normaliza os caminhos das fotos das pastas escolhidas', function () {
    expect(RestoreService::photoPath('Transferências\\fotos\\community\\2026\\a.jpg'))->toBe('community/2026/a.jpg');
    expect(fn () => RestoreService::photoPath('documentos/a.jpg'))->toThrow(RuntimeException::class);
});
