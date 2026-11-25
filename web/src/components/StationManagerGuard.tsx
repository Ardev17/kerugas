import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'

/** Painel do Admin de Posto / gerente (Novembro 2026). */
export function StationManagerGuard({ children }: { children: ReactNode }) {
  const { user, isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return <div className="mx-auto max-w-md px-4 py-16 text-center text-gray">A verificar permissões...</div>
  }

  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }

  if (user?.role !== 'STATION_MANAGER') {
    return <Navigate to="/dashboard" replace />
  }

  return <>{children}</>
}
