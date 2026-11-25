import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useAuthStore } from '../authStore'
import type { AuthUser } from '@/types'

const mockUser: AuthUser = {
  id: 1,
  name: 'Utilizador Teste',
  phone: '+244900000005',
  email: null,
  role: 'USER',
  status: 'ACTIVE',
  province_id: 1,
  municipality_id: 1,
}

describe('authStore', () => {
  beforeEach(() => {
    localStorage.clear()
    useAuthStore.setState({ user: null, token: null, status: 'idle' })
  })

  it('começa sem sessão (status idle)', () => {
    expect(useAuthStore.getState().status).toBe('idle')
    expect(useAuthStore.getState().user).toBeNull()
  })

  it('setSession guarda o utilizador, o token, e persiste o token no localStorage', () => {
    useAuthStore.getState().setSession(mockUser, 'token-abc')

    const state = useAuthStore.getState()
    expect(state.status).toBe('authenticated')
    expect(state.user).toEqual(mockUser)
    expect(state.token).toBe('token-abc')
    expect(localStorage.getItem('kerugas_token')).toBe('token-abc')
  })

  it('clearSession remove o utilizador, o token, e limpa o localStorage', () => {
    useAuthStore.getState().setSession(mockUser, 'token-abc')
    useAuthStore.getState().clearSession()

    const state = useAuthStore.getState()
    expect(state.status).toBe('guest')
    expect(state.user).toBeNull()
    expect(state.token).toBeNull()
    expect(localStorage.getItem('kerugas_token')).toBeNull()
  })

  it('hydrate recupera o token do localStorage sem confiar no user em cache', () => {
    localStorage.setItem('kerugas_token', 'token-persistido')

    useAuthStore.getState().hydrate()

    const state = useAuthStore.getState()
    expect(state.token).toBe('token-persistido')
    // Nunca marca como autenticado só por ter token,isso exige
    // revalidação via /users/me (feita pelo hook useAuth, não pelo store).
    expect(state.status).toBe('idle')
  })

  it('hydrate marca status guest quando não há token guardado', () => {
    useAuthStore.getState().hydrate()
    expect(useAuthStore.getState().status).toBe('guest')
  })
})

describe('authStore,regressões encontradas ao testar o app Android (Setembro 2026)', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('hydrate não desfaz uma sessão já confirmada com o mesmo token', () => {
    useAuthStore.getState().setSession(mockUser, 'token-abc')

    useAuthStore.getState().hydrate()

    expect(useAuthStore.getState().status).toBe('authenticated')
    expect(useAuthStore.getState().user).toEqual(mockUser)
  })

  it('hydrate volta a exigir confirmação se o token guardado mudou (outra conta)', () => {
    useAuthStore.getState().setSession(mockUser, 'token-abc')
    localStorage.setItem('kerugas_token', 'token-de-outra-conta')

    useAuthStore.getState().hydrate()

    expect(useAuthStore.getState().status).toBe('idle')
    expect(useAuthStore.getState().token).toBe('token-de-outra-conta')
  })

  it('com um token guardado, o store já nasce à espera de confirmação,nunca como visitante', async () => {
    localStorage.setItem('kerugas_token', 'token-persistido')
    vi.resetModules()

    const { useAuthStore: freshStore } = await import('../authStore')

    expect(freshStore.getState().token).toBe('token-persistido')
    expect(freshStore.getState().status).toBe('idle')
  })

  it('sem token guardado, o store nasce como visitante', async () => {
    vi.resetModules()

    const { useAuthStore: freshStore } = await import('../authStore')

    expect(freshStore.getState().status).toBe('guest')
  })
})
