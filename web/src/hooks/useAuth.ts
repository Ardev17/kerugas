import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import axios from 'axios'
import { authService } from '@/services/authService'
import { useAuthStore } from '@/stores/authStore'

/**
 * Revalida a sessão a partir do token guardado, sempre que a app carrega.
 * Nunca confia no `user` em cache sem confirmar contra o backend —
 * evita mostrar um estado autenticado "fantasma" se o token expirou
 * ou a conta foi suspensa entretanto.
 */
export function useAuth() {
  const { user, token, status, setSession, clearSession, hydrate } = useAuthStore()

  useEffect(() => {
    hydrate()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const meQuery = useQuery({
    queryKey: ['me', token],
    queryFn: authService.me,
    enabled: !!token && status !== 'authenticated',
    retry: false,
  })

  useEffect(() => {
    // BUG CORRIGIDO (Outubro 2026): a resposta de /users/me fica em cache no
    // React Query. Cada componente que monta useAuth() (ex.: voltar ao
    // "Meu perfil") reaplicava essa resposta ANTIGA e apagava o que a
    // sessão já tinha atualizado (foto de perfil, nome editado...). Só se
    // aplica enquanto a sessão está por confirmar.
    if (meQuery.data && token && useAuthStore.getState().status !== 'authenticated') {
      setSession(meQuery.data, token)
    }
    // BUG CORRIGIDO (Setembro 2026, revisão do app Android): antes,
    // QUALQUER erro aqui terminava a sessão,incluindo "sem internet" ou
    // o servidor em baixo. Um condutor que abrisse o app sem sinal ia
    // parar ao ecrã de login e perdia a sessão. Só uma resposta real de
    // "sessão inválida" (401/403: token expirado, revogado, conta
    // suspensa) termina a sessão; um erro de rede deixa-a por confirmar
    // e o React Query volta a tentar quando a ligação regressar.
    if (meQuery.isError && isSessionRejected(meQuery.error)) {
      clearSession()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [meQuery.data, meQuery.isError])

  return {
    user,
    isAuthenticated: status === 'authenticated',
    isLoading: status === 'idle' && !!token,
  }
}

function isSessionRejected(error: unknown): boolean {
  if (!axios.isAxiosError(error)) return false
  const status = error.response?.status
  return status === 401 || status === 403
}
