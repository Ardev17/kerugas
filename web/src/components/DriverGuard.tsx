import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'

/** Restringe o painel do Condutor a contas role === 'DRIVER'. */
export function DriverGuard({ children }: { children: ReactNode }) {
  const { user, isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return <div className="mx-auto max-w-md px-4 py-16 text-center text-gray">A verificar permissões...</div>
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  // Condutor de Posto (Novembro 2026): o mesmo painel de entregas, sem stock.
  if (user?.role !== 'DRIVER' && user?.role !== 'STATION_DRIVER') {
    return <Navigate to="/dashboard" replace />
  }

  return <>{children}</>
}
