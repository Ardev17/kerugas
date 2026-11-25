<?php

namespace Database\Seeders;

use App\Enums\QueueLevel;
use App\Enums\UserRole;
use App\Models\Municipality;
use App\Models\Province;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Dados de demonstração (secções 126-127). Nunca usar em produção.
 * Passwords fictícias — apenas para ambiente local/desenvolvimento.
 * Todos os postos criados aqui têm nomes claramente identificados
 * como demo, para nunca serem confundidos com postos reais.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $lubango = Municipality::where('slug', 'lubango')->first();
        $luanda = Municipality::where('slug', 'ingombota')->first() ?? Municipality::where('slug', 'luanda')->first();
        $huilaProvince = Province::where('slug', 'huila')->first();
        $luandaProvince = Province::where('slug', 'luanda')->first();

        if (!$lubango || !$luanda) {
            $this->command?->warn('Municípios base não encontrados — execute ProvinceSeeder e MunicipalitySeeder primeiro.');

            return;
        }

        $demoPassword = Hash::make('Demo@2026');

        // Benguela (Outubro 2026): postos e contas demo para testar a província.
        $benguelaProvince = Province::where('slug', 'benguela')->first();
        $bgMun = fn (string $slug) => $benguelaProvince
            ? Municipality::where('province_id', $benguelaProvince->id)->where('slug', $slug)->first()
            : null;
        $benguela = $bgMun('benguela');
        $lobito = $bgMun('lobito');
        $catumbela = $bgMun('catumbela');
        $baiaFarta = $bgMun('baia-farta');

        // Cunene (Outubro 2026): postos demo em vários municípios.
        $cuneneProvince = Province::where('slug', 'cunene')->first();
        $cnMun = fn (string $slug) => $cuneneProvince
            ? Municipality::where('province_id', $cuneneProvince->id)->where('slug', $slug)->first()
            : null;
        $ondjiva = $cnMun('cuanhama');
        $xangongo = $cnMun('ombadja');
        $cahama = $cnMun('cahama');
        $cuvelai = $cnMun('cuvelai');
        $namacunde = $cnMun('namacunde');
        $curoca = $cnMun('curoca');

        $huila = ['province' => $huilaProvince, 'municipality' => $lubango];
        $lda = ['province' => $luandaProvince, 'municipality' => $luanda];
        $bg = ['province' => $benguelaProvince, 'municipality' => $benguela];
        $lob = ['province' => $benguelaProvince, 'municipality' => $lobito];

        // Todas as contas demo: password Demo@2026.
        $users = [
            ['name' => 'Super Admin Demo', 'phone' => '+244900000001', 'email' => 'superadmin@demo.kerugas.com', 'role' => UserRole::SUPER_ADMIN] + $huila,
            ['name' => 'Admin Demo', 'phone' => '+244900000002', 'email' => 'admin@demo.kerugas.com', 'role' => UserRole::ADMIN] + $huila,
            ['name' => 'Moderador Demo', 'phone' => '+244900000003', 'email' => 'moderador@demo.kerugas.com', 'role' => UserRole::MODERATOR] + $huila,
            ['name' => 'Operador Demo', 'phone' => '+244900000004', 'email' => 'operador@demo.kerugas.com', 'role' => UserRole::STATION_OPERATOR, 'station' => '[DEMO] Posto Central Lubango'] + $huila,
            ['name' => 'Utilizador Demo', 'phone' => '+244900000005', 'email' => 'utilizador@demo.kerugas.com', 'role' => UserRole::USER] + $huila,
            // Mais contas demo (Outubro 2026).
            ['name' => 'Provincial Huíla Demo', 'phone' => '+244900000006', 'email' => 'provincial.huila@demo.kerugas.com', 'role' => UserRole::PROVINCE_SUPER_ADMIN] + $huila,
            ['name' => 'Condutor Lubango Demo', 'phone' => '+244900000007', 'email' => 'condutor.lubango@demo.kerugas.com', 'role' => UserRole::DRIVER] + $huila,
            ['name' => 'Admin Luanda Demo', 'phone' => '+244900000008', 'email' => 'admin.luanda@demo.kerugas.com', 'role' => UserRole::ADMIN] + $lda,
            ['name' => 'Operador Talatona Demo', 'phone' => '+244900000009', 'email' => 'operador.talatona@demo.kerugas.com', 'role' => UserRole::STATION_OPERATOR, 'station' => '[DEMO] Posto Talatona'] + $lda,
            ['name' => 'Condutor Luanda Demo', 'phone' => '+244900000010', 'email' => 'condutor.luanda@demo.kerugas.com', 'role' => UserRole::DRIVER] + $lda,
            ['name' => 'Utilizador Luanda Demo', 'phone' => '+244900000011', 'email' => 'utilizador.luanda@demo.kerugas.com', 'role' => UserRole::USER] + $lda,
            // Benguela
            ['name' => 'Provincial Benguela Demo', 'phone' => '+244900000020', 'email' => 'provincial.benguela@demo.kerugas.com', 'role' => UserRole::PROVINCE_SUPER_ADMIN] + $bg,
            ['name' => 'Admin Benguela Demo', 'phone' => '+244900000021', 'email' => 'admin.benguela@demo.kerugas.com', 'role' => UserRole::ADMIN] + $bg,
            // Dois operadores no mesmo posto (um posto pode ter vários operadores).
            ['name' => 'Operador Benguela 1 Demo', 'phone' => '+244900000022', 'email' => 'operador1.benguela@demo.kerugas.com', 'role' => UserRole::STATION_OPERATOR, 'station' => '[DEMO] Posto Central Benguela'] + $bg,
            ['name' => 'Operador Benguela 2 Demo', 'phone' => '+244900000023', 'email' => 'operador2.benguela@demo.kerugas.com', 'role' => UserRole::STATION_OPERATOR, 'station' => '[DEMO] Posto Central Benguela'] + $bg,
            ['name' => 'Operador Lobito Demo', 'phone' => '+244900000024', 'email' => 'operador.lobito@demo.kerugas.com', 'role' => UserRole::STATION_OPERATOR, 'station' => '[DEMO] Posto Restinga Lobito'] + $lob,
            ['name' => 'Condutor Benguela Demo', 'phone' => '+244900000025', 'email' => 'condutor.benguela@demo.kerugas.com', 'role' => UserRole::DRIVER] + $bg,
            ['name' => 'Condutor Lobito Demo', 'phone' => '+244900000026', 'email' => 'condutor.lobito@demo.kerugas.com', 'role' => UserRole::DRIVER] + $lob,
            ['name' => 'Utilizador Benguela Demo', 'phone' => '+244900000027', 'email' => 'utilizador.benguela@demo.kerugas.com', 'role' => UserRole::USER] + $bg,
            ['name' => 'Utilizador Lobito Demo', 'phone' => '+244900000028', 'email' => 'utilizador.lobito@demo.kerugas.com', 'role' => UserRole::USER] + $lob,
            // Investidor (Outubro 2026): Benguela 10% e Lobito 12,5% da comissão.
            ['name' => 'Investidor Benguela Demo', 'phone' => '+244900000029', 'email' => 'investidor.benguela@demo.kerugas.com', 'role' => UserRole::INVESTOR] + $bg,
        ];

        $stations = [
            ['name' => '[DEMO] Posto Central Lubango', 'municipality' => $lubango, 'province' => $huilaProvince,
                'lat' => -14.9177, 'lng' => 13.4925, 'sms_phone' => '+244911000001', 'gas' => true, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Comercial da Huíla', 'municipality' => $lubango, 'province' => $huilaProvince,
                'lat' => -14.9250, 'lng' => 13.5010, 'sms_phone' => '+244911000002', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Talatona', 'municipality' => $luanda, 'province' => $luandaProvince,
                'lat' => -8.9147, 'lng' => 13.1858, 'sms_phone' => '+244911000003', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            // Benguela (Outubro 2026)
            ['name' => '[DEMO] Posto Central Benguela', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.5763, 'lng' => 13.4055, 'sms_phone' => '+244911000004', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Bairro da Graça', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.5862, 'lng' => 13.4168, 'sms_phone' => '+244911000005', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Restinga Lobito', 'municipality' => $lobito, 'province' => $benguelaProvince,
                'lat' => -12.3470, 'lng' => 13.5450, 'sms_phone' => '+244911000006', 'gas' => true, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Compão Lobito', 'municipality' => $lobito, 'province' => $benguelaProvince,
                'lat' => -12.3700, 'lng' => 13.5570, 'sms_phone' => '+244911000007', 'gas' => true, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Catumbela', 'municipality' => $catumbela, 'province' => $benguelaProvince,
                'lat' => -12.4310, 'lng' => 13.5470, 'sms_phone' => '+244911000008', 'gas' => false, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Baía Farta', 'municipality' => $baiaFarta, 'province' => $benguelaProvince,
                'lat' => -12.6120, 'lng' => 13.1980, 'sms_phone' => '+244911000009', 'gas' => true, 'queue' => QueueLevel::LOW],
            // Benguela (Outubro 2026) - postos adicionais
            ['name' => '[DEMO] Posto Benguela Caponte', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.562, 'lng' => 13.419, 'sms_phone' => '+244911000017', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Benguela Cavaco', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.59, 'lng' => 13.395, 'sms_phone' => '+244911000018', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Benguela Benfica', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.57, 'lng' => 13.43, 'sms_phone' => '+244911000019', 'gas' => true, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Benguela Cabuta', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.545, 'lng' => 13.402, 'sms_phone' => '+244911000020', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Benguela Zona Norte', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.548, 'lng' => 13.411, 'sms_phone' => '+244911000021', 'gas' => false, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Benguela Zona Sul', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.595, 'lng' => 13.41, 'sms_phone' => '+244911000022', 'gas' => true, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Benguela Zona Leste', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.58, 'lng' => 13.445, 'sms_phone' => '+244911000023', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Benguela Zona Oeste', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.572, 'lng' => 13.38, 'sms_phone' => '+244911000024', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Benguela Centro Antigo', 'municipality' => $benguela, 'province' => $benguelaProvince,
                'lat' => -12.577, 'lng' => 13.408, 'sms_phone' => '+244911000025', 'gas' => true, 'queue' => QueueLevel::LOW],
            // Cunene (Outubro 2026)
            ['name' => '[DEMO] Posto Central Ondjiva', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.0667, 'lng' => 15.7333, 'sms_phone' => '+244911000010', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Ondjiva Norte', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.0540, 'lng' => 15.7410, 'sms_phone' => '+244911000011', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Xangongo', 'municipality' => $xangongo, 'province' => $cuneneProvince,
                'lat' => -16.7500, 'lng' => 15.0833, 'sms_phone' => '+244911000012', 'gas' => true, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Cahama', 'municipality' => $cahama, 'province' => $cuneneProvince,
                'lat' => -15.1500, 'lng' => 14.2333, 'sms_phone' => '+244911000013', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            // VERIFICAR coordenadas no mapa antes de usar
            ['name' => '[DEMO] Posto Cuvelai', 'municipality' => $cuvelai, 'province' => $cuneneProvince,
                'lat' => -16.4833, 'lng' => 14.3500, 'sms_phone' => '+244911000014', 'gas' => false, 'queue' => QueueLevel::HIGH],
            // VERIFICAR coordenadas no mapa antes de usar
            ['name' => '[DEMO] Posto Namacunde', 'municipality' => $namacunde, 'province' => $cuneneProvince,
                'lat' => -17.2000, 'lng' => 15.9000, 'sms_phone' => '+244911000015', 'gas' => true, 'queue' => QueueLevel::LOW],
            // VERIFICAR coordenadas no mapa antes de usar
            ['name' => '[DEMO] Posto Curoca', 'municipality' => $curoca, 'province' => $cuneneProvince,
                'lat' => -17.1000, 'lng' => 14.6000, 'sms_phone' => '+244911000016', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            // Cunene (Outubro 2026) - zonas adicionais de Ondjiva
            ['name' => '[DEMO] Posto Ondjiva Zona 1', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.06, 'lng' => 15.728, 'sms_phone' => '+244911000026', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Ondjiva Zona 2', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.072, 'lng' => 15.74, 'sms_phone' => '+244911000027', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Ondjiva Zona 3', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.065, 'lng' => 15.745, 'sms_phone' => '+244911000028', 'gas' => true, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Ondjiva Zona 4', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.08, 'lng' => 15.73, 'sms_phone' => '+244911000029', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Ondjiva Zona 5', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.058, 'lng' => 15.735, 'sms_phone' => '+244911000030', 'gas' => false, 'queue' => QueueLevel::HIGH],
            ['name' => '[DEMO] Posto Ondjiva Zona 6', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.07, 'lng' => 15.72, 'sms_phone' => '+244911000031', 'gas' => true, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Ondjiva Zona 7', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.077, 'lng' => 15.748, 'sms_phone' => '+244911000032', 'gas' => true, 'queue' => QueueLevel::MEDIUM],
            ['name' => '[DEMO] Posto Ondjiva Zona 8', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.054, 'lng' => 15.718, 'sms_phone' => '+244911000033', 'gas' => false, 'queue' => QueueLevel::LOW],
            ['name' => '[DEMO] Posto Ondjiva Zona 9', 'municipality' => $ondjiva, 'province' => $cuneneProvince,
                'lat' => -17.086, 'lng' => 15.738, 'sms_phone' => '+244911000034', 'gas' => true, 'queue' => QueueLevel::HIGH],
        ];

        $stationIds = [];
        foreach ($stations as $s) {
            if (!$s['municipality'] || !$s['province']) {
                continue;
            }

            $station = Station::updateOrCreate(
                ['slug' => Str::slug($s['name'])],
                [
                    'name' => $s['name'],
                    'province_id' => $s['province']->id,
                    'municipality_id' => $s['municipality']->id,
                    'latitude' => $s['lat'],
                    'longitude' => $s['lng'],
                    'sms_phone' => $s['sms_phone'],
                    'status' => 'ACTIVE',
                    'current_gas_available' => $s['gas'],
                    'current_queue_level' => $s['queue'],
                    'current_confidence_score' => 80,
                    'status_updated_at' => now(),
                ]
            );
            $stationIds[$s['name']] = $station->id;
        }

        foreach ($users as $data) {
            if (!$data['province'] || !$data['municipality']) {
                continue; // província/município base ainda não semeados
            }

            User::updateOrCreate(
                ['phone' => $data['phone']],
                [
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $demoPassword,
                    'role' => $data['role'],
                    'status' => 'ACTIVE',
                    'province_id' => $data['province']->id,
                    'municipality_id' => $data['municipality']->id,
                    // Um operador pertence a um só posto; um posto pode ter vários.
                    'operated_station_id' => isset($data['station']) ? ($stationIds[$data['station']] ?? null) : null,
                    'driver_available' => $data['role'] === UserRole::DRIVER,
                ]
            );
        }

        // Associações do investidor demo (um município só tem um investidor em vigor).
        $investor = User::where('phone', '+244900000029')->first();
        foreach ([[$benguela, 10.0], [$lobito, 12.5]] as [$municipality, $pct]) {
            if ($investor && $municipality && !\App\Models\MunicipalityInvestment::query()->active()->where('municipality_id', $municipality->id)->exists()) {
                \App\Models\MunicipalityInvestment::create([
                    'municipality_id' => $municipality->id, 'user_id' => $investor->id, 'percentage' => $pct,
                    'starts_at' => now()->startOfMonth(), 'assigned_by' => User::where('phone', '+244900000001')->value('id'),
                ]);
            }
        }

        $this->command?->info('Dados de demonstração criados. Password para todas as contas demo: Demo@2026');
    }
}
