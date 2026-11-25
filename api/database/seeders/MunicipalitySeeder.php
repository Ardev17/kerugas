<?php

namespace Database\Seeders;

use App\Models\Municipality;
use App\Models\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Os 326 municípios de Angola, pelas 21 províncias da nova Divisão
 * Político-Administrativa (Lei 14/24, de 5 de Setembro, em vigor desde
 * 01/01/2025).
 *
 * FONTE: anexo do Decreto Presidencial n.º 270/24 (Classificação dos
 * Municípios), que enumera os 326 municípios por província.
 *
 * Pode correr-se outra vez numa base de dados já em uso
 * (php artisan db:seed --class=MunicipalitySeeder):
 * - um município que já existe (mesmo slug) fica com o nome oficial e
 *   passa para a província certa (ex: Cangamba sai do Moxico Leste);
 * - um município renomeado pela lei (ex: Namibe -> Moçâmedes) é
 *   renomeado no mesmo registo, por isso os postos, utilizadores e
 *   subscrições ligados a ele continuam ligados;
 * - nada é apagado: os nomes antigos que já não existem na lei ficam
 *   como estão e aparecem num aviso no fim, para revisão manual.
 */
class MunicipalitySeeder extends Seeder
{
    public const DATA = [
        'Bengo' => ['Dande', 'Ambriz', 'Barra do Dande', 'Nambuangongo', 'Panguila', 'Quibaxe', 'Muxaluando', 'Bula Atumba', 'Pango Aluquém', 'Piri', 'Quicunzo', 'Úcua'],
        'Benguela' => ['Benguela', 'Lobito', 'Catumbela', 'Cubal', 'Baía Farta', 'Ganda', 'Navegantes', 'Balombo', 'Bocoio', 'Chongorói', 'Caimbambo', 'Biópio', 'Bolonguera', 'Catengue', 'Chila', 'Chicuma', 'Iambala', 'Babaera', 'Canhamela', 'Chindumbo', 'Egito Praia', 'Dombe Grande', 'Capupa'],
        'Bié' => ['Andulo', 'Cuito', 'Camacupa', 'Nharêa', 'Chinguar', 'Chitembo', 'Catabola', 'Cunhinga', 'Cuemba', 'Calucinga', 'Chicala', 'Chipeta', 'Luando', 'Ringoma', 'Umpulo', 'Cambândua', 'Lúbia', 'Mumbué', 'Belo Horizonte'],
        'Cabinda' => ['Cabinda', 'Cacongo', 'Buco Zau', 'Liambo', 'Belize', 'Ngoio', 'Massabi', 'Miconje', 'Necuto', 'Tando Zinze'],
        'Cuando' => ['Mavinga', 'Cuito Cuanavale', 'Dirico', 'Rivungo', 'Luiana', 'Mucusso', 'Xipundo', 'Dima', 'Luengue'],
        'Cuanza Norte' => ['Cazengo', 'Cambambe', 'Golungo Alto', 'Ambaca', 'Banga', 'Bolongongo', 'Lucala', 'Quiculungo', 'Samba Cajú', 'Ngonguembo', 'Aldeia Nova', 'Caculo Cabaça', 'Cerca', 'Luinga', 'Massangano', 'Tango', 'Terreiro'],
        'Cuanza Sul' => ['Gabela', 'Sumbe', 'Porto Amboim', 'Quibala', 'Seles', 'Waku Kungo', 'Gangula', 'Calulo', 'Cassongue', 'Mussende', 'Ebo', 'Condé', 'Conda', 'Quilenda', 'Boa Entrada', 'Pambangala', 'Amboiva', 'Lonhe', 'Munenga', 'Quissongo', 'Quenha', 'Quirimbo', 'Sanga', 'Gungo'],
        'Cubango' => ['Menongue', 'Cuchi', 'Calai', 'Cuangar', 'Nancova', 'Cutato', 'Caiundo', 'Savate', 'Chinguanja', 'Mavengue', 'Longa'],
        'Cunene' => ['Cuanhama', 'Namacunde', 'Ombadja', 'Cahama', 'Curoca', 'Cuvelai', 'Chissuata', 'Mupa', 'Naulila', 'Cafima', 'Mucope', 'Nehone', 'Chitado', 'Chiéde'],
        'Huambo' => ['Bailundo', 'Caála', 'Huambo', 'Cachiungo', 'Chicala Choloanga', 'Londuimbali', 'Mungo', 'Chinjenje', 'Ecunha', 'Ucuma', 'Longonjo', 'Alto Hama', 'Cuima', 'Bimbe', 'Chilata', 'Galanga', 'Sambo'],
        'Huíla' => ['Lubango', 'Matala', 'Caconda', 'Caluquembe', 'Chibia', 'Humpata', 'Cacula', 'Chicomba', 'Jamba Mineira', 'Quipungo', 'Hoque', 'Palanca', 'Chipindo', 'Cuvango', 'Gambos', 'Quilengues', 'Capunda Cavilongo', 'Dongo', 'Galangue', 'Capelongo', 'Chituto', 'Viti Vivali', 'Chicungo'],
        'Icolo e Bengo' => ['Catete', 'Calumbo', 'Sequele', 'Bom Jesus', 'Quiçama', 'Cabo Ledo', 'Cabiri'],
        'Luanda' => ['Cacuaco', 'Cazenga', 'Kilamba Kiaxi', 'Ingombota', 'Viana', 'Talatona', 'Maianga', 'Rangel', 'Samba', 'Sambizanga', 'Hoji ya Henda', 'Kilamba', 'Belas', 'Camama', 'Mulenvos', 'Mussulo'],
        'Lunda Norte' => ['Dundo', 'Chitato', 'Cuango', 'Lucapa', 'Mussungue', 'Cafunfo', 'Cambulo', 'Capenda Camulemba', 'Caungula', 'Cuilo', 'Lóvua', 'Lubalo', 'Xá Muteba', 'Cassanje Calucala', 'Luangue', 'Xá Cassau', 'Luremo', 'Camaxilo', 'Canzar'],
        'Lunda Sul' => ['Saurimo', 'Muconda', 'Cassengo', 'Cacolo', 'Dala', 'Muangueji', 'Alto Chicapa', 'Cazage', 'Chiluage', 'Luma Cassai', 'Muriege', 'Sombo', 'Xassengue', 'Cassai Sul'],
        'Malanje' => ['Malanje', 'Calandula', 'Cacuso', 'Cangandala', 'Cambundi Catembo', 'Cahombo', 'Kiwaba Nzoji', 'Kunda dya Baze', 'Quela', 'Massango', 'Marimba', 'Quirima', 'Caculama', 'Luquembo', 'Cambo Suinginge', 'Cateco Cangola', 'Mbanji ya Ngola', 'Muquixe', 'Pungu a Ndongo', 'Ngola Luiji', 'Quihuhu', 'Quitapa', 'Xandel', 'Capunda', 'Cuale', 'Milando', 'Quéssua'],
        'Moxico' => ['Luena', 'Lumbala Nguimbo', 'Camanongue', 'Cangamba', 'Léua', 'Alto Cuito', 'Cangumbe', 'Chiúme', 'Lucusse', 'Lutembo', 'Lutuai', 'Ninda'],
        'Moxico Leste' => ['Cazombo', 'Luau', 'Cameia', 'Luacano', 'Lago Dilolo', 'Nana Candundo', 'Caianda', 'Macondo', 'Lóvua do Zambeze'],
        'Namibe' => ['Moçâmedes', 'Tômbwa', 'Camucuio', 'Virei', 'Cacimbas', 'Bibala', 'Sacomar', 'Iona', 'Lucira'],
        'Uíge' => ['Uíge', 'Maquela do Zombo', 'Negage', 'Damba', 'Cangola', 'Bembe', 'Ambuíla', 'Dange Quitexe', 'Milunga', 'Mucaba', 'Sanza Pombo', 'Puri', 'Quimbele', 'Songo', 'Nova Esperança', 'Bungo', 'Lucunga', 'Quipedro', 'Vista Alegre', 'Alto Zaza', 'Nsosso', 'Sacandica', 'Massau'],
        'Zaire' => ['Mbanza Kongo', 'Soyo', 'Luvo', 'Nóqui', 'Nzeto', 'Cuimba', 'Tomboco', 'Lufico', 'Quêlo', 'Quindeje', 'Serra de Canda'],
    ];

    /**
     * Nomes antigos (slug) que a lei substituiu pelo nome oficial (slug).
     * Só se renomeia se o nome novo ainda não existir na base de dados.
     */
    private const RENOMEADOS = [
        'namibe' => 'mocamedes',
        'libolo' => 'calulo',
        'amboim' => 'gabela',
        'gonguembo' => 'ngonguembo',
        'katchiungo' => 'cachiungo',
        'ekunha' => 'ecunha',
        'londuimbale' => 'londuimbali',
        'tchicala-tcholoanga' => 'chicala-choloanga',
        'tchindjenje' => 'chinjenje',
        'jamba' => 'jamba-mineira',
        'kuvango' => 'cuvango',
        'alto-zambeze' => 'cazombo',
        'quissama' => 'quicama',
        'dembos-quibaxe' => 'quibaxe',
        'quilamba-quiaxi' => 'kilamba-kiaxi',
        'kivaba-nzogi' => 'kiwaba-nzoji',
        'cunda-dia-baze' => 'kunda-dya-baze',
        'caombo' => 'cahombo',
        'camacuio' => 'camucuio',
        'tombua' => 'tombwa',
        'lumbala-nguimbo-bundas' => 'lumbala-nguimbo',
    ];

    public function run(): void
    {
        $oficiais = [];

        foreach (self::DATA as $provinceName => $municipalities) {
            $province = Province::where('slug', Str::slug($provinceName))->first();
            if (!$province) {
                continue;
            }

            foreach ($municipalities as $name) {
                $slug = Str::slug($name);
                $oficiais[$slug] = true;
                $this->guardar($province, $name, $slug);
            }
        }

        // A API guarda estas listas em cache (1 hora): sem isto, os
        // formulários continuavam a mostrar os municípios antigos.
        Cache::forget('territory:provinces');
        foreach (Province::pluck('id') as $id) {
            Cache::forget("territory:province:{$id}:municipalities");
        }

        $antigos = Municipality::query()
            ->whereNotIn('slug', array_keys($oficiais))
            ->with('province:id,name')
            ->orderBy('name')
            ->get();

        if ($antigos->isNotEmpty()) {
            $this->command?->warn(sprintf(
                '%d município(s) fora da lista oficial (não apagados, rever à mão): %s',
                $antigos->count(),
                $antigos->map(fn ($m) => $m->name . ' (' . ($m->province?->name ?? '?') . ')')->implode(', ')
            ));
        }
    }

    private function guardar(Province $province, string $name, string $slug): void
    {
        // 1. Já existe com o nome oficial: acerta o nome e a província.
        $existente = Municipality::where('slug', $slug)->get();
        if ($existente->count() > 1) {
            $existente = $existente->where('province_id', $province->id);
        }
        if ($m = $existente->first()) {
            $m->update(['name' => $name, 'province_id' => $province->id]);

            return;
        }

        // 2. Existe com o nome antigo: renomeia o mesmo registo.
        $antigo = array_search($slug, self::RENOMEADOS, true);
        if ($antigo !== false && ($m = Municipality::where('slug', $antigo)->first())) {
            $m->update(['name' => $name, 'slug' => $slug, 'province_id' => $province->id]);

            return;
        }

        // 3. Município novo.
        Municipality::create(['province_id' => $province->id, 'name' => $name, 'slug' => $slug]);
    }
}
