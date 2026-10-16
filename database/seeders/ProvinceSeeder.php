<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seed das 21 províncias de Angola, em vigor desde 1 de janeiro de 2025
 * (Lei 14/24, de 5 de setembro — nova Divisão Político-Administrativa).
 *
 * FONTE: Lei 14/24 (Diário da República), amplamente reportada por
 * Vatican News, Público, CM Jornal e Wikipédia (Subdivisões de Angola).
 * As 3 novas províncias resultam da divisão de: Luanda (-> Icolo e Bengo),
 * Cuando Cubango (-> Cuando + Cubango) e Moxico (-> Moxico Leste).
 */
class ProvinceSeeder extends Seeder
{
    public const PROVINCES = [
        'Bengo',
        'Benguela',
        'Bié',
        'Cabinda',
        'Cuando',
        'Cubango',
        'Cuanza Norte',
        'Cuanza Sul',
        'Cunene',
        'Huambo',
        'Huíla',
        'Icolo e Bengo',
        'Luanda',
        'Lunda Norte',
        'Lunda Sul',
        'Malanje',
        'Moxico',
        'Moxico Leste',
        'Namibe',
        'Uíge',
        'Zaire',
    ];

    public function run(): void
    {
        foreach (self::PROVINCES as $name) {
            Province::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}
