<?php

namespace App\Http\Controllers\Api\V1\StaffChat;

use App\Http\Controllers\Controller;
use App\Models\StaffChatMessage;
use App\Services\Community\CommunityImageService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve as fotos do Chat da Equipa (Outubro 2026). Como nas fotos dos
 * Vizinhos: nome gerado pelo servidor (160 bits), só enquanto a mensagem
 * estiver visível, Content-Type fixo, nosniff e CSP "sandbox".
 */
class StaffChatImageController extends Controller
{
    public function show(string $file): Response
    {
        abort_unless(preg_match('#^' . CommunityImageService::FILE_PATTERN . '$#', $file) === 1, 404);

        $path = CommunityImageService::DIR . '/' . $file;
        abort_unless(StaffChatMessage::query()->visible()->where('image_path', $path)->exists(), 404);

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
