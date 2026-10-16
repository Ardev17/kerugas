<?php

/**
 * Configurações específicas de domínio do KERUGAS.
 * Mantidas fora dos Models/Controllers para permitir ajuste pelo
 * Super Admin (/admin/settings) sem alterar código (secção 89).
 */
return [
    // SEO (Outubro 2026): domínio canónico do site, usado no sitemap dos postos.
    'site_url' => env('KERUGAS_SITE_URL', 'https://www.kerugas.ao'),

    'freshness_windows' => [
        'fresh_max_minutes' => (int) env('KERUGAS_FRESH_MAX_MINUTES', 15),
        'recent_max_minutes' => (int) env('KERUGAS_RECENT_MAX_MINUTES', 60),
        'stale_max_minutes' => (int) env('KERUGAS_STALE_MAX_MINUTES', 180),
    ],

    'sms' => [
        // mock | production | kamba
        'provider' => env('SMS_PROVIDER', 'mock'),
        // BUG CORRIGIDO (Setembro 2026): estas 3 credenciais viviam como
        // `env('SMS_API_KEY')` direto dentro de `AppServiceProvider::register()`
        // — fora de um ficheiro `config/*.php`. Depois de
        // `php artisan config:cache` (passo normal de produção, e que fazia
        // parte da própria checklist de ativação da Kamba), o Laravel deixa
        // de ler o `.env` em runtime para qualquer coisa fora de `config/`,
        // e esse `env()` passava a devolver sempre `null` — rebentando com
        // "Argument #1 ($apiKey) must be of type string, null given" logo
        // que alguém tentasse enviar um SMS ou consultar o saldo. Só
        // funcionava, por coincidência, enquanto ninguém corria
        // `config:cache` (ambiente de desenvolvimento). Lido aqui (dentro de
        // `config/kerugas.php`), o valor fica corretamente cacheado.
        'api_key' => env('SMS_API_KEY'),
        'api_secret' => env('SMS_API_SECRET'),
        'webhook_secret' => env('SMS_WEBHOOK_SECRET'),
        // KambaSMS (Setembro 2026): ver App\Services\Sms\KambaSmsProvider
        // para os endpoints reais confirmados (dashboard/docs deles) e
        // para a justificação de `verifyWebhook()` rejeitar sempre — a
        // API deles não tem nenhum endpoint de callback/webhook.
        'kamba' => [
            'base_url' => env('KAMBA_SMS_BASE_URL', 'https://nexasms-api.onrender.com'),
            // Informativo apenas — o Sender ID é definido no dashboard
            // deles ao criar a API key (ligado à própria chave) e nunca é
            // enviado no corpo do pedido de `POST /messages/send`. Mantido
            // aqui só para documentar qual está associado à chave em uso.
            'sender_id' => env('KAMBA_SMS_SENDER_ID', 'KERUGAS'),
        ],
    ],

    'map' => [
        'provider' => env('MAP_PROVIDER', 'osm'),
    ],

    // Formulário de Contacto público (Setembro 2026) — destinatário do
    // email de cada nova mensagem. Separado de `MAIL_FROM_ADDRESS`
    // (esse é o remetente técnico dos emails da app, este é para onde
    // as mensagens dos visitantes devem chegar — podem ser endereços
    // diferentes, ex. um caindo numa caixa de suporte partilhada).
    // Geocodificação inversa (Setembro 2026): deduz província/município a
    // partir das coordenadas no registo e no pedido de gás. Nominatim exige
    // um User-Agent que identifique a aplicação e um contacto.
    'geocoding' => [
        'url' => env('KERUGAS_GEOCODER_URL', 'https://nominatim.openstreetmap.org/reverse'),
        'user_agent' => env('KERUGAS_GEOCODER_USER_AGENT', 'KeruGas/1.0 (contacto@kerugas.ao)'),
    ],

    'contact' => [
        'recipient' => env('CONTACT_RECIPIENT_EMAIL', 'contacto@kerugas.com'),
        // Telefone da central usado nos textos das campanhas ("ligue
        // {central}") — o mesmo número que o site mostra em
        // apps/web/src/constants/contact.ts. Substituir pelo real.
        'central_phone' => env('KERUGAS_CENTRAL_PHONE', '+244923000000'),
    ],

    // Campanhas de notificações (Setembro 2026) — ver
    // App\Services\Campaigns\CampaignService.
    'notifications' => [
        'timezone' => env('KERUGAS_NOTIFICATIONS_TIMEZONE', 'Africa/Luanda'),
        // Horas de silêncio: nada sai entre quiet_start e quiet_end.
        'quiet_start' => (int) env('KERUGAS_QUIET_HOURS_START', 21),
        'quiet_end' => (int) env('KERUGAS_QUIET_HOURS_END', 8),
        // Só para estimar o custo no painel (preço do pacote KambaSMS em uso).
        'sms_price_kz' => (float) env('KERUGAS_SMS_PRICE_KZ', 100),
    ],

    // Notificações push do app Android (Setembro 2026) — Firebase Cloud
    // Messaging, API HTTP v1. `credentials` é o caminho para o JSON da
    // conta de serviço (Firebase Console › Definições do projeto › Contas
    // de serviço › Gerar nova chave privada). NUNCA commitar esse
    // ficheiro. Sem ele, o push fica desligado em silêncio (NullPushSender)
    // e as notificações continuam a aparecer dentro do app/site.
    'push' => [
        'fcm_credentials' => env('FCM_CREDENTIALS_PATH'),
        // App iPhone (Outubro 2026) — push direto na Apple (APNs), sem Firebase.
        // Chave .p8 criada em developer.apple.com › Keys › Apple Push
        // Notifications service (APNs). NUNCA commitar o .p8.
        // `sandbox` só para builds de desenvolvimento ligadas pelo Xcode;
        // TestFlight e App Store usam sempre produção (false).
        'apns' => [
            'key_path' => env('APNS_KEY_PATH'),
            'key_id' => env('APNS_KEY_ID'),
            'team_id' => env('APNS_TEAM_ID'),
            'bundle_id' => env('APNS_BUNDLE_ID', 'ao.kerugas.app'),
            'sandbox' => (bool) env('APNS_SANDBOX', false),
        ],
        // Web Push no navegador (VAPID). Gerar com `php artisan kerugas:vapid`.
        // O nome das variáveis mantém o que o .env.example já tinha.
        'vapid_public_key' => env('PUSH_VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('PUSH_VAPID_PRIVATE_KEY'),
        'vapid_subject' => env('PUSH_VAPID_SUBJECT', 'mailto:contacto@kerugas.com'),
    ],

    // Vizinhos — partilhar a localização (Outubro 2026). Só de dia: das 18h às
    // 8h os postos estão fechados e a partilha fica desligada; qualquer partilha
    // acaba às 18h, mesmo que a duração escolhida fosse maior.
    // Carregamentos pela central e por SMS (Outubro 2026).
    'pickups' => [
        'central_max_quantity' => (int) env('PICKUP_CENTRAL_MAX_QTY', 100),
        'central_max_hours_back' => (int) env('PICKUP_CENTRAL_MAX_HOURS_BACK', 48),
        'central_default_days' => 7,
        'central_max_days' => 30,
        // Mais do que isto num dia por um Admin avisa os Super Admins.
        'central_admin_alert_per_day' => (int) env('PICKUP_CENTRAL_ALERT_PER_DAY', 5),
        'sms_max_quantity' => (int) env('PICKUP_SMS_MAX_QTY', 50),
        'sms_per_day' => (int) env('PICKUP_SMS_PER_DAY', 10),
        'pin_max_failures' => 5,
        'pin_lock_minutes' => 60,
    ],

    'community_location' => [
        'timezone' => env('COMMUNITY_LOCATION_TIMEZONE', 'Africa/Luanda'),
        'opens_at' => env('COMMUNITY_LOCATION_OPENS', '08:00'),
        'closes_at' => env('COMMUNITY_LOCATION_CLOSES', '18:00'),
        'durations' => [30, 60, 120],          // minutos que a pessoa pode escolher
        'station_radius_m' => 300,             // "estou no posto": tem de estar a menos disto
        'max_accuracy_m' => 500,               // GPS mais impreciso do que isto é recusado
        'per_hour' => 6,                       // partilhas por pessoa por hora
    ],

    'order_rate_limit' => [
        'per_phone_per_hour' => (int) env('KERUGAS_ORDER_RATE_LIMIT', 3),
    ],

    // Versões do app Android (Outubro 2026). Abaixo de `min_version` o app
    // mostra "Atualize a KeruGás" e a API recusa os pedidos (426); abaixo de
    // `latest_version` só aparece um aviso que se pode fechar. Subir estes
    // valores DEPOIS de a nova versão estar publicada na Play Store.
    'app' => [
        'android' => [
            'min_version' => env('APP_ANDROID_MIN_VERSION', '1.0.0'),
            'latest_version' => env('APP_ANDROID_LATEST_VERSION', '1.0.0'),
            'store_url' => env('APP_ANDROID_STORE_URL', 'https://play.google.com/store/apps/details?id=ao.kerugas.app'),
            // false até o app estar publicado: o botão "Baixar app" do site
            // mostra "Brevemente no Google Play" (sem link). Pôr true no dia
            // em que a ficha da Play Store ficar pública.
            'published' => (bool) env('APP_ANDROID_PUBLISHED', false),
            // Versão de TESTE (Outubro 2026): link direto para o APK (ex.:
            // GitHub Releases). Enquanto o app não estiver na Play Store, o
            // site mostra "Baixar versão de teste (APK)" com este link e o
            // aviso de atualização do app aponta para aqui. Só https://.
            // Vazio = não há versão de teste pública.
            'apk_url' => env('APP_ANDROID_APK_URL', ''),
            // Rótulo opcional da versão de teste (ex.: "1.0.3-teste").
            'apk_version' => env('APP_ANDROID_APK_VERSION', ''),
        ],
        // App para iPhone/iPad (Outubro 2026) — ainda não existe. O site mostra
        // "Brevemente na App Store" até APP_IOS_PUBLISHED=true e o link da
        // ficha (https://apps.apple.com/...) estar em APP_IOS_STORE_URL.
        'ios' => [
            // Atualização obrigatória/aviso, como no Android: subir só depois
            // de a nova versão estar aprovada pela Apple.
            'min_version' => env('APP_IOS_MIN_VERSION', '1.0.0'),
            'latest_version' => env('APP_IOS_LATEST_VERSION', '1.0.0'),
            'published' => (bool) env('APP_IOS_PUBLISHED', false),
            'store_url' => env('APP_IOS_STORE_URL', ''),
        ],
    ],
];
