import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import Login from '../Login'

const svc = vi.hoisted(() => ({ login: vi.fn() }))
vi.mock('@/services/authService', () => ({ authService: svc }))
const setSession = vi.hoisted(() => vi.fn())
vi.mock('@/stores/authStore', () => ({
  useAuthStore: (sel: (s: { setSession: typeof setSession }) => unknown) => sel({ setSession }),
}))

function Where() {
  const l = useLocation()
  return <p data-testid="where">{l.pathname}</p>
}

function renderLoginFrom(from: string) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
      <MemoryRouter initialEntries={[{ pathname: '/login', state: { from: { pathname: from } } }]}>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="*" element={<Where />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

async function signIn() {
  await userEvent.type(screen.getByLabelText('Telefone ou email'), '923555111')
  await userEvent.type(screen.getByLabelText('Password'), 'Segredo123')
  await userEvent.click(screen.getByRole('button', { name: 'Entrar' }))
}

/** Segurança (Outubro 2026): o regresso depois do login nunca sai do site. */
describe('Login,regresso à página de origem', () => {
  beforeEach(() => {
    svc.login.mockReset().mockResolvedValue({ user: { id: 1, role: 'USER' }, token: 't' })
    setSession.mockReset()
  })

  it('volta à página interna de onde veio', async () => {
    renderLoginFrom('/pedidos')
    await signIn()
    expect(await screen.findByTestId('where')).toHaveTextContent('/pedidos')
  })

  it.each(['//evil.com', '/\\evil.com', '/\\/evil.com'])('ignora um caminho de open redirect (%s) e vai para o painel', async (from) => {
    renderLoginFrom(from)
    await signIn()
    expect(await screen.findByTestId('where')).toHaveTextContent('/dashboard')
  })
})

/** Proteção contra adivinhação (Outubro 2026): a mensagem vem do servidor. */
describe('Login,mensagens de erro', () => {
  beforeEach(() => setSession.mockReset())

  function axiosError(status: number, message: string) {
    return Object.assign(new Error(message), {
      isAxiosError: true,
      response: { status, data: { message } },
    })
  }

  it('palavra-passe errada: mostra a mensagem do servidor', async () => {
    svc.login.mockReset().mockRejectedValue(axiosError(401, 'Credenciais inválidas.'))
    renderLoginFrom('/')
    await signIn()
    expect(await screen.findByRole('alert')).toHaveTextContent('Credenciais inválidas.')
    expect(screen.queryByRole('link', { name: /recuperar a palavra-passe/i })).toBeNull()
  })

  it('muitas tentativas nesta rede: diz quanto esperar e oferece recuperar a palavra-passe', async () => {
    svc.login.mockReset().mockRejectedValue(axiosError(429, 'Muitas tentativas falhadas. Por segurança, tente de novo daqui a 12 minutos,ou recupere a palavra-passe.'))
    renderLoginFrom('/')
    await signIn()
    expect(await screen.findByRole('alert')).toHaveTextContent('daqui a 12 minutos')
    expect(screen.getByRole('link', { name: /recuperar a palavra-passe/i })).toHaveAttribute('href', '/forgot-password')
  })
})
