import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import ForgotPassword from '../ForgotPassword'

const svc = vi.hoisted(() => ({ forgotPassword: vi.fn(), resetPassword: vi.fn() }))
vi.mock('@/services/authService', () => ({ authService: svc }))

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <MemoryRouter><ForgotPassword /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Recuperar palavra-passe (código por email)', () => {
  beforeEach(() => {
    svc.forgotPassword.mockReset().mockResolvedValue(undefined)
    svc.resetPassword.mockReset().mockResolvedValue(undefined)
  })

  it('pede o email, depois o código e a nova palavra-passe no mesmo ecrã', async () => {
    renderPage()
    await userEvent.type(screen.getByLabelText('Email'), 'ana@exemplo.ao')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar código' }))
    expect(svc.forgotPassword).toHaveBeenCalledWith('ana@exemplo.ao', undefined) // honeypot vazio: nada enviado

    const code = await screen.findByLabelText('Código de 6 dígitos')
    expect(screen.getByRole('button', { name: /Reenviar código \(\d+s\)/ })).toBeDisabled()
    await userEvent.type(code, '12a3456') // só algarismos, máx. 6
    expect(code).toHaveValue('123456')

    const submit = screen.getByRole('button', { name: 'Repor palavra-passe' })
    await userEvent.type(screen.getByLabelText('Nova palavra-passe'), 'curta')
    expect(submit).toBeDisabled()
    await userEvent.clear(screen.getByLabelText('Nova palavra-passe'))
    await userEvent.type(screen.getByLabelText('Nova palavra-passe'), 'Nova12345')
    await userEvent.type(screen.getByLabelText('Confirmar nova palavra-passe'), 'Nova12345')
    await userEvent.click(submit)

    await waitFor(() => expect(svc.resetPassword).toHaveBeenCalledWith({ email: 'ana@exemplo.ao', code: '123456', password: 'Nova12345', password_confirmation: 'Nova12345' }))
    expect(await screen.findByRole('status')).toHaveTextContent('Palavra-passe reposta')
  })

  it('mostra o erro do código vindo do servidor', async () => {
    svc.resetPassword.mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { message: 'x', errors: { code: ['Código errado ou expirado.'] } } } })
    renderPage()
    await userEvent.type(screen.getByLabelText('Email'), 'ana@exemplo.ao')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar código' }))
    await userEvent.type(await screen.findByLabelText('Código de 6 dígitos'), '000000')
    await userEvent.type(screen.getByLabelText('Nova palavra-passe'), 'Nova12345')
    await userEvent.type(screen.getByLabelText('Confirmar nova palavra-passe'), 'Nova12345')
    await userEvent.click(screen.getByRole('button', { name: 'Repor palavra-passe' }))
    expect(await screen.findByText('Código errado ou expirado.')).toBeInTheDocument()
  })

  it('um bot que preenche o campo escondido é sinalizado ao servidor (honeypot)', async () => {
    const { container } = renderPage()
    await userEvent.type(screen.getByLabelText('Email'), 'bot@exemplo.ao')
    const trap = container.querySelector('[data-testid="honeypot"] input') as HTMLInputElement
    await userEvent.type(trap, 'http://spam')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar código' }))
    expect(svc.forgotPassword).toHaveBeenCalledWith('bot@exemplo.ao', 'http://spam')
  })
})
