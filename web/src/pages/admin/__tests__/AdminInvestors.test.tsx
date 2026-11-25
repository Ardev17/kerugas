import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminInvestors from '../AdminInvestors'

const inv = vi.hoisted(() => ({ list: vi.fn(), assign: vi.fn(), changePercentage: vi.fn(), remove: vi.fn(), overviewOf: vi.fn(), overview: vi.fn(), exportOf: vi.fn(), deactivate: vi.fn(), reactivate: vi.fn() }))
const listUsers = vi.hoisted(() => vi.fn())
vi.mock('@/services/investorService', () => ({ investorService: inv }))
vi.mock('@/services/adminService', () => ({ adminService: { listUsers } }))
vi.mock('@/services/territoryService', () => ({
  territoryService: {
    listProvinces: vi.fn().mockResolvedValue([{ id: 2, name: 'Benguela' }]),
    listMunicipalities: vi.fn().mockResolvedValue([{ id: 1, name: 'Lobito' }, { id: 3, name: 'Ganda' }]),
  },
}))
vi.mock('@/lib/echo', () => ({ getEcho: () => null }))

const ana = {
  id: 9, name: 'Ana Investidora', phone: '+244923000111', email: null, avatar_url: null, status: 'ACTIVE', chat_unread: 2,
  active: true, deactivated_at: null, paused_municipalities: [] as Array<{ id: number; name: string; percentage: number }>,
  municipalities: [{ id: 1, name: 'Lobito', province: 'Benguela', percentage: 10, since: '2026-10-01T00:00:00Z' }],
}

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/admin/investidores']}><AdminInvestors /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Investidores (Super Admin)', () => {
  beforeEach(() => {
    Object.values(inv).forEach((f) => f.mockReset())
    listUsers.mockReset()
    inv.list.mockResolvedValue({ investors: [ana], taken: [{ municipality_id: 1, investor_id: 9, investor_name: 'Ana Investidora' }] })
    inv.assign.mockResolvedValue(undefined)
    inv.remove.mockResolvedValue(undefined)
  })

  it('lista os investidores com municípios, percentagem e mensagens por ler', async () => {
    renderPage()
    expect(await screen.findByText('Ana Investidora')).toBeInTheDocument()
    expect(screen.getByText('10%')).toBeInTheDocument()
    expect(screen.getByLabelText('2 por ler')).toBeInTheDocument()
  })

  it('associa um utilizador a um município livre (os ocupados não se escolhem)', async () => {
    listUsers.mockResolvedValue({ data: [
      { id: 20, name: 'Rui Novo', role: 'USER', phone: '+244923000222' },
      { id: 21, name: 'Admin X', role: 'ADMIN', phone: '+244923000333' },
    ] })
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: /Associar investidor/ }))
    const dialog = screen.getByRole('dialog', { name: 'Associar investidor' })
    await userEvent.type(within(dialog).getByLabelText(/Pessoa/), 'Ru')
    await userEvent.click(await within(dialog).findByText('Rui Novo'))
    expect(within(dialog).queryByText('Admin X')).not.toBeInTheDocument()

    await userEvent.selectOptions(within(dialog).getByLabelText('Província'), '2')
    const municipality = within(dialog).getByLabelText('Município')
    await waitFor(() => expect(within(municipality).getByRole('option', { name: /Lobito,já tem investidor/ })).toBeDisabled())
    await userEvent.selectOptions(municipality, '3')
    await userEvent.type(within(dialog).getByLabelText('Percentagem'), '12,5')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Associar' }))

    await waitFor(() => expect(inv.assign).toHaveBeenCalledWith(expect.objectContaining({ user_id: 20, municipality_id: 3, percentage: 12.5 })))
  })

  it('pede confirmação antes de retirar um investidor do município', async () => {
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Retirar Ana Investidora de Lobito' }))
    const dialog = screen.getByRole('dialog', { name: 'Retirar investidor?' })
    expect(dialog).toHaveTextContent('a conta volta a Utilizador')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Retirar' }))
    await waitFor(() => expect(inv.remove).toHaveBeenCalledWith(9, 1))
  })

  it('desativa um investidor (contrato desfeito) com confirmação e motivo', async () => {
    inv.deactivate.mockResolvedValue({ freed_municipalities: ['Lobito'] })
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Desativar Ana Investidora' }))
    const dialog = screen.getByRole('dialog', { name: 'Desativar Ana Investidora?' })
    expect(dialog).toHaveTextContent('ficam livres para outro investidor')
    await userEvent.type(within(dialog).getByLabelText('Motivo'), 'Contrato terminado')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Desativar' }))
    await waitFor(() => expect(inv.deactivate).toHaveBeenCalledWith(9, 'Contrato terminado'))
    expect(await screen.findByText(/Municípios libertados: Lobito/)).toBeInTheDocument()
  })

  it('um investidor desativado aparece marcado e pode ser reativado', async () => {
    inv.list.mockResolvedValue({ investors: [{ ...ana, active: false, deactivated_at: '2026-10-13T10:00:00Z', municipalities: [], paused_municipalities: [{ id: 1, name: 'Lobito', percentage: 10 }] }], taken: [] })
    inv.reactivate.mockResolvedValue({ restored: ['Lobito'], skipped: [] })
    renderPage()
    expect(await screen.findByText('Desativado')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ver e exportar/ })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Reativar Ana Investidora' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Reativar' }))
    await waitFor(() => expect(inv.reactivate).toHaveBeenCalledWith(9))
    expect(await screen.findByText(/Volta a ganhar, a partir de agora, em: Lobito/)).toBeInTheDocument()
  })
})
