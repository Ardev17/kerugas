import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminCentralChats from '../AdminCentralChats'

const svc = vi.hoisted(() => ({ centralThreads: vi.fn(), overview: vi.fn(), messages: vi.fn(), mentionable: vi.fn() }))
const listUsers = vi.hoisted(() => vi.fn())
vi.mock('@/services/roomChatService', () => ({ roomChatService: svc }))
vi.mock('@/services/adminService', () => ({ adminService: { listUsers } }))
vi.mock('@/lib/echo', () => ({ getEcho: () => null }))

function renderPage(path = '/admin/conversas-central') {
  Element.prototype.scrollIntoView = vi.fn()
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}><AdminCentralChats /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Conversa com a Central (admins)', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset())
    listUsers.mockReset()
    svc.centralThreads.mockResolvedValue([{
      user: { id: 9, name: 'Maria Cliente', phone: '+244923000111', avatar_url: null, type_label: 'Utilizador', province: 'Benguela', municipality: 'Lobito' },
      last_message: { excerpt: 'Obrigada pelo aviso', from_user: true, author: 'Maria Cliente', created_at: new Date().toISOString() },
      total: 2, unread: 1,
    }])
  })

  it('lista as conversas com as mensagens por ler', async () => {
    renderPage()
    expect(await screen.findByText('Maria Cliente')).toBeInTheDocument()
    expect(screen.getByText('Obrigada pelo aviso')).toBeInTheDocument()
    expect(screen.getByLabelText('1 por ler')).toBeInTheDocument()
  })

  it('nova conversa: procura só utilizadores (sem admins)', async () => {
    listUsers.mockResolvedValue({ data: [
      { id: 9, name: 'Maria Cliente', role: 'USER', phone: '+244923000111' },
      { id: 2, name: 'Admin Benguela', role: 'ADMIN', phone: '+244923000222' },
    ] })
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: /Nova conversa/ }))
    await userEvent.type(screen.getByLabelText('Procurar utilizador'), 'Ma')
    expect(await screen.findAllByText('Maria Cliente')).not.toHaveLength(0)
    expect(screen.queryByText('Admin Benguela')).not.toBeInTheDocument()
  })
})
