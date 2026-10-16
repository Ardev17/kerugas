<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Media\AvatarService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Fotos de perfil (Outubro 2026):
 *   php artisan kerugas:avatars-prune
 * Apaga ficheiros da pasta `avatars/` que nenhuma conta (nem apagada de
 * forma suave) usa — ex.: restos de um envio interrompido. Só toca em
 * ficheiros com o nome gerado pelo servidor e com mais de 1 hora (nunca
 * num envio que esteja a acontecer agora).
 */
class PruneAvatarsCommand extends Command
{
    protected $signature = 'kerugas:avatars-prune {--dry-run : Só lista, não apaga}';

    protected $description = 'Apaga fotos de perfil que já não pertencem a nenhuma conta';

    public function handle(): int
    {
        $disk = Storage::disk(AvatarService::DISK);
        $used = User::withTrashed()->whereNotNull('avatar_path')->pluck('avatar_path')->flip();
        $cutoff = now()->subHour()->getTimestamp();
        $deleted = 0;

        foreach ($disk->files(AvatarService::DIR) as $path) {
            if (!preg_match('#^' . AvatarService::DIR . '/' . AvatarService::FILE_PATTERN . '$#', $path)) {
                continue;
            }
            if ($used->has($path) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            if (!$this->option('dry-run')) {
                $disk->delete($path);
            }
            $deleted++;
        }

        $this->info(($this->option('dry-run') ? 'A apagar (simulação): ' : 'Apagadas: ') . $deleted . ' foto(s) sem dono.');

        return self::SUCCESS;
    }
}
