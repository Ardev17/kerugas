<?php

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    // Super Admin Provincial (Outubro 2026): os poderes do Super Admin, mas
    // SÓ na província ligada à conta (`users.province_id`, obrigatória).
    // Pode haver vários por província. Nunca mexe em configurações gerais
    // (preços/IBAN das subscrições, templates de SMS, campanhas automáticas,
    // mensagens do site) nem cria/edita contas de Super Admin (geral ou
    // provincial),isso fica só com o Super Admin geral.
    case PROVINCE_SUPER_ADMIN = 'PROVINCE_SUPER_ADMIN';
    case ADMIN = 'ADMIN';
    case MODERATOR = 'MODERATOR';
    case STATION_OPERATOR = 'STATION_OPERATOR';
    // Admin de Posto / gerente (Novembro 2026): ligado a UM posto
    // (users.managed_station_id); um posto pode ter vários. Gere as
    // informações do posto, os seus operadores e os seus condutores.
    // Criado só pelo Super Admin geral ou pelo Provincial (na província).
    case STATION_MANAGER = 'STATION_MANAGER';
    // Condutor (Setembro 2026): entrega pedidos já confirmados/atribuídos
    // pelo Admin/Super Admin. Ligado a uma província + município fixos
    // (users.province_id/municipality_id, reaproveitados),só recebe
    // pedidos do SEU município. Contas de Condutor só podem ser criadas
    // ou geridas pelo SUPER_ADMIN (decisão explícita do cliente, mesmo
    // sendo uma exceção à regra geral de que um Admin gere utilizadores
    // da sua própria província,ver UserAdminController::PRIVILEGED_ROLES).
    case DRIVER = 'DRIVER';
    // Condutor de Posto (Novembro 2026): pertence a UM posto
    // (users.driver_station_id) e só entrega pedidos aceites por esse
    // posto, no município do posto. Não faz carregamentos nem tem
    // faturação diária (isso é só do condutor KeruGás). Nunca se
    // converte em condutor KeruGás nem o contrário.
    case STATION_DRIVER = 'STATION_DRIVER';
    // Investidor (Outubro 2026): vê SÓ a faturação dos municípios a que o
    // Super Admin geral o associou (ver MunicipalityInvestment). Não entra
    // no painel de gestão e não tem nenhuma permissão granular.
    case INVESTOR = 'INVESTOR';
    case USER = 'USER';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super Administrador',
            self::PROVINCE_SUPER_ADMIN => 'Super Admin Provincial',
            self::ADMIN => 'Administrador',
            self::MODERATOR => 'Moderador',
            self::STATION_OPERATOR => 'Operador de Posto',
            self::STATION_MANAGER => 'Admin de Posto',
            self::DRIVER => 'Condutor',
            self::STATION_DRIVER => 'Condutor de Posto',
            self::INVESTOR => 'Investidor',
            self::USER => 'Utilizador',
        };
    }

    /** Papéis com poderes de Super Admin (geral ou provincial). */
    public static function superRoles(): array
    {
        return [self::SUPER_ADMIN, self::PROVINCE_SUPER_ADMIN];
    }

    /** Pessoal ligado a um posto (operador, gerente, condutor de posto). */
    public static function stationStaffRoles(): array
    {
        return [self::STATION_OPERATOR, self::STATION_MANAGER, self::STATION_DRIVER];
    }

    /** Papéis que entram no painel de gestão de pedidos/faturação. */
    public static function managementRoles(): array
    {
        return [self::SUPER_ADMIN, self::PROVINCE_SUPER_ADMIN, self::ADMIN];
    }
}
