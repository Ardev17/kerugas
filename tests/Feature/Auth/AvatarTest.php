<?php

use App\Enums\CommentStatus;
use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Province;
use App\Models\StationComment;
use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/**
 * Foto de perfil (Outubro 2026): upload seguro (re-codificado pelo GD),
 * visível para todos, removível pela moderação dentro do seu âmbito.
 */

/** Imagem real gerada pelo GD (não depende de UploadedFile::fake()->image). */
function avatarTestImage(int $width = 800, int $height = 600, string $format = 'png', string $append = ''): UploadedFile
{
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    $path = tempnam(sys_get_temp_dir(), 'avt');

    match ($format) {
        'jpg' => imagejpeg($img, $path, 90),
        'webp' => imagewebp($img, $path, 80),
        'gif' => imagegif($img, $path),
        default => imagepng($img, $path),
    };
    imagedestroy($img);

    if ($append !== '') {
        file_put_contents($path, $append, FILE_APPEND);
    }

    $mime = ['jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][$format] ?? 'image/png';

    return new UploadedFile($path, "foto.{$format}", $mime, null, true);
}

/** Ficheiro qualquer com nome/MIME à escolha (para os ataques). */
function avatarTestRawFile(string $contents, string $name, string $mime): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'avt');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, $mime, null, true);
}

function avatarTestStoredPath(User $user): string
{
    return (string) $user->fresh()->avatar_path;
}

beforeEach(function () {
    Storage::fake(AvatarService::DISK);

    $this->province = Province::factory()->create();
    $this->otherProvince = Province::factory()->create();
    $this->user = User::factory()->create(['role' => UserRole::USER, 'province_id' => $this->province->id]);
});

it('aceita uma foto, re-codifica num quadrado de 512 px e devolve o URL público', function () {
    Sanctum::actingAs($this->user);

    $res = $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage(900, 600)], ['Accept' => 'application/json'])
        ->assertOk();

    $path = avatarTestStoredPath($this->user);
    expect($path)->toMatch('#^avatars/[a-f0-9]{40}\.(webp|jpg)$#');
    expect($res->json('data.avatar_url'))->toEndWith('/api/v1/avatars/' . basename($path));

    Storage::disk(AvatarService::DISK)->assertExists($path);
    $info = getimagesizefromstring(Storage::disk(AvatarService::DISK)->get($path));
    expect([$info[0], $info[1]])->toBe([512, 512]);

    expect(AuditLog::where('action', 'profile.avatar_updated')->where('entity_id', $this->user->id)->exists())->toBeTrue();
});

it('aceita JPG e WebP', function (string $format) {
    if ($format === 'webp' && !(imagetypes() & IMG_WEBP)) {
        $this->markTestSkipped('O GD deste PHP não tem suporte WebP.');
    }
    Sanctum::actingAs($this->user);

    $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage(300, 300, $format)], ['Accept' => 'application/json'])
        ->assertOk();

    expect(avatarTestStoredPath($this->user))->not->toBe('');
})->with(['jpg', 'webp']);

it('nunca guarda o nome do cliente nem conteúdo escondido no ficheiro', function () {
    Sanctum::actingAs($this->user);

    $payload = '<?php system($_GET["c"]); ?><script>alert(1)</script>';
    $file = avatarTestImage(400, 400, 'jpg', $payload);

    $this->post('/api/v1/users/me/avatar', ['avatar' => $file], ['Accept' => 'application/json'])->assertOk();

    $path = avatarTestStoredPath($this->user);
    $stored = Storage::disk(AvatarService::DISK)->get($path);

    expect($path)->not->toContain('foto');
    expect($stored)->not->toContain('<?php')->not->toContain('<script>');
});

it('troca a foto e apaga a anterior', function () {
    Sanctum::actingAs($this->user);

    $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage()], ['Accept' => 'application/json'])->assertOk();
    $first = avatarTestStoredPath($this->user);

    $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage(500, 500, 'jpg')], ['Accept' => 'application/json'])->assertOk();
    $second = avatarTestStoredPath($this->user);

    expect($second)->not->toBe($first);
    Storage::disk(AvatarService::DISK)->assertMissing($first);
    Storage::disk(AvatarService::DISK)->assertExists($second);
});

it('recusa ficheiros que não são imagens, mesmo com extensão de imagem', function (UploadedFile $file) {
    Sanctum::actingAs($this->user);

    $this->post('/api/v1/users/me/avatar', ['avatar' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('avatar');

    expect($this->user->fresh()->avatar_path)->toBeNull();
    expect(Storage::disk(AvatarService::DISK)->allFiles())->toBe([]);
})->with([
    'php disfarçado de jpg' => fn () => avatarTestRawFile('<?php phpinfo(); ?>', 'foto.jpg', 'image/jpeg'),
    'html disfarçado de png' => fn () => avatarTestRawFile('<html><script>alert(1)</script></html>', 'foto.png', 'image/png'),
    'svg' => fn () => avatarTestRawFile('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'foto.svg', 'image/svg+xml'),
    'gif' => fn () => avatarTestImage(200, 200, 'gif'),
    'png truncado' => fn () => avatarTestRawFile(substr(file_get_contents(avatarTestImage(300, 300)->getRealPath()), 0, 60), 'foto.png', 'image/png'),
]);

it('recusa imagens demasiado grandes em dimensões (antes de as descodificar) ou pequenas demais', function (int $w, int $h) {
    Sanctum::actingAs($this->user);

    $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage($w, $h)], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('avatar');
})->with([
    'lado acima do máximo' => [AvatarService::MAX_SIDE + 1, 64],
    'demasiado pequena' => [32, 32],
]);

it('recusa um PNG que declara dimensões gigantes ("bomba de descompressão")', function () {
    Sanctum::actingAs($this->user);

    // Cabeçalho PNG válido a declarar 50 000 × 50 000 px — nunca chega ao GD.
    $png = file_get_contents(avatarTestImage(100, 100)->getRealPath());
    $ihdr = pack('NN', 50000, 50000) . substr($png, 24, 5);
    $png = substr($png, 0, 16) . $ihdr . pack('N', crc32('IHDR' . $ihdr)) . substr($png, 33);

    $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestRawFile($png, 'foto.png', 'image/png')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('avatar');
});

it('recusa ficheiros com mais de 5 MB', function () {
    Sanctum::actingAs($this->user);

    $this->post('/api/v1/users/me/avatar', ['avatar' => UploadedFile::fake()->create('foto.jpg', AvatarService::MAX_KB + 100, 'image/jpeg')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('avatar');
});

it('exige sessão para enviar ou remover a foto', function () {
    $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage()], ['Accept' => 'application/json'])->assertStatus(401);
    $this->deleteJson('/api/v1/users/me/avatar')->assertStatus(401);
});

it('não deixa definir avatar_path pelo PUT do perfil', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/v1/users/me', ['name' => 'Novo Nome', 'avatar_path' => 'avatars/../../.env'])->assertOk();

    expect($this->user->fresh()->avatar_path)->toBeNull();
});

it('serve a foto a qualquer pessoa, sem sessão, com cabeçalhos seguros', function () {
    Sanctum::actingAs($this->user);
    $url = $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage()], ['Accept' => 'application/json'])->json('data.avatar_url');

    app('auth')->forgetGuards();
    $relative = parse_url($url, PHP_URL_PATH);

    $res = $this->get($relative)->assertOk();
    expect($res->headers->get('Content-Type'))->toStartWith('image/');
    expect($res->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($res->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
    // exceção ao no-store da API (nome aleatório), mas só 1 dia por causa da moderação
    expect($res->headers->get('Cache-Control'))->toContain('max-age=86400')->not->toContain('no-store');
});

it('não serve nomes inventados, caminhos estranhos nem fotos removidas', function () {
    Sanctum::actingAs($this->user);
    $url = $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage()], ['Accept' => 'application/json'])->json('data.avatar_url');
    $relative = parse_url($url, PHP_URL_PATH);

    $this->get('/api/v1/avatars/' . str_repeat('a', 40) . '.webp')->assertNotFound();
    $this->get('/api/v1/avatars/..%2F..%2F.env')->assertNotFound();
    $this->get('/api/v1/avatars/foto.php')->assertNotFound();

    // ficheiro existente no disco mas sem dono → não é servido
    Storage::disk(AvatarService::DISK)->put('avatars/' . str_repeat('b', 40) . '.webp', 'x');
    $this->get('/api/v1/avatars/' . str_repeat('b', 40) . '.webp')->assertNotFound();

    $this->deleteJson('/api/v1/users/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
    $this->get($relative)->assertNotFound();
    expect(Storage::disk(AvatarService::DISK)->exists('avatars/' . basename($relative)))->toBeFalse();
    expect(AuditLog::where('action', 'profile.avatar_removed')->exists())->toBeTrue();
});

it('mostra a foto do autor nos comentários públicos', function () {
    Sanctum::actingAs($this->user);
    $url = $this->post('/api/v1/users/me/avatar', ['avatar' => avatarTestImage()], ['Accept' => 'application/json'])->json('data.avatar_url');

    $comment = StationComment::factory()->create(['user_id' => $this->user->id, 'status' => CommentStatus::VISIBLE]);
    app('auth')->forgetGuards();

    $this->getJson("/api/v1/stations/{$comment->station_id}/comments")
        ->assertOk()
        ->assertJsonPath('data.0.user_avatar_url', $url)
        ->assertJsonMissingPath('data.0.user_id');
});

/* ───────────────────────── Moderação ───────────────────────── */

function avatarTestUserWithPhoto(UserRole $role, ?int $provinceId): User
{
    $user = User::factory()->create(['role' => $role, 'province_id' => $provinceId]);
    $path = 'avatars/' . bin2hex(random_bytes(20)) . '.webp';
    Storage::disk(AvatarService::DISK)->put($path, 'img');
    $user->forceFill(['avatar_path' => $path])->save();

    return $user;
}

it('o Moderador remove a foto de um cliente; a pessoa é avisada e fica auditado', function () {
    $target = avatarTestUserWithPhoto(UserRole::USER, $this->province->id);
    $path = $target->avatar_path;
    $moderator = User::factory()->create(['role' => UserRole::MODERATOR]);
    Sanctum::actingAs($moderator);

    $this->deleteJson("/api/v1/admin/users/{$target->id}/avatar")->assertOk();

    expect($target->fresh()->avatar_path)->toBeNull();
    Storage::disk(AvatarService::DISK)->assertMissing($path);
    expect(AppNotification::where('user_id', $target->id)->where('title', 'Foto de perfil removida')->exists())->toBeTrue();
    expect(AuditLog::where('action', 'user.avatar_removed_by_moderator')->where('user_id', $moderator->id)->where('entity_id', $target->id)->exists())->toBeTrue();
});

it('o Admin só remove fotos na sua província e nunca de outro Admin', function () {
    $admin = User::factory()->create(['role' => UserRole::ADMIN, 'province_id' => $this->province->id]);
    $inProvince = avatarTestUserWithPhoto(UserRole::DRIVER, $this->province->id);
    $outside = avatarTestUserWithPhoto(UserRole::USER, $this->otherProvince->id);
    $otherAdmin = avatarTestUserWithPhoto(UserRole::ADMIN, $this->province->id);
    Sanctum::actingAs($admin);

    $this->deleteJson("/api/v1/admin/users/{$outside->id}/avatar")->assertForbidden();
    $this->deleteJson("/api/v1/admin/users/{$otherAdmin->id}/avatar")->assertForbidden();
    $this->deleteJson("/api/v1/admin/users/{$inProvince->id}/avatar")->assertOk();

    expect($outside->fresh()->avatar_path)->not->toBeNull();
    expect($otherAdmin->fresh()->avatar_path)->not->toBeNull();
    expect($inProvince->fresh()->avatar_path)->toBeNull();
});

it('o Super Admin Provincial remove a foto de um Admin da sua província, mas não a de um Super Admin', function () {
    $psa = User::factory()->create(['role' => UserRole::PROVINCE_SUPER_ADMIN, 'province_id' => $this->province->id]);
    $admin = avatarTestUserWithPhoto(UserRole::ADMIN, $this->province->id);
    $super = avatarTestUserWithPhoto(UserRole::SUPER_ADMIN, $this->province->id);
    $otherPsa = avatarTestUserWithPhoto(UserRole::PROVINCE_SUPER_ADMIN, $this->province->id);
    Sanctum::actingAs($psa);

    $this->deleteJson("/api/v1/admin/users/{$admin->id}/avatar")->assertOk();
    $this->deleteJson("/api/v1/admin/users/{$super->id}/avatar")->assertForbidden();
    $this->deleteJson("/api/v1/admin/users/{$otherPsa->id}/avatar")->assertForbidden();
});

it('o Super Admin geral remove qualquer foto', function () {
    $super = User::factory()->create(['role' => UserRole::SUPER_ADMIN]);
    $psa = avatarTestUserWithPhoto(UserRole::PROVINCE_SUPER_ADMIN, $this->otherProvince->id);
    Sanctum::actingAs($super);

    $this->deleteJson("/api/v1/admin/users/{$psa->id}/avatar")->assertOk();
    $this->deleteJson("/api/v1/admin/users/{$psa->id}/avatar")->assertStatus(422);
});

it('clientes, condutores e operadores não podem remover fotos de outros', function (UserRole $role) {
    $target = avatarTestUserWithPhoto(UserRole::USER, $this->province->id);
    Sanctum::actingAs(User::factory()->create(['role' => $role, 'province_id' => $this->province->id]));

    $this->deleteJson("/api/v1/admin/users/{$target->id}/avatar")->assertForbidden();
    expect($target->fresh()->avatar_path)->not->toBeNull();
})->with([UserRole::USER, UserRole::DRIVER, UserRole::STATION_OPERATOR]);

/* ───────────────────────── Limpeza ───────────────────────── */

it('kerugas:avatars-prune apaga só ficheiros sem dono e com mais de 1 hora', function () {
    $owned = avatarTestUserWithPhoto(UserRole::USER, $this->province->id);
    $trashed = avatarTestUserWithPhoto(UserRole::USER, $this->province->id);
    $trashed->delete(); // eliminação suave: a foto fica (a conta pode voltar)

    $disk = Storage::disk(AvatarService::DISK);
    $orphan = 'avatars/' . str_repeat('c', 40) . '.webp';
    $disk->put($orphan, 'x');
    touch($disk->path($orphan), now()->subHours(2)->getTimestamp());
    $fresh = 'avatars/' . str_repeat('d', 40) . '.webp';
    $disk->put($fresh, 'x');
    touch($disk->path($owned->avatar_path), now()->subHours(2)->getTimestamp());

    $this->artisan('kerugas:avatars-prune')->assertSuccessful();

    $disk->assertMissing($orphan);
    $disk->assertExists($fresh);
    $disk->assertExists($owned->avatar_path);
    $disk->assertExists($trashed->avatar_path);
});

it('apagar a conta definitivamente apaga a foto', function () {
    $user = avatarTestUserWithPhoto(UserRole::USER, $this->province->id);
    $path = $user->avatar_path;

    $user->forceDelete();

    Storage::disk(AvatarService::DISK)->assertMissing($path);
});

it('a foto de uma conta apagada (suave) deixa de ser servida', function () {
    $user = avatarTestUserWithPhoto(UserRole::USER, $this->province->id);
    $url = '/api/v1/avatars/' . basename($user->avatar_path);
    $this->get($url)->assertOk();

    $user->delete();

    $this->get($url)->assertNotFound();
});
