import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { NotificationsBell } from '../NotificationsBell'
import { NotificationPopupHost } from '../NotificationPopup'
import { useNotificationPopup } from '@/stores/notificationPopupStore'

const list = vi.hoisted(() => vi.fn())
const get = vi.hoisted(() => vi.fn())
const markRead = vi.hoisted(() => vi.fn())
vi.mock('@/services/notificationService', () => ({ notificationService: { list, get, markRead, markAllRead: vi.fn() } }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ isAuthenticated: true }) }))

const longBody = 'Olá, caro operador. ' + 'Esta é uma mensagem muito longa. '.repeat(10)

function Where() {
  const l = useLocation()
  return <p data-testid="where">{l.pathname + l.search}</p>
}

function renderAt(path: string) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <MemoryRouter initialEntries={[path]}>
        <Routes><Route path="*" element={<><NotificationsBell /><Where /></>} /></Routes>
        <NotificationPopupHost />
      </MemoryRouter>
    </QueryClientProvider>
  )
}

describe('popup das notificações', () => {
  beforeEach(() => {
    useNotificationPopup.setState({ current: null })
    markRead.mockReset().mockResolvedValue(undefined)
    get.mockReset()
    list.mockReset().mockResolvedValue({
      data: [
        { id: 1, category: 'system', title: 'Aviso do sistema', body: longBody, data: { type: 'x' }, read: false, created_at: '2026-10-05T08:00:00Z' },
        { id: 2, category: 'system', title: 'Com ecrã', body: 'Abrir operador', data: { path: '/operator' }, read: true, created_at: '2026-10-05T08:00:00Z' },
      ],
      meta: { unread_count: 1, current_page: 1, last_page: 1 },
    })
  })

  it('sem ecrã: abre o popup com a mensagem completa e marca como lida', async () => {
    renderAt('/')
    await userEvent.click(await screen.findByRole('button', { name: /Notificações/ }))
    await userEvent.click(await screen.findByText('Aviso do sistema'))

    const dialog = await screen.findByRole('dialog')
    expect(dialog).toHaveTextContent(longBody.trim())
    expect(markRead).toHaveBeenCalledWith(1)
    await userEvent.click(screen.getByRole('button', { name: 'OK' }))
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('com ecrã: navega, sem popup', async () => {
    renderAt('/')
    await userEvent.click(await screen.findByRole('button', { name: /Notificações/ }))
    await userEvent.click(await screen.findByText('Com ecrã'))
    expect(screen.getByTestId('where')).toHaveTextContent('/operator')
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  })

  it('tocar na push (/?notificacao=ID) abre o popup e limpa o endereço', async () => {
    get.mockResolvedValue({ id: 9, category: 'system', title: 'Push longa', body: longBody, data: null, read: true, created_at: '2026-10-05T08:00:00Z' })
    renderAt('/?notificacao=9')

    expect(await screen.findByRole('dialog')).toHaveTextContent('Push longa')
    expect(get).toHaveBeenCalledWith(9)
    await waitFor(() => expect(screen.getByTestId('where')).toHaveTextContent(/^\/$/))
  })
})
