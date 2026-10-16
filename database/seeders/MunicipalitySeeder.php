<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seed de municípios reais de Angola, organizados pela nova divisão de
 * 21 províncias (Lei 14/24, em vigor desde 01/01/2025).
 *
 * DECISÃO TÉCNICA (secção 164 do master prompt): a lista oficial completa
 * é de 326 municípios; esta seed cobre um subconjunto real e verificado
 * (~150 municípios), suficiente para desenvolvimento e demonstração.
 * FONTES: Wikipédia "List of municipalities of Angola" (divisão pré-2025,
 * ainda válida para a maioria das províncias não afetadas pela reforma) e
 * o mapa oficial pós-reforma da Embaixada de Angola (Cabinda, Zaire, Uíge,
 * Bengo). Não é a lista oficial completa — antes de produção, reconciliar
 * com o Diário da República (Lei 14/24) e o Anuário do MAT.
 *
 * ATENÇÃO: a divisão exata dos municípios herdados pelas 3 novas
 * províncias (Cuando/Cubango, Luanda/Icolo e Bengo, Moxico/Moxico Leste)
 * é uma estimativa geográfica razoável, não uma confirmação oficial
 * município-a-município — marcado abaixo onde relevante.
 */
class MunicipalitySeeder extends Seeder
{
    private const DATA = [
        'Bengo' => ['Ambriz', 'Bula Atumba', 'Dande', 'Nambuangongo', 'Pango Aluquém', 'Quibaxe', 'Muxaluando'],

        // Estimativa geográfica pendente de confirmação oficial município-a-município.
        'Icolo e Bengo' => ['Catete', 'Cabo Ledo', 'Bom Jesus'],

        'Benguela' => ['Balombo', 'Baía Farta', 'Benguela', 'Bocoio', 'Caimbambo', 'Catumbela', 'Chongorói', 'Cubal', 'Ganda', 'Lobito'],

        'Bié' => ['Andulo', 'Camacupa', 'Catabola', 'Chinguar', 'Chitembo', 'Cuemba', 'Cunhinga', 'Cuíto', "N'harea"],

        'Cabinda' => ['Belize', 'Miconje', 'Cabinda', 'Ngoio', 'Liambo', 'Tando Zinze', 'Cacongo', 'Necuto', 'Massabi', 'Buco Zau'],

        // Estimativa geográfica pendente de confirmação oficial (divisão de Cuando Cubango).
        'Cuando' => ['Mavinga', 'Rivungo', 'Dirico', 'Nancova', 'Cuito Cuanavale'],
        'Cubango' => ['Menongue', 'Cuchi', 'Calai', 'Cuangar'],

        'Cuanza Norte' => ['Ambaca', 'Banga', 'Bolongongo', 'Cambambe', 'Cazengo', 'Golungo Alto', 'Gonguembo', 'Lucala', 'Quiculungo', 'Samba Cajú'],

        'Cuanza Sul' => ['Amboim', 'Porto Amboim', 'Cassongue', 'Cela', 'Waku Kungo', 'Conda', 'Ebo', 'Libolo', 'Mussende', 'Quibala', 'Quilenda', 'Seles', 'Sumbe'],

        'Cunene' => ['Cahama', 'Cuanhama', 'Curoca', 'Cuvelai', 'Namacunde', 'Ombadja'],

        'Huambo' => ['Bailundo', 'Caála', 'Katchiungo', 'Ekunha', 'Huambo', 'Londuimbale', 'Longonjo', 'Mungo', 'Tchicala-Tcholoanga', 'Tchindjenje', 'Ucuma'],

        'Huíla' => ['Caconda', 'Caluquembe', 'Chiange', 'Chibia', 'Chicomba', 'Chipindo', 'Gambos', 'Humpata', 'Jamba', 'Kuvango', 'Lubango', 'Matala', 'Quilengues', 'Quipungo'],

        // Sem Icolo e Bengo (agora província própria).
        'Luanda' => ['Belas', 'Cacuaco', 'Cazenga', 'Luanda', 'Kilamba Kiaxi', 'Talatona', 'Viana'],

        'Lunda Norte' => ['Cambulo', 'Capenda-Camulemba', 'Caungula', 'Chitato', 'Cuango', 'Cuilo', 'Lóvua', 'Lucapa', 'Xá-Muteba'],

        'Lunda Sul' => ['Saurimo', 'Cacolo', 'Dala', 'Muconda'],

        'Malanje' => ['Malanje', 'Calandula', 'Cangandala', 'Cacuso', 'Cambundi-Catembo', 'Cahombo'],

        // Estimativa geográfica pendente de confirmação oficial (divisão do Moxico).
        'Moxico' => ['Luena', 'Camanongue', 'Léua', 'Luau'],
        'Moxico Leste' => ['Alto Zambeze', 'Luchazes', 'Cameia', 'Cangamba'],

        'Namibe' => ['Namibe', 'Bibala', 'Camucuio', 'Tômbwa', 'Virei'],

        'Uíge' => [
            'Maquela do Zombo', 'Damba', 'Lucunga', 'Songo', 'Bembe', 'Sanza Pombo',
            'Milunga', 'Massau', 'Nsosso', 'Quimbele', 'Alto Zaza', 'Sacandica',
            'Nova Esperança', 'Dange Quitexe', 'Bungo', 'Ambuíla', 'Quipedro',
            'Negage', 'Vista Alegre', 'Uíge', 'Mucaba', 'Puri', 'Cangola',
        ],

        'Zaire' => ['Soyo', 'Nóqui', 'Luvo', 'Lufico', 'Quêlo', 'Mbanza Kongo', 'Serra de Canda', 'Nzeto', 'Quindeje', 'Tomboco', 'Cuimba'],
    ];

    public function run(): void
    {
        foreach (self::DATA as $provinceName => $municipalities) {
            $province = Province::where('slug', Str::slug($provinceName))->first();

            if (!$province) {
                continue;
            }

            foreach ($municipalities as $name) {
                Municipality::updateOrCreate(
                    ['province_id' => $province->id, 'slug' => Str::slug($name)],
                    ['name' => $name]
                );
            }
        }
    }
}
