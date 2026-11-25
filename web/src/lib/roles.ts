import type { AuthUser } from '@/types'

/**
 * Papéis e poderes (Outubro 2026). O Super Admin Provincial tem os poderes
 * do Super Admin, mas só na província ligada à conta,o servidor é quem
 * garante o âmbito; aqui só se decide o que mostrar.
 */
type Who = Pick<AuthUser, 'role'> | null | undefined

export const ROLE_LABEL: Record<AuthUser['role'], string> = {
  SUPER_ADMIN: 'Super Admin',
  PROVINCE_SUPER_ADMIN: 'Super Admin Provincial',
  ADMIN: 'Admin',
  MODERATOR: 'Moderador',
  STATION_OPERATOR: 'Operador de posto',
  STATION_MANAGER: 'Admin de posto',
  DRIVER: 'Condutor',
  STATION_DRIVER: 'Condutor de posto',
  INVESTOR: 'Investidor',
  USER: 'Utilizador',
}

/** Super Admin geral (todo o país e configurações gerais). */
export const isGlobalSuperAdmin = (u: Who) => u?.role === 'SUPER_ADMIN'
export const isProvinceSuperAdmin = (u: Who) => u?.role === 'PROVINCE_SUPER_ADMIN'
/** Poderes de Super Admin,geral, ou provincial na sua província. */
export const hasSuperPowers = (u: Who) => isGlobalSuperAdmin(u) || isProvinceSuperAdmin(u)
/** Limitado à sua província (Admin e Super Admin Provincial). */
export const isProvinceScoped = (u: Who) => u?.role === 'ADMIN' || isProvinceSuperAdmin(u)
/** Gere pedidos, faturação, postos... */
export const isManagement = (u: Who) => u?.role === 'ADMIN' || hasSuperPowers(u)
/** Entra no painel de administração. */
export const canEnterAdminPanel = (u: Who) => isManagement(u) || u?.role === 'MODERATOR'

/**
 * Remoção de fotos de perfil (Outubro 2026),espelho da regra do servidor
 * (UserAvatarModerationController), que é quem decide: só sobre contas com
 * MENOS poderes, e Admin/Provincial só na sua província.
 */
const AVATAR_RANK: Partial<Record<AuthUser['role'], number>> = { SUPER_ADMIN: 3, PROVINCE_SUPER_ADMIN: 2, ADMIN: 1, MODERATOR: 1 }
export function canRemoveAvatarOf(actor: Pick<AuthUser, 'role' | 'province_id'> | null | undefined, target: { role?: string | null; province_id?: number | null }): boolean {
  if (!actor) return false
  if (isGlobalSuperAdmin(actor)) return true
  const mine = AVATAR_RANK[actor.role] ?? 0
  const theirs = AVATAR_RANK[(target.role ?? 'USER') as AuthUser['role']] ?? 0
  if (mine === 0 || mine <= theirs) return false
  // Comentários não trazem a província do autor: aí decide o servidor.
  if (isProvinceScoped(actor) && target.province_id !== undefined) return target.province_id != null && target.province_id === actor.province_id
  return true
}

/** Admin de Posto / gerente (Novembro 2026). */
export const isStationManager = (u: Who) => u?.role === 'STATION_MANAGER'
/** Painel do estado do gás do posto: operador e gerente. */
export const canUseOperatorPanel = (u: Who) => u?.role === 'STATION_OPERATOR' || isStationManager(u)
