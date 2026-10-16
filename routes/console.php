<?php

use App\Jobs\ExpireStaleStationStatusesJob;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Agendamento KERUGAS (secção 55/136)
|--------------------------------------------------------------------------
| Requer o container "scheduler" do docker-compose.yml a correr
| `php artisan schedule:run` a cada minuto (já configurado).
*/

Schedule::job(new ExpireStaleStationStatusesJob())
    ->hourly()
    ->name('kerugas:expire-stale-station-statuses')
    ->withoutOverlapping();

// Setembro 2026 (push do app Android): apaga os tokens de sessão do
// Sanctum já expirados (SANCTUM_TOKEN_EXPIRATION_MINUTES). Os telemóveis
// registados para push com essas sessões saem com eles (FK em cascata em
// device_tokens) — quem deixou a sessão expirar deixa de receber push.
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->name('kerugas:prune-expired-tokens')
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Campanhas de notificações (Setembro 2026)
|--------------------------------------------------------------------------
| Horas em hora de Angola. Cada campanha pode ser desligada pelo Super
| Admin no painel "Notificações" (o comando corre, mas não envia nada).
| As horas de silêncio (21:00–08:00) são garantidas também no próprio
| CampaignService — nenhuma destas horas cai dentro delas.
*/
$campaignTz = config('kerugas.notifications.timezone', 'Africa/Luanda');
$campaigns = [
    'driver_morning' => fn ($e) => $e->dailyAt('08:00'),
    'gas_reminder' => fn ($e) => $e->dailyAt('09:00'),
    'gas_reminder_followup' => fn ($e) => $e->dailyAt('09:30'),
    'safety_tip' => fn ($e) => $e->monthlyOn(1, '10:00'),
    'account_anniversary' => fn ($e) => $e->dailyAt('10:00'),
    'special_dates' => fn ($e) => $e->dailyAt('10:15'),
    'welcome_alerts' => fn ($e) => $e->dailyAt('11:00'),
    'we_miss_you' => fn ($e) => $e->dailyAt('12:00'),
    'weekend_eve' => fn ($e) => $e->fridays()->at('16:00'),
    'admin_daily_summary' => fn ($e) => $e->dailyAt('18:00'),
    'post_delivery' => fn ($e) => $e->hourly()->between('8:00', '20:59'),
    'operator_stale' => fn ($e) => $e->hourly()->between('8:00', '20:00'),
];
foreach ($campaigns as $key => $when) {
    $when(Schedule::command("kerugas:campaign {$key}"))
        ->timezone($campaignTz)
        ->name("kerugas:campaign:{$key}")
        ->withoutOverlapping()
        ->onOneServer()
        // Em segundo plano: uma campanha com milhares de clientes não
        // pode atrasar o resto do agendador (ex: envios agendados, que
        // correm a cada minuto).
        ->runInBackground();
}

// Envios manuais agendados pelo Super Admin.
Schedule::command('kerugas:broadcasts-due')
    ->everyMinute()
    ->name('kerugas:broadcasts-due')
    ->withoutOverlapping();

// Subscrições (Outubro 2026): lembrete ao operador 7 dias antes de a
// subscrição do posto terminar. Fora das horas de silêncio.
Schedule::command('kerugas:subscriptions-check')
    ->dailyAt('09:20')
    ->timezone($campaignTz)
    ->name('kerugas:subscriptions-check')
    ->withoutOverlapping()
    ->onOneServer();

// Fotos de perfil (Outubro 2026): limpeza diária de ficheiros sem dono.
Schedule::command('kerugas:avatars-prune')
    ->dailyAt('03:40')
    ->name('kerugas:avatars-prune')
    ->withoutOverlapping()
    ->onOneServer();

// Eliminação de conta (Outubro 2026): anonimiza as contas com o prazo de
// arrependimento terminado.
Schedule::command('kerugas:accounts-purge')
    ->dailyAt('03:20')
    ->name('kerugas:accounts-purge')
    ->withoutOverlapping()
    ->onOneServer();

// Vizinhos — partilha de localização (Outubro 2026): as coordenadas das
// partilhas que já acabaram são apagadas da base de dados a cada 5 minutos.
Schedule::command('kerugas:community-locations-prune')
    ->everyFiveMinutes()
    ->name('kerugas:community-locations-prune')
    ->withoutOverlapping()
    ->onOneServer();

// Relatórios de erros do site/app (Outubro 2026): retenção de 90 dias.
Schedule::command('kerugas:client-errors-prune')
    ->dailyAt('03:50')
    ->name('kerugas:client-errors-prune')
    ->withoutOverlapping()
    ->onOneServer();

// Cópias de segurança (Outubro 2026): além do botão do Super Admin, uma cópia
// automática todas as noites (hora de Angola). Desligado por omissão —
// BACKUP_SCHEDULE_ENABLED=true no .env. Corre em segundo plano para não
// atrasar o resto do agendador.
if (config('backup.schedule.enabled')) {
    Schedule::command('kerugas:backup')
        ->dailyAt((string) config('backup.schedule.time', '02:30'))
        ->timezone($campaignTz)
        ->name('kerugas:backup')
        ->withoutOverlapping(480)
        ->onOneServer()
        ->runInBackground();
}
