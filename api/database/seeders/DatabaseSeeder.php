<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Orquestra os seeders na ordem correta de dependências.
 * Território e modelos de SMS correm sempre (dados reais, necessários).
 * Os dados DEMO (contas com palavra-passe pública) NUNCA correm em produção:
 * o Super Admin real cria-se com `php artisan kerugas:create-super-admin`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            ProvinceSeeder::class,
            MunicipalitySeeder::class,
        ];

        if (!app()->isProduction()) {
            $seeders[] = DemoDataSeeder::class;
        }

        $seeders[] = SmsTemplateSeeder::class;

        $this->call($seeders);
    }
}
