<?php

namespace App\Enums;

/**
 * Catálogo de permissões granulares (secção 19 do master prompt).
 * Usadas pelo middleware EnsurePermission e pelo RolePermissions.
 */
enum Permission: string
{
    case STATIONS_VIEW = 'stations.view';
    case STATIONS_CREATE = 'stations.create';
    case STATIONS_UPDATE = 'stations.update';
    case STATIONS_DELETE = 'stations.delete';
    // Mudar só o ESTADO operacional (gás, fila, garrafas) — o Admin comum tem isto
    // mas não create/update/delete (Outubro 2026, decisão do cliente).
    case STATIONS_STATUS = 'stations.status';

    case USERS_VIEW = 'users.view';
    case USERS_UPDATE = 'users.update';
    case USERS_BLOCK = 'users.block';

    case COMMENTS_MODERATE = 'comments.moderate';
    case REPORTS_REVIEW = 'reports.review';

    case SMS_VIEW = 'sms.view';
    case SMS_SEND = 'sms.send';
    case SMS_TEMPLATES_MANAGE = 'sms.templates.manage';
    case OPERATIONS_MANAGE = 'operations.manage';
    case ANALYTICS_VIEW = 'analytics.view';
    case ANALYTICS_EXPORT = 'analytics.export';
    case SETTINGS_MANAGE = 'settings.manage';

    case ORDERS_VIEW_ALL = 'orders.view_all';
    case ORDERS_ASSIGN = 'orders.assign';
    case ORDERS_MANAGE = 'orders.manage';

    // Faturação por entrega (Setembro 2026) — dado financeiro, por isso
    // vive numa permissão própria em vez de reaproveitar ANALYTICS_VIEW,
    // mesmo sendo hoje concedida às mesmas contas (ADMIN/SUPER_ADMIN).
    case BILLING_VIEW = 'billing.view';

    // Condutores + chat por município (Setembro 2026). DRIVERS_VIEW
    // (ver/filtrar condutores por município para lhes enviar um pedido)
    // é dada a ADMIN/SUPER_ADMIN — nunca DRIVERS_MANAGE (criar/editar
    // contas de condutor), que fica reservada ao SUPER_ADMIN via a
    // mesma verificação de papel privilegiado de UserAdminController,
    // não uma permissão à parte.
    case DRIVERS_VIEW = 'drivers.view';
    case CHAT_VIEW = 'chat.view';
    case CHAT_SEND = 'chat.send';

    // Mensagens de contacto do site (Setembro 2026).
    case CONTACT_VIEW = 'contact.view';
    // Campanhas e envios manuais de notificações (Setembro 2026) — só o
    // SUPER_ADMIN (via '*' em RolePermissions); nenhum outro papel a tem.
    case NOTIFICATIONS_MANAGE = 'notifications.manage';
}
