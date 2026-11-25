import type { ReactNode } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'

import { canEnterAdminPanel } from '@/lib/roles'

/**
 * Restringe o acesso ao painel admin a ADMIN/SUPER_ADMIN/MODERATOR.
 * Reutiliza a revalidação de sessão do useAuth,nunca confia
 * apenas num papel guardado localmente sem confirmar no backend.
 *
 * BUG CORRIGIDO: o MODERATOR já tinha permissão no backend
 * (comments.moderate, reports.review) mas era sempre bloqueado aqui,
 * tornando essa permissão inacessível na prática. Agora entra, mas o
 * AdminLayout esconde as secções que não lhe dizem respeito (Dashboard,
 * Analytics, Postos, Utilizadores, Pedidos),a função do Moderador
 * neste sistema é só: moderar comentários e rever denúncias da
 * comunidade, nada de gestão operacional ou administrativa.
 */
export function AdminGuard({ children }: { children: ReactNode }) {
  const { user, isAuthenticated, isLoading } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return <div className="mx-auto max-w-md px-4 py-16 text-center text-gray">A verificar permissões...</div>
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  // Condutor (Setembro 2026): tem o seu próprio painel dedicado
  // (/driver), fora do painel administrativo,nunca deve cair aqui.
  if (user?.role === 'DRIVER') {
    return <Navigate to="/driver" replace />
  }

  if (!user || !canEnterAdminPanel(user)) {
    return <Navigate to="/dashboard" replace />
  }

  // O Moderador não tem permissão para o dashboard/analytics (exige
  // analytics.view),mandá-lo direto para a secção que pode usar.
  if (user.role === 'MODERATOR' && location.pathname === '/admin') {
    return <Navigate to="/admin/comments" replace />
  }

  return <>{children}</>
}
