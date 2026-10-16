<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case ADMIN = 'ADMIN';
    case MODERATOR = 'MODERATOR';
    case STATION_OPERATOR = 'STATION_OPERATOR';
    // Condutor (Setembro 2026): entrega pedidos já confirmados/atribuídos
    // pelo Admin/Super Admin. Ligado a uma província + município fixos
    // (users.province_id/municipality_id, reaproveitados) — só recebe
    // pedidos do SEU município. Contas de Condutor só podem ser criadas
    // ou geridas pelo SUPER_ADMIN (decisão explícita do cliente, mesmo
    // sendo uma exceção à regra geral de que um Admin gere utilizadores
    // da sua própria província — ver UserAdminController::PRIVILEGED_ROLES).
    case DRIVER = 'DRIVER';
    case USER = 'USER';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Administrador',
            self::ADMIN => 'Administrador',
            self::MODERATOR => 'Moderador',
            self::STATION_OPERATOR => 'Operador de Posto',
            self::DRIVER => 'Condutor',
            self::USER => 'Utilizador',
        };
    }
}
