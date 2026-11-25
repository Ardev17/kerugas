<?php

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MunicipalityFactory extends Factory
{
    /**
     * O unique() do Faker guarda os valores já usados e tenta de novo até sair
     * um novo. Aplicado a citySuffix(), que tem menos de duas dezenas de
     * valores, esgota-se à vigésima vez e rebenta com OverflowException,foi
     * o que derrubou o teste do ataque em massa, que cria muitos municípios.
     *
     * A base de dados não exige nomes únicos: só o par (província, slug). Deste
     * contador vem essa garantia, sem limite de quantos municípios se criam.
     */
    private static int $sequencia = 0;

    public function definition(): array
    {
        $sequencia = ++self::$sequencia;
        $name = $this->faker->citySuffix() . ' ' . $this->faker->city();

        return [
            'province_id' => Province::factory(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . $sequencia . Str::random(3),
        ];
    }
}
