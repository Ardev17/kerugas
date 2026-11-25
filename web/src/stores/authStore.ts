import { create } from 'zustand'
import type { AuthUser } from '@/types'
import { disconnectEcho } from '@/lib/echoConnection'
import { clearToken, getToken, setToken } from '@/lib/tokenStorage'
import { clearLocalSessionData, SESSION_CLEARED_EVENT } from '@/lib/sessionCleanup'

interface AuthState {
  user: AuthUser | null
  token: string | null
  status: 'idle' | 'authenticated' | 'guest'
  setSession: (user: AuthUser, token: string) => void
  clearSession: () => void
  hydrate: () => void
}

// Outubro 2026: no app Android o token vive no armazenamento seguro do
// sistema (ver lib/tokenStorage.ts); no site, no localStorage como antes.
function readStoredToken(): string | null {
  return getToken()
}

/**
 * BUG CORRIGIDO (Setembro 2026, encontrado ao testar o app Android):
 * o store começava com `token: null` e só lia o token guardado num
 * useEffect, DEPOIS do primeiro render. Nesse primeiro render, o
 * <AuthGuard> via "sem token", concluía "visitante" e redirecionava
 * para /login,qualquer página protegida aberta diretamente ou
 * recarregada (F5 em /favorites, ou um toque numa notificação push com o
 * app fechado) mandava para o login quem já tinha sessão. Agora o token
 * é lido de imediato, na criação do store: o primeiro render já sabe que
 * há uma sessão por confirmar ('idle') e o <AuthGuard> espera por ela.
 */
const initialToken = readStoredToken()

/**
 * Store global de sessão. O token é o único dado persistido em
 * localStorage,o objeto `user` é sempre revalidado via /users/me
 * ao carregar a aplicação (hydrate), nunca confiado como cache indefinido.
 */
export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  token: initialToken,
  status: initialToken ? 'idle' : 'guest',

  setSession: (user, token) => {
    const previous = get().user
    setToken(token)
    set({ user, token, status: 'authenticated' })
    // Outra conta entrou sem a anterior ter saído: a cache não pode passar
    // de uma para a outra.
    if (previous && previous.id !== user.id) window.dispatchEvent(new Event(SESSION_CLEARED_EVENT))
  },

  clearSession: () => {
    clearToken()
    // Outubro 2026: cache de pedidos e dados locais da conta saem também.
    clearLocalSessionData()
    // Fecha a ligação de tempo real da sessão anterior (Setembro 2026):
    // sem isto, num telemóvel partilhado, os canais privados da conta
    // anterior (ex: pedidos do Admin) continuavam subscritos até o app
    // ser fechado.
    disconnectEcho()
    set({ user: null, token: null, status: 'guest' })
  },

  hydrate: () => {
    const token = readStoredToken()
    // BUG CORRIGIDO (Setembro 2026): `hydrate` corre em cada componente
    // que usa useAuth() (cabeçalho, guardas, páginas). Antes, repunha
    // SEMPRE o estado para 'idle',uma sessão já confirmada voltava a
    // "não confirmada" a cada navegação, com o ecrã "A verificar
    // sessão..." a piscar e um novo pedido a /users/me. Com o mesmo
    // token já confirmado, não há nada a fazer.
    const current = get()
    if (current.status === 'authenticated' && current.token === token) {
      return
    }
    set({ token, status: token ? 'idle' : 'guest' })
  },
}))
