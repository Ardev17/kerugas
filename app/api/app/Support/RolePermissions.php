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
        UserRole::ADMIN->value => [
            Permission::STATIONS_VIEW, Permission::STATIONS_CREATE,
            Permission::STATIONS_UPDATE, Permission::STATIONS_DELETE,
            Permission::USERS_VIEW, Permission::USERS_UPDATE, Permission::USERS_BLOCK,
            Permission::COMMENTS_MODERATE, Permission::REPORTS_REVIEW,
            Permission::SMS_VIEW, Permission::ANALYTICS_VIEW, Permission::ANALYTICS_EXPORT, Permission::SETTINGS_MANAGE,
            Permission::ORDERS_VIEW_ALL, Permission::ORDERS_ASSIGN, Permission::ORDERS_MANAGE,
            Permission::BILLING_VIEW,
            // Central de Operações (SMS manual): estritamente Admin e
            // Super Admin (secção 40 da especificação) — nenhum outro
            // papel deve conseguir iniciar atendimentos, gerir templates
            // ou enviar SMS através deste módulo.
            Permission::SMS_SEND, Permission::SMS_TEMPLATES_MANAGE, Permission::OPERATIONS_MANAGE,
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
