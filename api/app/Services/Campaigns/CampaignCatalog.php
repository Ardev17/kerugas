<?php

namespace App\Services\Campaigns;

use App\Enums\NotificationCategory;
use App\Enums\UserRole;
use App\Services\Push\PushMessage;

/**
 * Catálogo das campanhas de notificações do KeruGás (Setembro 2026).
 *
 * Aqui vivem a definição de cada campanha e os textos POR OMISSÃO. O
 * Super Admin pode ligar/desligar, ativar SMS e reescrever os textos no
 * painel "Notificações" — essas afinações ficam em
 * `notification_campaigns` e sobrepõem-se a estes valores.
 *
 * Variáveis nos textos: {nome} (primeiro nome), {central} (telefone da
 * central), e as específicas de cada campanha (indicadas em `variables`).
 * Várias variantes por campanha = teste A/B automático: cada pessoa
 * recebe sempre a mesma variante, e o painel mostra qual converte mais.
 *
 * SMS: nunca com links (a KambaSMS recusa) e até 160 carateres depois de
 * convertidos para GSM-7 (sem acentos nem emojis — ver SmsTextNormalizer).
 */
final class CampaignCatalog
{
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_EVENT = 'event';

    /**
     * @return array<string, array{
     *     key: string, name: string, description: string, trigger: string, schedule: string,
     *     roles: array<int, UserRole>, audience: string, category: NotificationCategory,
     *     preference: ?string, marketing: bool, sms_allowed: bool, sms_default: bool,
     *     path: string, channel: string, variables: array<int, string>,
     *     templates: array<int, array{title: string, body: string, sms?: string, occasion?: string}>,
     *     tips?: array<int, string>
     * }>
     */
    public static function all(): array
    {
        $customers = [UserRole::USER];

        return [
            // ------------------------------------------------------------
            // Lembrete de gás — o pedido original do cliente.
            // ------------------------------------------------------------
            'gas_reminder' => [
                'key' => 'gas_reminder',
                'name' => 'O seu gás está a acabar?',
                'description' => 'Enviado quando o gás da pessoa deve estar a acabar: conta a partir da última entrega '
                    . 'e aprende o ritmo de cada casa (média entre pedidos). Quem nunca pediu recebe 15 dias depois do registo.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 09:00',
                'roles' => $customers,
                'audience' => 'Clientes',
                'category' => NotificationCategory::REMINDER,
                'preference' => 'reminders',
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => true,
                'path' => '/pedir-gas?repetir=1',
                'channel' => PushMessage::CHANNEL_REMINDERS,
                'variables' => ['nome', 'dias', 'central'],
                'templates' => [
                    [
                        'title' => '🔥 Olá, {nome}! O seu gás já deu sinal?',
                        'body' => 'Não precisa de sair de casa nem enfrentar filas. Peça agora e levamos a botija até à sua porta! ☺️',
                        'sms' => 'KeruGas: Ola {nome}! O seu gas esta a acabar? Nao saia de casa: peca na app, no site ou ligue {central}. Levamos ate a sua porta!',
                    ],
                    [
                        'title' => '{nome}, a sua botija está a pedir reforço? 😄',
                        'body' => 'Um toque e o gás vai ter consigo. Sem filas, sem sair de casa.',
                        'sms' => 'KeruGas: {nome}, a sua botija esta a pedir reforco? Peca na app ou ligue {central} e levamos o gas a sua casa!',
                    ],
                    [
                        'title' => 'Faz {dias} dias desde o último gás, {nome}',
                        'body' => 'Antes que o jantar fique a meio, peça já. Nós levamos até ao conforto da sua casa! 🍲',
                        'sms' => 'KeruGas: {nome}, faz {dias} dias desde o ultimo gas. Peca ja na app ou ligue {central}. Entregamos em sua casa!',
                    ],
                ],
            ],

            'gas_reminder_followup' => [
                'key' => 'gas_reminder_followup',
                'name' => 'Segundo lembrete (suave)',
                'description' => 'Uma única vez, 3 dias depois do lembrete de gás, se a pessoa ainda não fez pedido. Depois disso o sistema cala-se até ao próximo ciclo.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 09:30',
                'roles' => $customers,
                'audience' => 'Clientes',
                'category' => NotificationCategory::REMINDER,
                'preference' => 'reminders',
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/pedir-gas?repetir=1',
                'channel' => PushMessage::CHANNEL_REMINDERS,
                'variables' => ['nome', 'central'],
                'templates' => [
                    [
                        'title' => 'Ainda com gás, {nome}? 😊',
                        'body' => 'Se precisar, estamos a um toque de distância. Pedimos, levamos, e pronto!',
                        'sms' => 'KeruGas: {nome}, se precisar de gas estamos aqui. Peca na app ou ligue {central}.',
                    ],
                ],
            ],

            'gas_back_nearby' => [
                'key' => 'gas_back_nearby',
                'name' => 'Há gás perto de si',
                'description' => 'Quando um posto do município da pessoa volta a ter gás depois de ter estado sem. No máximo uma vez a cada 12 horas por pessoa; nunca durante as horas de silêncio.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando um posto volta a ter gás',
                'roles' => $customers,
                'audience' => 'Clientes do município do posto',
                'category' => NotificationCategory::AVAILABILITY,
                'preference' => 'alerts',
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/postos/{station_id}',
                'channel' => PushMessage::CHANNEL_ALERTS,
                'variables' => ['nome', 'posto', 'municipio'],
                'templates' => [
                    [
                        'title' => 'Boa notícia! Já há gás no {posto} ⛽',
                        'body' => 'O {posto}, em {municipio}, voltou a ter gás. Veja a fila antes de sair!',
                        'sms' => 'KeruGas: Ja ha gas no {posto} ({municipio}). Veja a fila na app antes de sair.',
                    ],
                ],
            ],

            'short_queue' => [
                'key' => 'short_queue',
                'name' => 'Fila curta agora',
                'description' => 'Para quem tem o posto nos favoritos, quando a fila passa a curta e há gás. No máximo uma vez por posto por dia.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando a fila de um posto favorito fica curta',
                'roles' => $customers,
                'audience' => 'Clientes com o posto nos favoritos',
                'category' => NotificationCategory::QUEUE,
                'preference' => 'alerts',
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/postos/{station_id}',
                'channel' => PushMessage::CHANNEL_ALERTS,
                'variables' => ['nome', 'posto'],
                'templates' => [
                    ['title' => 'Fila curta no {posto} agora mesmo! 🏃', 'body' => 'Boa altura para ir, {nome}. Há gás e quase ninguém à espera.'],
                ],
            ],

            'weekend_eve' => [
                'key' => 'weekend_eve',
                'name' => 'Véspera de fim de semana',
                'description' => 'Sexta-feira à tarde, para garantir o gás antes das filas de sábado.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Sextas-feiras às 16:00',
                'roles' => $customers,
                'audience' => 'Clientes',
                'category' => NotificationCategory::PROMO,
                'preference' => 'promos',
                'marketing' => true,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/pedir-gas',
                'channel' => PushMessage::CHANNEL_PROMOS,
                'variables' => ['nome'],
                'templates' => [
                    ['title' => 'O fim de semana está à porta! 🎉', 'body' => 'Garanta o gás hoje, {nome}, e evite as filas de sábado. Nós levamos até si.', 'sms' => 'KeruGas: O fim de semana esta a porta! Garanta o gas hoje e evite as filas de sabado. Peca na app.'],
                    ['title' => 'Sexta-feira, {nome}! O churrasco está garantido? 🍗', 'body' => 'Não deixe o gás acabar a meio da festa. Peça hoje e relaxe no fim de semana.'],
                ],
            ],

            'safety_tip' => [
                'key' => 'safety_tip',
                'name' => 'Dica de segurança do mês',
                'description' => 'Uma dica por mês, sempre diferente (roda pela lista). Mostra que o KeruGás cuida das famílias, não só vende gás.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Dia 1 de cada mês às 10:00',
                'roles' => $customers,
                'audience' => 'Clientes',
                'category' => NotificationCategory::TIP,
                'preference' => 'tips',
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/como-funciona',
                'channel' => PushMessage::CHANNEL_TIPS,
                'variables' => ['nome', 'dica'],
                'templates' => [
                    ['title' => '🛡️ Dica de segurança KeruGás', 'body' => '{dica}'],
                ],
                'tips' => [
                    'Cheira a gás em casa? Não acenda luzes nem interruptores. Abra as janelas e feche a válvula da botija.',
                    'A mangueira do gás tem prazo! Se estiver rachada ou dura, está na hora de a trocar.',
                    'Botija sempre de pé e num lugar arejado — nunca dentro de armários fechados.',
                    'Teste de fugas: passe água com sabão nas ligações. Se fizer bolhas, feche a botija e peça ajuda.',
                    'Ao sair de casa por muito tempo, feche a válvula da botija. É um gesto de segundos que evita acidentes.',
                    'Crianças longe do fogão e da botija. O gás é um aliado na cozinha, não um brinquedo.',
                ],
            ],

            'welcome' => [
                'key' => 'welcome',
                'name' => 'Boas-vindas',
                'description' => 'Logo após o registo.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando a pessoa cria conta',
                'roles' => $customers,
                'audience' => 'Novos clientes',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome'],
                'templates' => [
                    ['title' => 'Bem-vindo ao KeruGás, {nome}! 👋', 'body' => 'Encontre gás antes de sair de casa, veja as filas e peça entrega com um toque. Estamos consigo!'],
                ],
            ],

            'welcome_alerts' => [
                'key' => 'welcome_alerts',
                'name' => 'Ative os alertas (2 dias depois)',
                'description' => 'Dois dias depois do registo, só para quem ainda não criou nenhum alerta.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 11:00',
                'roles' => $customers,
                'audience' => 'Clientes sem alertas',
                'category' => NotificationCategory::TIP,
                'preference' => 'tips',
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/alerts',
                'channel' => PushMessage::CHANNEL_TIPS,
                'variables' => ['nome'],
                'templates' => [
                    ['title' => 'Sabia que o KeruGás pode avisá-lo? 🔔', 'body' => '{nome}, ative um alerta e receba um aviso assim que o seu posto tiver gás. Chega de voltas em vão!'],
                ],
            ],

            'post_delivery' => [
                'key' => 'post_delivery',
                'name' => 'Como foi a entrega?',
                'description' => 'Uma a três horas depois de um pedido entregue por um condutor, se ainda não foi avaliado.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'De hora a hora (08:00–21:00)',
                'roles' => $customers,
                'audience' => 'Clientes com entrega recente',
                'category' => NotificationCategory::ORDER,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/avaliar/{referencia}',
                'channel' => PushMessage::CHANNEL_ORDERS,
                'variables' => ['nome', 'condutor', 'referencia'],
                'templates' => [
                    ['title' => 'O seu gás chegou bem? ⭐', 'body' => '{nome}, conte-nos como foi a entrega do {condutor}. Leva 10 segundos e ajuda-nos a melhorar.'],
                ],
            ],

            'account_anniversary' => [
                'key' => 'account_anniversary',
                'name' => 'Aniversário de conta',
                'description' => 'No dia em que a conta faz 1, 2, 3... anos.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 10:00',
                'roles' => $customers,
                'audience' => 'Clientes',
                'category' => NotificationCategory::PROMO,
                'preference' => 'promos',
                'marketing' => true,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_PROMOS,
                'variables' => ['nome', 'anos'],
                'templates' => [
                    ['title' => 'Parabéns, {nome}! 🎉', 'body' => 'Faz hoje {anos} que o KeruGás cuida do seu gás. Obrigado pela confiança!', 'sms' => 'KeruGas: Parabens {nome}! Faz hoje {anos} que cuidamos do seu gas. Obrigado pela confianca!'],
                ],
            ],

            'special_dates' => [
                'key' => 'special_dates',
                'name' => 'Datas especiais',
                'description' => 'Independência (11 de Novembro), véspera das festas (20 de Dezembro) e Ano Novo (30 de Dezembro). Cada variante tem a sua data (DD-MM).',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Nas datas indicadas, às 10:00',
                'roles' => $customers,
                'audience' => 'Clientes',
                'category' => NotificationCategory::PROMO,
                'preference' => 'promos',
                'marketing' => true,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/pedir-gas',
                'channel' => PushMessage::CHANNEL_PROMOS,
                'variables' => ['nome'],
                'templates' => [
                    ['occasion' => '11-11', 'title' => 'Feliz Dia da Independência! 🇦🇴', 'body' => '{nome}, celebre com a família sem preocupações — o gás, deixe connosco.', 'sms' => 'KeruGas: Feliz Dia da Independencia! Celebre com a familia sem preocupacoes. O gas, deixe connosco.'],
                    ['occasion' => '20-12', 'title' => 'As festas estão a chegar! 🎄', 'body' => 'Encha a botija antes do Natal e cozinhe sem sustos, {nome}. Peça já e evite as filas.', 'sms' => 'KeruGas: As festas estao a chegar! Encha a botija antes do Natal e evite as filas. Peca na app.'],
                    ['occasion' => '30-12', 'title' => 'Um ano novo cheio de sabor, {nome}! ✨', 'body' => 'Garanta o gás para a passagem de ano. Nós levamos até à sua porta.'],
                ],
            ],

            'we_miss_you' => [
                'key' => 'we_miss_you',
                'name' => 'Sentimos a sua falta',
                'description' => 'Depois de 45 dias sem abrir o KeruGás nem fazer pedidos. No máximo uma vez a cada 90 dias.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 12:00',
                'roles' => $customers,
                'audience' => 'Clientes inativos',
                'category' => NotificationCategory::PROMO,
                'preference' => 'promos',
                'marketing' => true,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_PROMOS,
                'variables' => ['nome'],
                'templates' => [
                    ['title' => 'Sentimos a sua falta, {nome}! 💙', 'body' => 'Veja onde há gás hoje perto de si — e se precisar, levamos até casa.', 'sms' => 'KeruGas: Sentimos a sua falta, {nome}! Veja onde ha gas perto de si na app ou peca entrega ao domicilio.'],
                ],
            ],

            // ------------------------------------------------------------
            // Equipa — melhoram os dados de toda a plataforma.
            // ------------------------------------------------------------
            'operator_stale' => [
                'key' => 'operator_stale',
                'name' => 'Operador: atualize o posto',
                'description' => 'Quando o estado de um posto não é atualizado há mais de 6 horas. No máximo uma vez a cada 6 horas.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'De hora a hora (08:00–20:00)',
                'roles' => [UserRole::STATION_OPERATOR],
                'audience' => 'Operadores de posto',
                'category' => NotificationCategory::REMINDER,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/operator',
                'channel' => PushMessage::CHANNEL_REMINDERS,
                'variables' => ['nome', 'posto', 'horas'],
                'templates' => [
                    ['title' => 'O {posto} ainda tem gás? ⛽', 'body' => 'O estado não é atualizado há {horas} horas. Um toque e os clientes ficam a saber!', 'sms' => 'KeruGas: O estado do {posto} nao e atualizado ha {horas}h. Ainda tem gas? Atualize na app ou por SMS.'],
                ],
            ],

            'driver_morning' => [
                'key' => 'driver_morning',
                'name' => 'Condutor: bom dia',
                'description' => 'De manhã, para os condutores que estão marcados como indisponíveis.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 08:00',
                'roles' => [UserRole::DRIVER],
                'audience' => 'Condutores',
                'category' => NotificationCategory::REMINDER,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/driver',
                'channel' => PushMessage::CHANNEL_REMINDERS,
                'variables' => ['nome'],
                'templates' => [
                    ['title' => 'Bom dia, {nome}! 🚚', 'body' => 'Está disponível para entregas hoje? Ative a disponibilidade e comece a receber pedidos.'],
                ],
            ],

            'admin_daily_summary' => [
                'key' => 'admin_daily_summary',
                'name' => 'Admin: resumo do dia',
                'description' => 'Resumo dos pedidos do dia. Cada Admin vê a sua província; o Super Admin vê tudo.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 18:00',
                'roles' => [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN],
                'audience' => 'Admins e Super Admins',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/admin/orders',
                'channel' => PushMessage::CHANNEL_ORDERS,
                'variables' => ['nome', 'pedidos', 'entregues', 'pendentes'],
                'templates' => [
                    ['title' => '📊 Resumo de hoje', 'body' => '{pedidos} pedidos · {entregues} entregues · {pendentes} por contactar. Bom trabalho, {nome}!'],
                ],
            ],

            // ============================================================
            // Paywall, consultas grátis e presença (Novembro 2026)
            // ============================================================
            'municipality_paywall_soon' => [
                'key' => 'municipality_paywall_soon',
                'name' => 'Município passa a pago (aviso prévio)',
                'description' => 'Três dias antes de a consulta dos postos de um município passar a ser paga. Uma vez por pessoa.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 10:45',
                'roles' => $customers,
                'audience' => 'Clientes do município',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'municipio', 'data', 'central'],
                'templates' => [
                    [
                        'title' => 'A consulta em {municipio} vai passar a paga',
                        'body' => '{nome}, a partir de {data} a consulta dos postos em {municipio} é só para subscritores. Subscreva a tempo para não perder o acesso.',
                        'sms' => 'KeruGas: a consulta de postos em {municipio} passa a paga a partir de {data}. Subscreva: peca o IBAN a {central}.',
                    ],
                ],
            ],

            'municipality_paywall_started' => [
                'key' => 'municipality_paywall_started',
                'name' => 'Município passou a pago',
                'description' => 'No dia em que a consulta dos postos de um município passa a ser paga.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 10:45',
                'roles' => $customers,
                'audience' => 'Clientes do município',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'municipio', 'data', 'central'],
                'templates' => [
                    [
                        'title' => 'Consulta paga em {municipio}',
                        'body' => '{nome}, a consulta dos postos em {municipio} é agora reservada a subscritores. Subscreva para continuar a ver o estado dos postos.',
                        'sms' => 'KeruGas: a consulta de postos em {municipio} e agora paga. Para subscrever peca o IBAN a {central}.',
                    ],
                ],
            ],

            'municipality_free_again' => [
                'key' => 'municipality_free_again',
                'name' => 'Município voltou a ser grátis',
                'description' => 'Quando o administrador volta a abrir a consulta dos postos de um município.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando o município é reaberto',
                'roles' => $customers,
                'audience' => 'Clientes do município',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'municipio'],
                'templates' => [
                    ['title' => 'Boa notícia: consulta grátis em {municipio} 🎉', 'body' => '{nome}, a consulta dos postos em {municipio} voltou a ser grátis. Veja já onde há gás!'],
                ],
            ],

            'free_consults_low' => [
                'key' => 'free_consults_low',
                'name' => 'Consultas grátis a acabar',
                'description' => 'Quando resta uma única consulta grátis por SMS no mês. Uma vez por mês.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 17:00',
                'roles' => $customers,
                'audience' => 'Clientes com poucas consultas grátis',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'restantes', 'limite'],
                'templates' => [
                    ['title' => 'Resta {restantes} consulta grátis este mês', 'body' => '{nome}, já usou quase todas as {limite} consultas grátis do mês. Para consultar sem limite, subscreva.'],
                ],
            ],

            'free_consults_exhausted' => [
                'key' => 'free_consults_exhausted',
                'name' => 'Consultas grátis esgotadas',
                'description' => 'Quando as consultas grátis por SMS do mês acabam. Uma vez por mês.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 17:00',
                'roles' => $customers,
                'audience' => 'Clientes sem consultas grátis',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'limite'],
                'templates' => [
                    ['title' => 'Consultas grátis esgotadas', 'body' => '{nome}, usou as {limite} consultas grátis deste mês. Renovam no dia 1 — ou subscreva e continue já.'],
                ],
            ],

            'free_consults_renewed' => [
                'key' => 'free_consults_renewed',
                'name' => 'Consultas grátis renovadas',
                'description' => 'No dia 1 de cada mês, a quem tinha esgotado as consultas grátis do mês anterior.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Dia 1 de cada mês às 09:30',
                'roles' => $customers,
                'audience' => 'Clientes que esgotaram as consultas',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'limite'],
                'templates' => [
                    ['title' => 'Consultas grátis renovadas ✅', 'body' => '{nome}, as suas {limite} consultas grátis deste mês já estão disponíveis.'],
                ],
            ],

            'access_granted' => [
                'key' => 'access_granted',
                'name' => 'Acesso à consulta concedido',
                'description' => 'Quando um administrador abre o acesso à consulta dos postos a uma pessoa.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando o acesso é concedido',
                'roles' => [UserRole::USER, UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR, UserRole::STATION_DRIVER],
                'audience' => 'Pessoa a quem se abriu o acesso',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'validade'],
                'templates' => [
                    ['title' => 'Acesso à consulta ativado ✅', 'body' => '{nome}, já pode consultar o estado dos postos {validade}.'],
                ],
            ],

            'access_revoked' => [
                'key' => 'access_revoked',
                'name' => 'Acesso à consulta retirado',
                'description' => 'Quando um administrador retira o acesso à consulta dos postos a uma pessoa.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando o acesso é retirado',
                'roles' => [UserRole::USER, UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR, UserRole::STATION_DRIVER],
                'audience' => 'Pessoa a quem se retirou o acesso',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'central'],
                'templates' => [
                    ['title' => 'Acesso à consulta retirado', 'body' => '{nome}, o seu acesso especial à consulta dos postos terminou. Para saber mais, fale com a central: {central}.'],
                ],
            ],

            'sms_subscription_expiring' => [
                'key' => 'sms_subscription_expiring',
                'name' => 'Subscrição de consultas a acabar',
                'description' => 'Cinco dias antes de terminar a subscrição de consultas por SMS, se não foi renovada.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 10:20',
                'roles' => $customers,
                'audience' => 'Clientes subscritores',
                'category' => NotificationCategory::REMINDER,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => true,
                'sms_default' => false,
                'path' => '/',
                'channel' => PushMessage::CHANNEL_REMINDERS,
                'variables' => ['nome', 'validade', 'central'],
                'templates' => [
                    [
                        'title' => 'A sua subscrição termina a {validade}',
                        'body' => '{nome}, renove para continuar a consultar o gás por SMS sem limite.',
                        'sms' => 'KeruGas: a sua subscricao termina a {validade}. Para renovar peca o IBAN a {central}.',
                    ],
                ],
            ],

            'station_weekly_summary' => [
                'key' => 'station_weekly_summary',
                'name' => 'Posto: resumo da semana',
                'description' => 'Todas as segundas, aos gerentes e operadores: pedidos aceites e entregues na semana e, se o posto não recebe pedidos, quantos perdeu.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Segundas-feiras às 08:30',
                'roles' => [UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR],
                'audience' => 'Gerentes e operadores de posto',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '{caminho}',
                'channel' => PushMessage::CHANNEL_ORDERS,
                'variables' => ['nome', 'posto', 'aceites', 'entregues', 'perdidos'],
                'templates' => [
                    ['title' => '📊 A semana do {posto}', 'body' => '{aceites} pedidos aceites · {entregues} entregues · {perdidos} do município sem posto subscrito.'],
                ],
            ],

            'order_unanswered' => [
                'key' => 'order_unanswered',
                'name' => 'Posto: pedido por aceitar',
                'description' => 'Pedidos do município por aceitar há mais de 15 minutos, aos postos que os podem receber. Máximo uma vez a cada 2 horas por pessoa.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'De hora a hora (08:00–20:00)',
                'roles' => [UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR],
                'audience' => 'Gerentes e operadores de postos com pedidos ativos',
                'category' => NotificationCategory::ORDER,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '{caminho}',
                'channel' => PushMessage::CHANNEL_ORDERS,
                'variables' => ['nome', 'posto', 'municipio', 'pedidos'],
                'templates' => [
                    ['title' => '⏰ {pedidos} pedido(s) à espera em {municipio}', 'body' => 'Quem aceitar primeiro fica com o pedido. Abra o painel do {posto} e aceite.'],
                ],
            ],

            'orders_missed' => [
                'key' => 'orders_missed',
                'name' => 'Posto: pedidos perdidos',
                'description' => 'Todas as segundas: aos postos sem subscrição de pedidos, quantos pedidos do município foram para outros postos na semana.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Segundas-feiras às 08:45',
                'roles' => [UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR],
                'audience' => 'Gerentes e operadores de postos sem pedidos',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '{caminho}',
                'channel' => PushMessage::CHANNEL_ORDERS,
                'variables' => ['nome', 'posto', 'perdidos', 'municipio', 'central'],
                'templates' => [
                    ['title' => 'Perdeu {perdidos} pedidos esta semana', 'body' => '{perdidos} pedidos de {municipio} foram para outros postos. Ative a subscrição de pedidos do {posto} para os receber. Contacto: {central}.'],
                ],
            ],

            'listing_invite' => [
                'key' => 'listing_invite',
                'name' => 'Posto fora do mapa: convite',
                'description' => 'Quando a presença paga está ligada, às quartas aos postos que não aparecem na aplicação: quantos postos do município já lá estão.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Quartas-feiras às 10:00',
                'roles' => [UserRole::STATION_MANAGER, UserRole::STATION_OPERATOR],
                'audience' => 'Gerentes e operadores de postos fora do mapa',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '{caminho}',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'posto', 'quantidade', 'municipio', 'central'],
                'templates' => [
                    ['title' => 'O {posto} não aparece na aplicação', 'body' => '{quantidade} postos de {municipio} já estão no mapa. Renove a presença do {posto} para voltar a ser encontrado. Contacto: {central}.'],
                ],
            ],

            'admin_subscriptions_expiring' => [
                'key' => 'admin_subscriptions_expiring',
                'name' => 'Admin: subscrições a acabar',
                'description' => 'Todas as segundas: subscrições de postos que terminam nos próximos 7 dias e ainda não foram renovadas.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Segundas-feiras às 08:15',
                'roles' => [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN],
                'audience' => 'Admins e Super Admins',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/admin/subscriptions',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'total'],
                'templates' => [
                    ['title' => '💳 {total} subscrições a terminar', 'body' => '{total} subscrições de postos terminam esta semana e ainda não foram renovadas.'],
                ],
            ],

            'admin_grace_ending' => [
                'key' => 'admin_grace_ending',
                'name' => 'Admin: postos a sair do mapa',
                'description' => 'Postos em tolerância que saem da aplicação nas próximas 48 horas.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Todos os dias às 09:15',
                'roles' => [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN],
                'audience' => 'Admins e Super Admins',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/admin/subscriptions',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'total', 'postos'],
                'templates' => [
                    ['title' => '🗺️ {total} posto(s) saem do mapa em 48h', 'body' => 'Presença por renovar: {postos}.'],
                ],
            ],

            'admin_paywall_changed' => [
                'key' => 'admin_paywall_changed',
                'name' => 'Admin: bloqueio alterado',
                'description' => 'Quando alguém abre ou fecha a consulta de um município, avisa os outros administradores com alcance sobre ele.',
                'trigger' => self::TRIGGER_EVENT,
                'schedule' => 'Quando o bloqueio de um município muda',
                'roles' => [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN],
                'audience' => 'Outros Admins e Super Admins',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/admin/acesso-consulta',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'quem', 'municipio', 'estado'],
                'templates' => [
                    ['title' => 'Consulta em {municipio}: {estado}', 'body' => '{quem} alterou o acesso à consulta dos postos em {municipio}: {estado}.'],
                ],
            ],

            'admin_municipality_uncovered' => [
                'key' => 'admin_municipality_uncovered',
                'name' => 'Admin: município sem posto a receber',
                'description' => 'Todas as segundas: municípios com pedidos na semana mas sem nenhum posto ativo a poder recebê-los.',
                'trigger' => self::TRIGGER_SCHEDULED,
                'schedule' => 'Segundas-feiras às 09:00',
                'roles' => [UserRole::ADMIN, UserRole::SUPER_ADMIN, UserRole::PROVINCE_SUPER_ADMIN],
                'audience' => 'Admins e Super Admins',
                'category' => NotificationCategory::SYSTEM,
                'preference' => null,
                'marketing' => false,
                'sms_allowed' => false,
                'sms_default' => false,
                'path' => '/admin/stations',
                'channel' => PushMessage::CHANNEL_GENERAL,
                'variables' => ['nome', 'total', 'municipios'],
                'templates' => [
                    ['title' => '📍 {total} município(s) sem posto a receber pedidos', 'body' => 'Tiveram pedidos esta semana: {municipios}.'],
                ],
            ],
        ];
    }

    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
