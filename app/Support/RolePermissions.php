<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\UserRole;

/**
 * Mapa estático de papel -> permissões concedidas.
 *
 * DECISÃO TÉCNICA: em vez de tabelas `roles`/`permissions` totalmente
 * dinâmicas (que a secção 46 sugere como possibilidade), optou-se por um
 * mapa em código para a Fase 1 — mais simples de auditar e testar, sem
 * bloquear o desenvolvimento. Se o produto exigir papéis customizáveis
 * pelo Super Admin no futuro, esta classe pode ser substituída por uma
 * leitura de base de dados sem alterar os pontos de uso (middleware/Gate).
 */
class RolePermissions
{
    private const MAP = [
        UserRole::SUPER_ADMIN->value => '*', // acesso total
        // Super Admin Provincial (Outubro 2026): tudo, MENOS o que é geral da
        // plataforma — templates de SMS, mensagens de contacto do site e
        // definições gerais. O âmbito por província é aplicado nos
        // controllers/serviços (User::scopedProvinceId()).
        UserRole::PROVINCE_SUPER_ADMIN->value => [
            Permission::STATIONS_VIEW, Permission::STATIONS_CREATE,
            Permission::STATIONS_UPDATE, Permission::STATIONS_DELETE, Permission::STATIONS_STATUS,
            Permission::USERS_VIEW, Permission::USERS_UPDATE, Permission::USERS_BLOCK,
            Permission::COMMENTS_MODERATE, Permission::REPORTS_REVIEW,
            Permission::SMS_VIEW, Permission::SMS_SEND, Permission::OPERATIONS_MANAGE,
            Permission::ANALYTICS_VIEW, Permission::ANALYTICS_EXPORT,
            Permission::ORDERS_VIEW_ALL, Permission::ORDERS_ASSIGN, Permission::ORDERS_MANAGE,
            Permission::BILLING_VIEW,
            Permission::DRIVERS_VIEW, Permission::CHAT_VIEW, Permission::CHAT_SEND,
            // Envios de notificações aos utilizadores da SUA província.
            Permission::NOTIFICATIONS_MANAGE,
        ],
        UserRole::ADMIN->value => [
            // Postos (Outubro 2026, decisão do cliente): o Admin comum só VÊ e muda o
            // ESTADO do posto. Criar, editar e apagar é do Super Admin e do Provincial.
            Permission::STATIONS_VIEW, Permission::STATIONS_STATUS,
            Permission::USERS_VIEW, Permission::USERS_UPDATE, Permission::USERS_BLOCK,
            Permission::COMMENTS_MODERATE, Permission::REPORTS_REVIEW,
            Permission::SMS_VIEW, Permission::ANALYTICS_VIEW, Permission::ANALYTICS_EXPORT, Permission::SETTINGS_MANAGE,
            Permission::ORDERS_VIEW_ALL, Permission::ORDERS_ASSIGN, Permission::ORDERS_MANAGE,
            Permission::BILLING_VIEW,
            // Central de Operações (SMS manual): estritamente Admin e
            // Super Admin (secção 40 da especificação) — nenhum outro
            // papel deve conseguir iniciar atendimentos, gerir templates
            // ou enviar SMS através deste módulo.
            // Criar, editar e desativar TEMPLATES é só do Super Admin
            // (Setembro 2026, decisão do cliente): o Admin usa os
            // templates existentes para responder, mas não os altera.
            Permission::SMS_SEND, Permission::OPERATIONS_MANAGE,
            // Condutores + chat por município (Setembro 2026).
            Permission::DRIVERS_VIEW, Permission::CHAT_VIEW, Permission::CHAT_SEND,
        ],
        UserRole::MODERATOR->value => [
            Permission::STATIONS_VIEW,
            Permission::COMMENTS_MODERATE, Permission::REPORTS_REVIEW,
            Permission::ORDERS_VIEW_ALL,
        ],
        UserRole::STATION_OPERATOR->value => [
            Permission::STATIONS_VIEW,
            // Pedidos de gás são estritamente Admin/Super Admin (decisão
            // explícita do cliente) — nem sequer o âmbito escopado por
            // município (que chegou a existir numa rota dedicada,
            // /operator/orders) ficou disponível ao Operador. Sem
            // ORDERS_VIEW_ALL aqui, e sem essa rota, não há caminho
            // nenhum para um Operador ver pedidos.
        ],
        // Condutor: só o chat por município + as suas próprias rotas de
        // entrega (/driver/*, que não passam por este mapa de
        // permissões — ver DriverOrderController, seguindo o mesmo
        // padrão de OperatorStationController: um papel operacional
        // restrito ao seu próprio âmbito não precisa de permissões
        // granulares, só de um `role === DRIVER` na própria rota).
        UserRole::DRIVER->value => [
            Permission::CHAT_VIEW, Permission::CHAT_SEND,
        ],
        // Investidor (Outubro 2026): NENHUMA permissão granular — a faturação
        // dele vem só de /investor/*, sempre limitada às suas associações.
        UserRole::INVESTOR->value => [],
        UserRole::USER->value => [],
    ];

    public static function has(UserRole $role, Permission $permission): bool
    {
        $granted = self::MAP[$role->value] ?? [];

        if ($granted === '*') {
            return true;
        }

        return in_array($permission, $granted, true);
    }
}
