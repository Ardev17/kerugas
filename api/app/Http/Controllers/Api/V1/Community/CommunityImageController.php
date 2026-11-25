<?php

namespace App\Http\Controllers\Api\V1\Community;

use App\Http\Controllers\Controller;
use App\Models\CommunityMessage;
use App\Services\Community\CommunityImageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve as fotos dos Vizinhos KeruGás (Outubro 2026). O nome é gerado pelo
 * servidor (160 bits) e só se serve enquanto a mensagem estiver visível —
 * removida, escondida ou apagada, a foto deixa de sair de imediato.
 * Content-Type fixo, nosniff e CSP "sandbox" (como as fotos de perfil).
 */
class CommunityImageController extends Controller
{
    public function show(string $file): Response
    {
        abort_unless(preg_match('#^' . CommunityImageService::FILE_PATTERN . '$#', $file) === 1, 404);

        $path = CommunityImageService::DIR . '/' . $file;
        abort_unless(CommunityMessage::query()->visible()->where('image_path', $path)->exists(), 404);

        $disk = Storage::disk(CommunityImageService::DISK);
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $file . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
