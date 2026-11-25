import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { useAuth } from '../useAuth'
import { useAuthStore } from '@/stores/authStore'
import type { AuthUser } from '@/types'

const me = vi.hoisted(() => vi.fn())
vi.mock('@/services/authService', () => ({ authService: { me } }))

const base = { id: 1, name: 'Ana', phone: '+244923000000', email: null, role: 'USER', status: 'ACTIVE', province_id: 1, municipality_id: 1 } as AuthUser

function Who() {
  const { user } = useAuth()
  return <p>{user ? `${user.name}|${user.avatar_url ?? 'sem-foto'}` : 'nada'}</p>
}

describe('useAuth', () => {
  beforeEach(() => {
    localStorage.setItem('kerugas_token', 't0k')
    me.mockReset().mockResolvedValue({ ...base })
    useAuthStore.setState({ user: null, token: 't0k', status: 'idle' })
  })

  it('não repõe a resposta antiga de /users/me por cima da sessão já atualizada (ex.: foto nova)', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    const wrap = (ui: JSX.Element) => <QueryClientProvider client={client}>{ui}</QueryClientProvider>

    const first = render(wrap(<Who />))
    await waitFor(() => expect(screen.getByText('Ana|sem-foto')).toBeInTheDocument())

    // a pessoa muda a foto → a sessão é atualizada
    useAuthStore.getState().setSession({ ...base, avatar_url: 'https://api.test/api/v1/avatars/a.webp' }, 't0k')
    first.unmount()

    // voltar a entrar no ecrã monta useAuth de novo, com /users/me ainda em cache
    render(wrap(<Who />))
    await new Promise((r) => setTimeout(r, 20))
    expect(screen.getByText('Ana|https://api.test/api/v1/avatars/a.webp')).toBeInTheDocument()
    expect(me).toHaveBeenCalledTimes(1)
  })
})
