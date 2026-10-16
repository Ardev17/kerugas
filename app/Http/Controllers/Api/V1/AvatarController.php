<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve as fotos de perfil (Outubro 2026). Pública, por decisão do
 * cliente: qualquer pessoa vê a foto de qualquer utilizador, mesmo sem
 * sessão iniciada.
 *
 * Os ficheiros vivem no disco privado; esta é a ÚNICA saída. O nome é
 * validado pela regex da rota (40 hex + .webp/.jpg, gerado pelo servidor)
 * e tem de pertencer a um utilizador existente (contas apagadas não contam)
 * — uma foto removida deixa de ser servida de imediato pelo servidor, mesmo
 * que o ficheiro ainda não tenha sido apagado (e dos navegadores em ≤ 1 dia). O Content-Type é fixo (nunca deduzido do ficheiro) e
 * `nosniff` + CSP impedem o navegador de o interpretar como outra coisa.
 */
class AvatarController extends Controller
{
    public function show(string $file): Response
    {
        $path = AvatarService::DIR . '/' . $file;

        abort_unless(preg_match('#^' . AvatarService::FILE_PATTERN . '$#', $file) === 1, 404);
        abort_unless(User::query()->where('avatar_path', $path)->exists(), 404);

        $disk = Storage::disk(AvatarService::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            // Nome aleatório e novo a cada troca, por isso pode ficar em cache;
            // 1 dia (e não "para sempre") para que uma foto removida pela
            // moderação desapareça também dos navegadores que já a tinham.
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
