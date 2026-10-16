<?php

use App\Http\Controllers\Api\V1\Admin\AdminAnalyticsController;
use App\Http\Controllers\Api\V1\Community\CommunityController;
use App\Http\Controllers\Api\V1\Community\CommunityImageController;
use App\Http\Controllers\Api\V1\Community\CommunityModerationController;
use App\Http\Controllers\Api\V1\Community\NeighborhoodController;
use App\Http\Controllers\Api\V1\StaffChat\StaffChatController;
use App\Http\Controllers\Api\V1\StaffChat\StaffChatImageController;
use App\Http\Controllers\Api\V1\RoomChat\RoomChatController;
use App\Http\Controllers\Api\V1\Admin\BillingController;
use App\Http\Controllers\Api\V1\Admin\CentralPickupController;
use App\Http\Controllers\Api\V1\Admin\PickupAdminController;
use App\Http\Controllers\Api\V1\Admin\DemandController;
use App\Http\Controllers\Api\V1\Admin\AdminDashboardController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\CommentModerationController;
use App\Http\Controllers\Api\V1\Admin\DriverAdminController;
use App\Http\Controllers\Api\V1\Admin\OperationController;
use App\Http\Controllers\Api\V1\Admin\SuperAdminMetricsController;
use App\Http\Controllers\Api\V1\Admin\SubscriptionController;
use App\Http\Controllers\Api\V1\Admin\ReportAdminController;
use App\Http\Controllers\Api\V1\Admin\SmsDispatchController;
use App\Http\Controllers\Api\V1\Admin\SmsTemplateController;
use App\Http\Controllers\Api\V1\Admin\StationAdminController;
use App\Http\Controllers\Api\V1\Admin\StationExportController;
use App\Http\Controllers\Api\V1\Admin\ContactMessageController;
use App\Http\Controllers\Api\V1\Admin\NotificationCenterController;
use App\Http\Controllers\Api\V1\Admin\UserAdminController;
use App\Http\Controllers\Api\V1\Admin\UserAvatarModerationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\ClientErrorController;
use App\Http\Controllers\Api\V1\Admin\ClientErrorAdminController;
use App\Http\Controllers\Api\V1\Admin\BackupAdminController;
use App\Http\Controllers\Api\V1\Admin\RestoreAdminController;
use App\Http\Controllers\Api\V1\AvatarController;
use App\Services\Media\AvatarService;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\Driver\DriverOrderController;
use App\Http\Controllers\Api\V1\Driver\DriverStockController;
use App\Http\Controllers\Api\V1\DriverRatingController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\GasOrderController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\NotificationPreferenceController;
use App\Http\Controllers\Api\V1\OrderPushController;
use App\Http\Controllers\Api\V1\WebPushSubscriptionController;
use App\Http\Controllers\Api\V1\Operator\OperatorStationController;
use App\Http\Controllers\Api\V1\Operator\OperatorPickupController;
use App\Http\Controllers\Api\V1\StationCommentController;
use App\Http\Controllers\Api\V1\StationConfirmationController;
use App\Http\Controllers\Api\V1\StationController;
use App\Http\Controllers\Api\V1\StationReportController;
use App\Http\Controllers\Api\V1\StationReviewController;
use App\Http\Controllers\Api\V1\TerritoryController;
use App\Http\Controllers\Api\V1\Webhooks\SmsWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API KERUGAS — v1
|--------------------------------------------------------------------------
| Todas as rotas versionadas sob /api/v1 (secção 49 do master prompt).
| Autenticação: Laravel Sanctum.
*/

Route::prefix('v1')->group(function () {

    // Configuração do app nativo (Outubro 2026): versão mínima e link da loja.
    Route::get('/app/config', [AppConfigController::class, 'show'])->middleware('throttle:60,1')->name('app.config');
    // Relatórios de erros do site/app (Outubro 2026) — ver ClientErrorService.
    Route::post('/client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:20,1')->name('client-errors.store');

    // Descarga de uma cópia de segurança (Outubro 2026): link ASSINADO que
    // expira em minutos, criado só para o Super Admin geral (ver BackupAdminController::link).
    Route::get('/backups/{backupRun}/download/{kind}', [BackupAdminController::class, 'download'])
        ->whereIn('kind', ['db', 'files'])
        ->middleware(['signed', 'throttle:20,1'])
        ->name('backups.download');

    // Estado de um restauro (Outubro 2026): link ASSINADO e sem sessão — a base
    // de dados (com os tokens de sessão) é substituída a meio. Também responde
    // com o site em manutenção (ver bootstrap/app.php). Sem throttle: o
    // limitador pode usar a base de dados, que está a ser reposta.
    Route::get('/restores/{restore}/status', [RestoreAdminController::class, 'status'])
        ->where('restore', '[a-z0-9]{24}')
        ->middleware('signed')
        ->name('restores.status');

    // Fotos de perfil (Outubro 2026): públicas (decisão do cliente), só
    // ficheiros com o nome gerado pelo servidor. Ver AvatarController.
    Route::get('/avatars/{file}', [AvatarController::class, 'show'])
        ->where('file', AvatarService::FILE_PATTERN)
        ->middleware('throttle:600,1')
        ->name('avatars.show');

    // Vizinhos KeruGás (Outubro 2026): fotos das mensagens (só enquanto visíveis).
    Route::get('/community/images/{file}', [CommunityImageController::class, 'show'])
        ->where('file', \App\Services\Community\CommunityImageService::FILE_PATTERN)
        ->middleware('throttle:600,1')
        ->name('community.images.show');
    // Conversa dos condutores e Conversa com a Central (Outubro 2026): fotos (só enquanto visíveis).
    Route::get('/rooms/images/{file}', [RoomChatController::class, 'image'])
        ->where('file', \App\Services\Community\CommunityImageService::FILE_PATTERN)
        ->middleware('throttle:600,1')
        ->name('rooms.images.show');
    // Chat da Equipa (Outubro 2026): fotos das mensagens (só enquanto visíveis).
    Route::get('/staff-chat/images/{file}', [StaffChatImageController::class, 'show'])
        ->where('file', \App\Services\Community\CommunityImageService::FILE_PATTERN)
        ->middleware('throttle:600,1')
        ->name('staff-chat.images.show');
    // Bairros de um município (público: alimenta o perfil e o registo).
    Route::get('/neighborhoods', [NeighborhoodController::class, 'index'])->middleware('throttle:public-read');

    // Autenticação (secção 17/18)
    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth');
        Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])->middleware('throttle:auth');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:auth');
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    });

    Route::middleware(['auth:sanctum', 'account.active', 'seen'])->group(function () {
        Route::get('/users/me', [AuthController::class, 'me']);
        // "Meu Perfil" (Outubro 2026): sempre a conta da própria sessão.
        Route::put('/users/me', [ProfileController::class, 'update'])->middleware('throttle:20,1');
        Route::put('/users/me/password', [ProfileController::class, 'password'])->middleware('throttle:6,1');
        Route::get('/users/me/sessions', [ProfileController::class, 'sessions']);
        Route::delete('/users/me/sessions/others', [ProfileController::class, 'revokeOtherSessions'])->middleware('throttle:10,1');
        // Eliminação da conta (Outubro 2026) — ver AccountDeletionService.
        Route::get('/users/me/deletion', [ProfileController::class, 'deletionStatus']);
        Route::post('/users/me/deletion', [ProfileController::class, 'requestDeletion'])->middleware('throttle:5,1');
        // Foto de perfil (Outubro 2026) — ver AvatarService.
        Route::post('/users/me/avatar', [ProfileController::class, 'avatar'])->middleware('throttle:10,1');
        Route::delete('/users/me/avatar', [ProfileController::class, 'removeAvatar'])->middleware('throttle:20,1');
    });

    // Conversas por sala (Outubro 2026): a conversa dos condutores (por
    // município) e a "Conversa com a Central" (utilizador ↔ direção), com as
    // mesmas características dos Vizinhos. Quem vê/escreve: RoomAccess.
    Route::prefix('rooms')->middleware(['auth:sanctum', 'account.active', 'seen'])->where(['type' => 'drivers|central|investor', 'roomId' => '[0-9]+'])->group(function () {
        Route::get('/{type}/{roomId}/overview', [RoomChatController::class, 'overview'])->middleware('throttle:60,1');
        Route::get('/{type}/{roomId}/messages', [RoomChatController::class, 'index'])->middleware('throttle:120,1');
        Route::post('/{type}/{roomId}/messages', [RoomChatController::class, 'store'])->middleware('throttle:30,1');
        Route::get('/{type}/{roomId}/mentionable', [RoomChatController::class, 'mentionable'])->middleware('throttle:60,1');
        Route::put('/messages/{message}', [RoomChatController::class, 'update'])->middleware('throttle:20,1');
        Route::delete('/messages/{message}', [RoomChatController::class, 'destroy'])->middleware('throttle:30,1');
        Route::put('/messages/{message}/reaction', [RoomChatController::class, 'react'])->middleware('throttle:60,1');
        Route::post('/messages/{message}/location/stop', [RoomChatController::class, 'stopLocation'])->middleware('throttle:30,1');
    });
    // Investidores (Outubro 2026). O investidor só vê a faturação dos SEUS
    // municípios em vigor; só o Super Admin GERAL associa/retira e vê tudo.
    Route::get('/investor/overview', [\App\Http\Controllers\Api\V1\Investor\InvestorController::class, 'overview'])
        ->middleware(['auth:sanctum', 'account.active', 'seen', 'role:INVESTOR', 'throttle:60,1']);
    Route::get('/investor/export', [\App\Http\Controllers\Api\V1\Investor\InvestorController::class, 'export'])
        ->middleware(['auth:sanctum', 'account.active', 'role:INVESTOR', 'throttle:20,1']);
    Route::prefix('admin/investors')->middleware(['auth:sanctum', 'account.active', 'role:SUPER_ADMIN'])->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'index'])->middleware('throttle:60,1');
        Route::post('/', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'store'])->middleware('throttle:30,1');
        Route::put('/{investor}/municipalities/{municipality}', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'update'])->middleware('throttle:30,1');
        Route::delete('/{investor}/municipalities/{municipality}', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'destroy'])->middleware('throttle:30,1');
        Route::get('/{investor}/overview', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'overview'])->middleware('throttle:60,1');
        Route::get('/{investor}/export', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'export'])->middleware('throttle:20,1');
        Route::post('/{investor}/deactivate', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'deactivate'])->middleware('throttle:20,1');
        Route::post('/{investor}/reactivate', [\App\Http\Controllers\Api\V1\Admin\InvestorAdminController::class, 'reactivate'])->middleware('throttle:20,1');
    });
    Route::get('/central-chat/me', [RoomChatController::class, 'centralMe'])->middleware(['auth:sanctum', 'account.active', 'throttle:120,1']);
    Route::get('/admin/central-threads', [RoomChatController::class, 'centralThreads'])
        ->middleware(['auth:sanctum', 'account.active', 'role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN', 'throttle:60,1']);

    // Chat da Equipa (Outubro 2026) — sala única dos admins de todo o país
    // (Super Admin, Super Admin Provincial e Admin). Só o Super Admin geral modera.
    Route::prefix('staff-chat')->middleware(['auth:sanctum', 'account.active', 'seen', 'role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN'])->group(function () {
        Route::get('/overview', [StaffChatController::class, 'overview'])->middleware('throttle:60,1');
        Route::get('/messages', [StaffChatController::class, 'index'])->middleware('throttle:120,1');
        Route::post('/messages', [StaffChatController::class, 'store'])->middleware('throttle:30,1');
        Route::put('/messages/{message}', [StaffChatController::class, 'update'])->middleware('throttle:20,1');
        Route::delete('/messages/{message}', [StaffChatController::class, 'destroy'])->middleware('throttle:30,1');
        Route::put('/messages/{message}/reaction', [StaffChatController::class, 'react'])->middleware('throttle:60,1');
        Route::get('/mentionable', [StaffChatController::class, 'mentionable'])->middleware('throttle:60,1');
        Route::get('/presence', [StaffChatController::class, 'presence'])->middleware('throttle:60,1');
        Route::post('/messages/{message}/pin', [StaffChatController::class, 'pin'])->middleware('throttle:30,1');
        Route::delete('/messages/{message}/pin', [StaffChatController::class, 'unpin'])->middleware('throttle:30,1');
        Route::post('/messages/{message}/remove', [StaffChatController::class, 'remove'])->middleware('throttle:60,1');
        Route::post('/messages/{message}/restore', [StaffChatController::class, 'restore'])->middleware('throttle:60,1');
        Route::delete('/messages/{message}/purge', [StaffChatController::class, 'purge'])->middleware('throttle:60,1');
        Route::get('/mutes', [StaffChatController::class, 'mutes']);
        Route::post('/mutes/{target}', [StaffChatController::class, 'mute'])->middleware('throttle:30,1');
        Route::delete('/mutes/{target}', [StaffChatController::class, 'unmute'])->middleware('throttle:30,1');
    });

    // Vizinhos KeruGás (Outubro 2026) — a comunidade de cada município. Qualquer
    // conta ativa entra; o município e a moderação seguem CommunityAccess.
    Route::prefix('community')->middleware(['auth:sanctum', 'account.active', 'seen'])->group(function () {
        Route::get('/overview', [CommunityController::class, 'overview'])->middleware('throttle:60,1');
        Route::post('/accept-rules', [CommunityController::class, 'acceptRules'])->middleware('throttle:10,1');
        Route::get('/messages', [CommunityController::class, 'index'])->middleware('throttle:120,1');
        Route::post('/messages', [CommunityController::class, 'store'])->middleware('throttle:20,1');
        Route::put('/messages/{message}', [CommunityController::class, 'update'])->middleware('throttle:20,1');
        // Partilhar a localização (Outubro 2026) — só de dia, expira sozinha.
        Route::post('/location', [CommunityController::class, 'shareLocation'])->middleware('throttle:10,1');
        Route::post('/messages/{message}/location/stop', [CommunityController::class, 'stopLocation'])->middleware('throttle:30,1');
        Route::get('/locations/active', [CommunityController::class, 'activeLocations'])->middleware('throttle:120,1');
        Route::delete('/messages/{message}', [CommunityController::class, 'destroy'])->middleware('throttle:30,1');
        Route::put('/messages/{message}/reaction', [CommunityController::class, 'react'])->middleware('throttle:60,1');
        Route::post('/messages/{message}/report', [CommunityController::class, 'report'])->middleware('throttle:20,1');
        Route::get('/mentionable', [CommunityController::class, 'mentionable'])->middleware('throttle:60,1');
        Route::get('/presence', [CommunityController::class, 'presence'])->middleware('throttle:60,1');
        Route::get('/blocks', [CommunityController::class, 'blocks']);
        Route::post('/blocks/{user}', [CommunityController::class, 'block'])->middleware('throttle:30,1');
        Route::delete('/blocks/{user}', [CommunityController::class, 'unblock'])->middleware('throttle:30,1');

        // Bairros: só Admin para cima (o âmbito é verificado no controlador).
        Route::middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN')->group(function () {
            Route::post('/neighborhoods', [NeighborhoodController::class, 'store'])->middleware('throttle:30,1');
            Route::put('/neighborhoods/{neighborhood}', [NeighborhoodController::class, 'update'])->middleware('throttle:30,1');
            Route::delete('/neighborhoods/{neighborhood}', [NeighborhoodController::class, 'destroy'])->middleware('throttle:30,1');
            Route::get('/moderation/stats', [CommunityModerationController::class, 'stats']);
        });

        // Moderação: a equipa (Moderador, Admin, PSA, Super Admin).
        Route::prefix('moderation')->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN,MODERATOR')->group(function () {
            Route::get('/messages', [CommunityModerationController::class, 'index']);
            Route::get('/messages/{message}/reports', [CommunityModerationController::class, 'reports']);
            Route::post('/messages/{message}/remove', [CommunityModerationController::class, 'remove'])->middleware('throttle:60,1');
            Route::post('/messages/{message}/restore', [CommunityModerationController::class, 'restore'])->middleware('throttle:60,1');
            Route::post('/messages/{message}/dismiss', [CommunityModerationController::class, 'dismiss'])->middleware('throttle:60,1');
            Route::post('/messages/{message}/pin', [CommunityModerationController::class, 'pin'])->middleware('throttle:30,1');
            Route::delete('/messages/{message}/pin', [CommunityModerationController::class, 'unpin'])->middleware('throttle:30,1');
            Route::delete('/messages/{message}', [CommunityModerationController::class, 'destroy'])->middleware('throttle:60,1');
            Route::get('/mutes', [CommunityModerationController::class, 'mutes']);
            Route::post('/mutes/{target}', [CommunityModerationController::class, 'mute'])->middleware('throttle:30,1');
            Route::delete('/mutes/{target}', [CommunityModerationController::class, 'unmute'])->middleware('throttle:30,1');
        });

        // Só o Super Admin geral: palavras proibidas e ligar/desligar por município.
        Route::prefix('moderation')->middleware('role:SUPER_ADMIN')->group(function () {
            Route::get('/banned-words', [CommunityModerationController::class, 'bannedWords']);
            Route::post('/banned-words', [CommunityModerationController::class, 'addBannedWord'])->middleware('throttle:30,1');
            Route::delete('/banned-words/{word}', [CommunityModerationController::class, 'removeBannedWord'])->middleware('throttle:30,1');
            Route::get('/municipalities', [CommunityModerationController::class, 'municipalities']);
            Route::put('/municipalities/{municipality}', [CommunityModerationController::class, 'toggleMunicipality'])->middleware('throttle:30,1');
        });
    });

    // Território (público, cacheado). AUDITORIA DE SEGURANÇA (Setembro
    // 2026): adicionado `throttle:public-read` a todas as rotas públicas
    // de leitura desta secção — antes não tinham nenhum limite.
    Route::get('/provinces', [TerritoryController::class, 'provinces'])->middleware('throttle:public-read');
    Route::get('/territory/locate', [TerritoryController::class, 'locate'])->middleware('throttle:geocode');
    Route::get('/provinces/{province}/municipalities', [TerritoryController::class, 'municipalities'])->middleware('throttle:public-read');

    // Tracking de eventos (secção 102) — leve, aceita utilizador autenticado ou anónimo
    Route::post('/analytics/events', [AnalyticsController::class, 'track'])->middleware('throttle:60,1');

    // Formulário de Contacto público do site (Setembro 2026) — pública
    // de propósito, tal como /orders: um visitante que quer falar
    // connosco não deve precisar de criar conta primeiro.
    Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:contact');

    // Postos (público). `/map/stations` devolve TODOS os postos ativos
    // de uma vez, sem paginação — é o alvo mais barato de abusar nesta
    // secção, daí o rate limit aqui ser tão importante quanto nas
    // rotas de escrita.
    Route::get('/stations/nearby', [StationController::class, 'nearby'])->middleware('throttle:public-read');
    Route::get('/stations/{station}', [StationController::class, 'show'])->middleware('throttle:public-read');
    Route::get('/map/stations', [StationController::class, 'forMap'])->middleware('throttle:public-read');
    // SEO (Outubro 2026): sitemap dos postos (o nginx serve-o em /sitemap-postos.xml).
    Route::get('/seo/sitemap-postos.xml', [\App\Http\Controllers\Api\V1\Seo\SitemapController::class, 'stations'])->middleware('throttle:public-read');

    // Comunidade — leitura pública, escrita autenticada + rate limit (secção 100)
    Route::get('/stations/{station}/reviews', [StationReviewController::class, 'index'])->middleware('throttle:public-read');
    Route::get('/stations/{station}/comments', [StationCommentController::class, 'index'])->middleware('throttle:public-read');

    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::post('/stations/{station}/reviews', [StationReviewController::class, 'store'])
            ->middleware('throttle:social');
        Route::post('/stations/{station}/comments', [StationCommentController::class, 'store'])
            ->middleware('throttle:social');
        Route::post('/stations/{station}/reports', [StationReportController::class, 'store'])
            ->middleware('throttle:social');
        Route::post('/stations/{station}/confirmations', [StationConfirmationController::class, 'store'])
            ->middleware('throttle:social');

        Route::get('/favorites', [FavoriteController::class, 'index']);
        Route::post('/favorites/{station}', [FavoriteController::class, 'store']);
        Route::delete('/favorites/{station}', [FavoriteController::class, 'destroy']);

        Route::get('/alerts', [AlertController::class, 'index']);
        Route::post('/alerts', [AlertController::class, 'store']);
        Route::put('/alerts/{alert}', [AlertController::class, 'update']);
        Route::delete('/alerts/{alert}', [AlertController::class, 'destroy']);

        // Telemóveis registados para notificações push (app Android).
        Route::post('/devices', [DeviceTokenController::class, 'store'])->middleware('throttle:devices');
        Route::delete('/devices', [DeviceTokenController::class, 'destroy'])->middleware('throttle:devices');

        // Web Push no navegador (VAPID) e preferências de notificações (Setembro 2026).
        Route::get('/web-push/public-key', [WebPushSubscriptionController::class, 'publicKey']);
        Route::post('/web-push/subscriptions', [WebPushSubscriptionController::class, 'store'])->middleware('throttle:devices');
        Route::delete('/web-push/subscriptions', [WebPushSubscriptionController::class, 'destroy'])->middleware('throttle:devices');
        Route::get('/users/me/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('/users/me/notification-preferences', [NotificationPreferenceController::class, 'update']);

        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::get('/notifications/{notification}', [NotificationController::class, 'show'])->whereNumber('notification');
    });

    // Pedidos de gás
    Route::post('/orders', [GasOrderController::class, 'store'])
        ->middleware('throttle:orders'); // rate limit dedicado — ver RouteServiceProvider
    // BUG CORRIGIDO (Setembro 2026): esta rota estava FORA do grupo
    // autenticado, mas o controller chama `authorize('view', $order)`
    // (só o dono ou um Admin podem ver). Sem `auth:sanctum`, o Laravel
    // nunca lia o Bearer token e tratava toda a gente como visitante —
    // a resposta era sempre 403, inclusive para o próprio dono.
    Route::get('/orders/reference/{reference}', [GasOrderController::class, 'showByReference'])
        ->middleware(['auth:sanctum', 'account.active']);

    // Acompanhamento sem conta (Outubro 2026): referência + telefone.
    Route::get('/orders/reference/{reference}/track', [GasOrderController::class, 'trackByPhone'])
        ->middleware('throttle:ratings');

    // Avisos push do pedido para quem não tem conta (Outubro 2026):
    // referência + telefone como prova de posse, como acima.
    Route::post('/orders/reference/{reference}/push', [OrderPushController::class, 'store'])
        ->middleware('throttle:ratings');
    Route::delete('/orders/reference/{reference}/push', [OrderPushController::class, 'destroy'])
        ->middleware('throttle:ratings');

    // Avaliação do condutor (Setembro 2026) — pública de propósito
    // (ver comentário no topo de DriverRatingController): a maioria
    // dos clientes que pedem gás não tem conta. Referência + telefone
    // do pedido servem de "prova de posse", tal como no fluxo acima.
    // `throttle:ratings` limita por IP e por referência (ver
    // AppServiceProvider) contra tentativas de adivinhar o telefone.
    Route::get('/orders/reference/{reference}/rating', [DriverRatingController::class, 'show'])
        ->middleware('throttle:ratings');
    Route::post('/orders/reference/{reference}/rating', [DriverRatingController::class, 'store'])
        ->middleware('throttle:ratings');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/orders', [GasOrderController::class, 'index']);
        Route::patch('/orders/{gasOrder}/cancel', [GasOrderController::class, 'cancel']);
    });

    // Webhook de SMS — protegido por validação de assinatura no controller,
    // não por Sanctum (o provedor externo não autentica como um utilizador).
    // Uso exclusivo: um POSTO autoatualiza o seu próprio estado enviando um
    // código de 2 dígitos. Não processa nem responde a SMS de clientes —
    // esse atendimento é sempre manual, via Central de Operações
    // (/admin/operations). Ver nota em SmsProcessingService.
    //
    // AUDITORIA DE SEGURANÇA (Setembro 2026): esta rota não tinha
    // NENHUM rate limit — mesmo um pedido sem assinatura válida ainda
    // gasta CPU/DB a validar o HMAC antes de ser rejeitado, tornando-a
    // um alvo barato de flood/DoS. `throttle:sms-webhook` (30/min/IP)
    // fecha isso sem incomodar o tráfego real do provedor.
    Route::post('/webhooks/sms', SmsWebhookController::class)->middleware('throttle:sms-webhook');

    // Painel do operador (secção 94/95) — cada operador só acede ao seu posto.
    Route::prefix('operator')
        ->middleware(['auth:sanctum', 'account.active'])
        ->group(function () {
            Route::get('/station', [OperatorStationController::class, 'show']);
            Route::get('/station/history', [OperatorStationController::class, 'history']);
            Route::get('/station/analytics', [OperatorStationController::class, 'analytics']);
            Route::get('/station/subscription', [OperatorStationController::class, 'subscription']);
            Route::get('/station/export', [OperatorStationController::class, 'export']);
            Route::post('/station/status', [OperatorStationController::class, 'updateStatus']);
            // Garrafas levadas pela KeruGás (Outubro 2026): contador + confirmar/contestar.
            Route::get('/pickups', [OperatorPickupController::class, 'index']);
            Route::post('/pickups/{pickup}/confirm', [OperatorPickupController::class, 'confirm']);
            Route::post('/pickups/{pickup}/dispute', [OperatorPickupController::class, 'dispute']);
            // BUG DE POLÍTICA CORRIGIDO: pedidos de gás foram removidos
            // do âmbito do Operador por decisão explícita do cliente —
            // ver pedidos (mesmo só os do seu município) é estritamente
            // reservado a Admin/Super Admin. Rota e controller
            // removidos, não só escondidos no frontend.
        });

    // Dashboard do Condutor (secção nova, Setembro 2026) — restrito por
    // papel (`role:DRIVER`), não por permissão granular (ver
    // DriverOrderController). Fluxo direto ASSIGNED -> Feita/Cancelado,
    // só sobre os SEUS pedidos (assigned_to === auth()->id()).
    Route::prefix('driver')
        ->middleware(['auth:sanctum', 'account.active', 'role:DRIVER'])
        ->group(function () {
            Route::get('/orders', [DriverOrderController::class, 'index']);
            Route::post('/orders/{order}/complete', [DriverOrderController::class, 'complete']);
            Route::post('/orders/{order}/cancel', [DriverOrderController::class, 'cancel']);
            Route::post('/availability', [DriverOrderController::class, 'toggleAvailability']);
            // Estatísticas/ranking simples do próprio condutor (Setembro 2026).
            Route::get('/stats', [DriverOrderController::class, 'stats']);
            // As avaliações que recebeu, sem identificar quem avaliou (Outubro 2026).
            Route::get('/ratings', [\App\Http\Controllers\Api\V1\Driver\DriverRatingsController::class, 'index'])->middleware('throttle:60,1');
            // Stock e carregamentos (Outubro 2026).
            Route::get('/stock', [DriverStockController::class, 'show']);
            Route::get('/stations', [DriverStockController::class, 'stations']);
            Route::post('/pickups', [DriverStockController::class, 'store'])->middleware('throttle:60,1');
            // Carregamentos registados pela central: o condutor confirma ou contesta (Outubro 2026).
            Route::post('/pickups/{pickup}/confirm', [DriverStockController::class, 'confirmCentral'])->middleware('throttle:30,1');
            Route::post('/pickups/{pickup}/dispute', [DriverStockController::class, 'disputeCentral'])->middleware('throttle:30,1');
            // PIN pessoal para registar por SMS.
            Route::put('/sms-pin', [\App\Http\Controllers\Api\V1\Driver\DriverSmsPinController::class, 'update'])->middleware('throttle:5,1');
        });

    // Chat por município (secção nova, Setembro 2026) — ADMIN, DRIVER e
    // SUPER_ADMIN (via permissão granular chat.view/chat.send — ver
    // RolePermissions). Fora do prefixo /admin porque DRIVER não é uma
    // conta administrativa.
    Route::middleware(['auth:sanctum', 'account.active'])->group(function () {
        Route::get('/chat/messages', [ChatController::class, 'index'])->middleware('permission:chat.view');
        Route::post('/chat/messages', [ChatController::class, 'store'])->middleware('permission:chat.send');
    });

    // Admin (secção 36-45, 183-188). Cada rota exige a permissão granular
    // correspondente, além de sessão ativa (ver EnsurePermission).
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'account.active'])
        ->group(function () {
            Route::get('/dashboard', [AdminDashboardController::class, 'index'])
                ->middleware('permission:analytics.view');
            // Mapa de calor da procura (Outubro 2026).
            Route::get('/analytics/demand', [DemandController::class, 'index'])
                ->middleware('permission:analytics.view');
            Route::get('/analytics', [AdminAnalyticsController::class, 'index'])
                ->middleware('permission:analytics.view');
            // Painel de métricas do Super Admin (Outubro 2026) — por PAPEL, não por
            // permissão: mistura dados financeiros e de toda a plataforma.
            Route::get('/metrics', [SuperAdminMetricsController::class, 'index'])
                ->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN');
            // Faturação por entrega (Setembro 2026) — permissão própria
            // (billing.view), não analytics.view: é dado financeiro.
            Route::get('/billing', [BillingController::class, 'index'])
                ->middleware('permission:billing.view');
            // Relatórios da faturação em Excel/PDF (Outubro 2026).
            Route::get('/billing/export', [BillingController::class, 'export'])
                ->middleware('permission:billing.view');
            // Pago ao posto, dinheiro dos condutores e livro (Outubro 2026).
            Route::post('/billing/stations/{station}/pay', [BillingController::class, 'payStation'])
                ->withTrashed() // postos apagados continuam a ter dívida por pagar
                ->middleware('permission:billing.view');
            Route::post('/billing/drivers/{driver}/receipts', [BillingController::class, 'receiveCash'])
                ->middleware('permission:billing.view');
            Route::get('/billing/ledger', [BillingController::class, 'ledger'])
                ->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN');
            Route::post('/billing/ledger/{auditLog}/undo', [BillingController::class, 'undo'])
                ->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN');
            // Carregamentos nos postos e contadores (Outubro 2026).
            Route::get('/pickups', [PickupAdminController::class, 'index'])->middleware('permission:billing.view');
            Route::get('/pickups/alerts', [PickupAdminController::class, 'alerts'])->middleware('permission:billing.view');
            // Corrigir o valor: só Super Admin Geral e Provincial (o Admin comum só vê).
            Route::patch('/pickups/{pickup}', [PickupAdminController::class, 'correctAmount'])->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN');
            // Registo pela central (Outubro 2026): interruptor por condutor (só Super/Provincial),
            // registar (Admin/Provincial/Super, só da sua província) e confirmar (Super).
            Route::get('/central-pickups/drivers', [CentralPickupController::class, 'drivers'])->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN');
            Route::put('/drivers/{driver}/central-pickup', [CentralPickupController::class, 'setSwitch'])->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN', 'throttle:30,1']);
            Route::put('/drivers/{driver}/sms-pin', [CentralPickupController::class, 'resetSmsPin'])->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN', 'throttle:10,1']);
            Route::get('/drivers/{driver}/sms-pin', [CentralPickupController::class, 'showSmsPin'])->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN', 'throttle:30,1']);
            Route::post('/pickups/central', [CentralPickupController::class, 'store'])->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN', 'throttle:30,1']);
            Route::post('/pickups/sms', [CentralPickupController::class, 'storeFromSms'])->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN', 'throttle:30,1']);
            Route::post('/pickups/{pickup}/confirm', [CentralPickupController::class, 'confirm'])->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN', 'throttle:30,1']);
            Route::post('/stations/{station}/counter-reset', [PickupAdminController::class, 'resetCounter'])->withTrashed()->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN');
            Route::get('/stations/export', [StationExportController::class, 'export'])
                ->middleware('permission:analytics.export');

            Route::get('/stations/groups', [StationAdminController::class, 'groups'])->middleware('permission:stations.view');
            Route::get('/stations', [StationAdminController::class, 'index'])->middleware('permission:stations.view');
            Route::post('/stations', [StationAdminController::class, 'store'])->middleware('permission:stations.create');
            Route::put('/stations/{station}', [StationAdminController::class, 'update'])->middleware('permission:stations.update');
            Route::post('/stations/{station}/status', [StationAdminController::class, 'updateStatus'])->middleware('permission:stations.status');
            Route::post('/stations/{station}/analytics-toggle', [StationAdminController::class, 'toggleAnalytics'])->middleware('permission:stations.update');
            // Aviso manual por SMS ao operador (Outubro 2026) — só Super Admin.
            Route::post('/stations/{station}/subscription-notice', [StationAdminController::class, 'sendSubscriptionNotice'])
                ->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN', 'permission:sms.send', 'throttle:sms-operations']);
            Route::delete('/stations/{station}', [StationAdminController::class, 'destroy'])->middleware('permission:stations.delete');

            Route::get('/users/groups', [UserAdminController::class, 'groups'])->middleware('permission:users.view');
            Route::get('/users', [UserAdminController::class, 'index'])->middleware('permission:users.view');
            // Criação administrativa (Setembro 2026) — usa a permissão
            // `users.update` (não existe `users.create`; o próprio
            // controller já reforça "só Super Admin cria papel
            // privilegiado", que é a regra que realmente importa aqui).
            Route::post('/users', [UserAdminController::class, 'store'])->middleware('permission:users.update');
            Route::put('/users/{user}', [UserAdminController::class, 'update'])->middleware('permission:users.update');
            // Erros do site/app (Outubro 2026) — só o Super Admin geral.
            Route::get('/client-errors', [ClientErrorAdminController::class, 'index'])->middleware('role:SUPER_ADMIN');
            Route::patch('/client-errors/{clientError}/resolve', [ClientErrorAdminController::class, 'resolve'])->middleware('role:SUPER_ADMIN');
            // Cópias de segurança (Outubro 2026) — só o Super Admin geral. Uma de cada vez.
            Route::prefix('backups')->middleware('role:SUPER_ADMIN')->group(function () {
                Route::get('/', [BackupAdminController::class, 'index']);
                Route::post('/', [BackupAdminController::class, 'store'])->middleware('throttle:6,1,backup-start');
                Route::get('/{backupRun}/link', [BackupAdminController::class, 'link'])->middleware('throttle:30,1,backup-link');
                Route::delete('/{backupRun}', [BackupAdminController::class, 'destroy'])->middleware('throttle:30,1,backup-delete');
                // Restaurar uma cópia do histórico (palavra-passe + RESTAURAR).
                Route::post('/{backupRun}/restore', [RestoreAdminController::class, 'fromBackup'])->middleware('throttle:5,1,restore-from-backup');
            });
            // Restaurar a partir de ficheiros enviados (Outubro 2026) — só o Super Admin geral.
            // Cada rota com o SEU contador (3.º parâmetro do throttle): sem prefixo o
            // Laravel soma todos os pedidos do utilizador — as centenas de partes do
            // envio esgotavam o limite e bloqueavam o "Restaurar agora" (429).
            Route::prefix('restores')->middleware('role:SUPER_ADMIN')->where(['restore' => '[a-z0-9]{24}'])->group(function () {
                Route::post('/', [RestoreAdminController::class, 'store'])->middleware('throttle:10,1,restore-open');
                Route::post('/{restore}/chunk', [RestoreAdminController::class, 'chunk'])->middleware('throttle:3000,1,restore-chunk');
                Route::post('/{restore}/verify', [RestoreAdminController::class, 'verify'])->middleware('throttle:10,1,restore-verify');
                Route::post('/{restore}/start', [RestoreAdminController::class, 'start'])->middleware('throttle:5,1,restore-start');
                Route::delete('/{restore}', [RestoreAdminController::class, 'destroy'])->middleware('throttle:30,1,restore-cancel');
            });
            // Remoção de fotos de perfil impróprias (Outubro 2026).
            Route::delete('/users/{user}/avatar', [UserAvatarModerationController::class, 'destroy'])
                ->middleware(['role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN,ADMIN,MODERATOR', 'throttle:30,1']);

            // Condutores (Setembro 2026): listagem/filtro para o fluxo
            // de atribuição de pedidos — ver DriverAdminController.
            Route::get('/drivers/groups', [DriverAdminController::class, 'groups'])->middleware('permission:drivers.view');
            Route::get('/drivers', [DriverAdminController::class, 'index'])->middleware('permission:drivers.view');
            // Estatísticas/ranking simples de um condutor, visível a
            // Admin (só da sua província) e Super Admin (Setembro 2026).
            Route::get('/drivers/{driver}/stats', [DriverAdminController::class, 'stats'])->middleware('permission:drivers.view');
            // Exportação de entregas por condutor (Setembro 2026) — CSV/PDF.
            Route::get('/drivers/export', [DriverAdminController::class, 'export'])->middleware('permission:drivers.view');

            Route::get('/comments/groups', [CommentModerationController::class, 'groups'])->middleware('permission:comments.moderate');
            Route::get('/comments', [CommentModerationController::class, 'index'])->middleware('permission:comments.moderate');
            Route::patch('/comments/{comment}/status', [CommentModerationController::class, 'updateStatus'])->middleware('permission:comments.moderate');

            Route::get('/reports/groups', [ReportAdminController::class, 'groups'])->middleware('permission:reports.review');
            Route::get('/reports', [ReportAdminController::class, 'index'])->middleware('permission:reports.review');
            Route::patch('/reports/{report}/status', [ReportAdminController::class, 'updateStatus'])->middleware('permission:reports.review');

            // Organização por província/município (Outubro 2026) — antes de /orders/{order}.
            Route::get('/orders/groups', [AdminOrderController::class, 'groups'])->middleware('permission:orders.view_all');
            Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('permission:orders.view_all');
            Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->middleware('permission:orders.view_all');
            Route::post('/orders/{order}/contact', [AdminOrderController::class, 'contact'])->middleware('permission:orders.manage');
            Route::post('/orders/{order}/confirm', [AdminOrderController::class, 'confirm'])->middleware('permission:orders.manage');
            Route::post('/orders/{order}/assign', [AdminOrderController::class, 'assign'])->middleware('permission:orders.assign');
            // Envio a um Condutor do MESMO município do pedido
            // (Setembro 2026) — ver AdminOrderController::assignToDriver.
            Route::post('/orders/{order}/assign-driver', [AdminOrderController::class, 'assignToDriver'])->middleware('permission:orders.assign');
            Route::post('/orders/{order}/delivery', [AdminOrderController::class, 'delivery'])->middleware('permission:orders.manage');
            Route::post('/orders/{order}/complete', [AdminOrderController::class, 'complete'])->middleware('permission:orders.manage');
            Route::post('/orders/{order}/cancel', [AdminOrderController::class, 'cancel'])->middleware('permission:orders.manage');
            // Faturação por entrega (Setembro 2026): correção de preço já
            // registado — a verificação de que é estritamente SUPER_ADMIN
            // (não só `orders.manage`, que o Admin comum também tem) vive
            // dentro do próprio controller (ver `updatePrice()`).
            Route::patch('/orders/{order}/price', [AdminOrderController::class, 'updatePrice'])->middleware('permission:orders.manage');
            // Completar pedidos antigos (só total) com posto + divisão (Outubro 2026).
            Route::patch('/orders/{order}/billing', [AdminOrderController::class, 'completeBilling'])->middleware('permission:orders.manage');

            // Central de Operações KeruGás (secções 1-97 da especificação
            // dedicada). Fluxo estritamente manual: o admin lê o SMS no
            // telefone físico da Central e inicia aqui o atendimento —
            // nada abaixo reage a um SMS recebido automaticamente.
            // Acesso restrito a Admin/Super Admin (secção 40).
            Route::prefix('operations')->group(function () {
                Route::post('/', [OperationController::class, 'store'])->middleware('permission:operations.manage');
                Route::get('/{operation}/stations', [OperationController::class, 'stations'])->middleware('permission:operations.manage');
                Route::post('/{operation}/preview', [OperationController::class, 'preview'])
                    ->middleware(['permission:sms.send', 'throttle:sms-operations']);
                Route::post('/{operation}/send', [OperationController::class, 'send'])
                    ->middleware(['permission:sms.send', 'throttle:sms-operations']);
                Route::post('/{operation}/cancel', [OperationController::class, 'cancel'])->middleware('permission:operations.manage');
                // Consultas grátis gastas / subscrição do cliente (Outubro 2026).
                Route::get('/{operation}/subscription', [OperationController::class, 'subscription'])->middleware('permission:operations.manage');
            });

            // Subscrições mensais (Outubro 2026) — ativação manual pelo Super Admin
            // geral ou pelo Provincial (só postos da sua província). Preço, IBAN e
            // contacto (PUT /settings) são só do Super Admin geral.
            Route::prefix('subscriptions')->middleware('role:SUPER_ADMIN,PROVINCE_SUPER_ADMIN')->group(function () {
                Route::get('/', [SubscriptionController::class, 'index']);
                Route::post('/', [SubscriptionController::class, 'store']);
                Route::get('/lookup', [SubscriptionController::class, 'lookup']);
                Route::get('/settings', [SubscriptionController::class, 'settings']);
                Route::put('/settings', [SubscriptionController::class, 'updateSettings'])->middleware('role:SUPER_ADMIN');
                Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
            });

            Route::get('/sms-templates', [SmsTemplateController::class, 'index'])->middleware('permission:sms.view');
            Route::post('/sms-templates', [SmsTemplateController::class, 'store'])->middleware('permission:sms.templates.manage');
            Route::put('/sms-templates/{smsTemplate}', [SmsTemplateController::class, 'update'])->middleware('permission:sms.templates.manage');
            Route::delete('/sms-templates/{smsTemplate}', [SmsTemplateController::class, 'destroy'])->middleware('permission:sms.templates.manage');

            Route::get('/sms/history', [SmsDispatchController::class, 'index'])->middleware('permission:sms.view');
            Route::get('/sms/stats', [SmsDispatchController::class, 'stats'])->middleware('permission:sms.view');
            // Saldo de créditos do provedor de SMS (Setembro 2026) —
            // `permission:sms.view` deixa entrar Admin também, mas o
            // controller reforça "estritamente SUPER_ADMIN" por dentro
            // (dado financeiro da conta junto do fornecedor, mesmo
            // padrão de AdminOrderController::updatePrice()).
            Route::get('/sms/credits', [SmsDispatchController::class, 'credits'])->middleware('permission:sms.view');
            // Uso de SMS por Admin (Setembro 2026) — monitorização/
            // auditoria de custos. `permission:sms.view` deixa entrar
            // Admin, mas o controller reforça "estritamente SUPER_ADMIN"
            // por dentro, mesmo padrão de `credits()` acima.
            Route::get('/sms/usage-by-admin', [SmsDispatchController::class, 'usageByAdmin'])->middleware('permission:sms.view');

            // Mensagens de contacto do site (Setembro 2026).
            // Campanhas e envios manuais de notificações — só SUPER_ADMIN (Setembro 2026).
            Route::prefix('notifications-center')->middleware('permission:notifications.manage')->group(function () {
                Route::get('/campaigns', [NotificationCenterController::class, 'campaigns']);
                Route::patch('/campaigns/{key}', [NotificationCenterController::class, 'updateCampaign']);
                Route::post('/campaigns/{key}/test', [NotificationCenterController::class, 'testCampaign'])->middleware('throttle:sms-operations');
                Route::post('/campaigns/{key}/run', [NotificationCenterController::class, 'runCampaign'])->middleware('throttle:sms-operations');
                Route::get('/broadcasts', [NotificationCenterController::class, 'broadcasts']);
                Route::post('/broadcasts/preview', [NotificationCenterController::class, 'previewBroadcast']);
                Route::post('/broadcasts', [NotificationCenterController::class, 'storeBroadcast'])->middleware('throttle:sms-operations');
                Route::post('/broadcasts/{broadcast}/cancel', [NotificationCenterController::class, 'cancelBroadcast']);
            });

            Route::get('/contact-messages', [ContactMessageController::class, 'index'])->middleware('permission:contact.view');
            Route::patch('/contact-messages/{contactMessage}/read', [ContactMessageController::class, 'markRead'])->middleware('permission:contact.view');
        });

    // Health checks
    Route::get('/health', fn () => response()->json(['success' => true, 'status' => 'ok']));
});
