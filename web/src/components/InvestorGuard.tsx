import type { ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useAuth } from '@/hooks/useAuth'

/** Painel do investidor: só contas role === 'INVESTOR' (o servidor volta a confirmar tudo). */
export function InvestorGuard({ children }: { children: ReactNode }) {
  const { user, isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return <div className="mx-auto max-w-md px-4 py-16 text-center text-gray">A verificar permissões...</div>
  }
  if (!isAuthenticated) {
    return <Navigate to="/login" replace />
  }
  if (user?.role !== 'INVESTOR') {
    return <Navigate to="/dashboard" replace />
  }

  return <>{children}</>
}
