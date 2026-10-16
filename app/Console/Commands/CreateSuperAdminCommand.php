<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\PhoneNumberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Cria (ou promove) o Super Admin real, sem palavras-passe públicas:
 *   php artisan kerugas:create-super-admin
 * A palavra-passe é pedida de forma oculta e nunca fica em histórico.
 */
class CreateSuperAdminCommand extends Command
{
    protected $signature = 'kerugas:create-super-admin
        {--name= : Nome completo}
        {--phone= : Telemóvel (ex.: 923456789 ou +244923456789)}
        {--email= : Email (opcional)}';

    protected $description = 'Cria o utilizador Super Admin (pede a palavra-passe de forma segura)';

    public function handle(PhoneNumberService $phones): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Nome completo')));
        $rawPhone = (string) ($this->option('phone') ?: $this->ask('Telemóvel'));
        $email = $this->option('email') ?: null;

        $phone = $phones->normalize($rawPhone);
        if ($name === '' || $phone === null) {
            $this->error('Nome e telemóvel angolano válido são obrigatórios.');

            return self::FAILURE;
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email inválido.');

            return self::FAILURE;
        }
        if ($email !== null && User::where('email', $email)->where('phone', '!=', $phone)->exists()) {
            $this->error('Esse email já pertence a outro utilizador.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Palavra-passe (mín. 10 caracteres)');
        if (mb_strlen($password) < 10) {
            $this->error('A palavra-passe deve ter pelo menos 10 caracteres.');

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Repita a palavra-passe')) {
            $this->error('As palavras-passe não coincidem.');

            return self::FAILURE;
        }

        $existing = User::where('phone', $phone)->first();
        if ($existing && $existing->role !== UserRole::SUPER_ADMIN
            && !$this->confirm("Já existe um utilizador com {$phone}. Promover a Super Admin e trocar a palavra-passe?", false)) {
            $this->warn('Cancelado.');

            return self::FAILURE;
        }

        User::updateOrCreate(['phone' => $phone], [
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => UserRole::SUPER_ADMIN,
            'status' => 'ACTIVE',
        ]);

        $this->info("Super Admin pronto. Entre com o telemóvel {$phone} e a palavra-passe definida.");

        return self::SUCCESS;
    }
}
