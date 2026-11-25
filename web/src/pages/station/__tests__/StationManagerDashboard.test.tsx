import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import StationManagerDashboard from '../StationManagerDashboard'
import type { StationManagerOverview } from '@/types'

const svc = vi.hoisted(() => ({
  overview: vi.fn(), updateStation: vi.fn(), addOperator: vi.fn(), removeOperator: vi.fn(),
  createDriver: vi.fn(), updateDriver: vi.fn(), removeDriver: vi.fn(),
}))
vi.mock('@/services/stationManagerService', () => ({ stationManagerService: svc }))
const orders = vi.hoisted(() => ({ list: vi.fn(), accept: vi.fn(), assign: vi.fn(), release: vi.fn(), subscription: vi.fn() }))
vi.mock('@/services/stationOrderService', () => ({ stationOrderService: orders }))

function overview(extra: Partial<StationManagerOverview> = {}): StationManagerOverview {
  return {
    station: {
      id: 7, code: '00007', name: 'Posto Lobito', municipality: 'Lobito', province: 'Benguela',
      address: 'Rua 1', reference_point: null, phone: null, opening_hours: null,
    } as StationManagerOverview['station'],
    operators: [{ id: 1, name: 'Ana Operadora', phone: '+244923000001', status: 'ACTIVE' }],
    managers: [{ id: 2, name: 'Gerente', phone: '+244923000002', status: 'ACTIVE' }],
    drivers: [],
    ...extra,
  }
}

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><StationManagerDashboard /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Painel do Admin de Posto', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset())
    orders.list.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0, scope: 'open', drivers: [], subscription: { active: false, can_accept: false, override: 'INHERIT', override_reason: null, listing_enforced: true, listing_visible: false, valid_until: null, municipality: 'Lobito', price_per_week_kz: 1000, price_label: '1.000 Kz por semana', payment: { bank_name: null, account_holder: null, iban: null, contact: '922761180' } } } })
  })

  it('abre nos pedidos do município', async () => {
    svc.overview.mockResolvedValue(overview())
    renderPage()

    expect(await screen.findByRole('tab', { name: /Pedidos/ })).toHaveAttribute('aria-selected', 'true')
    expect(await screen.findByText(/1\.000 Kz por semana/)).toBeInTheDocument()
  })

  it('mostra o posto e guarda as informações', async () => {
    svc.overview.mockResolvedValue(overview())
    svc.updateStation.mockResolvedValue(overview())
    renderPage()

    expect(await screen.findByRole('heading', { name: 'Posto Lobito' })).toBeInTheDocument()
    expect(screen.getByText(/N\.º 00007/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Estado do gás/ })).toHaveAttribute('href', '/operator')

    await userEvent.click(screen.getByRole('tab', { name: /^Posto$/ }))
    const horario = screen.getByPlaceholderText('Ex.: 07h às 19h')
    await userEvent.type(horario, '07h-19h')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar informações' }))

    expect(svc.updateStation.mock.calls[0][0]).toEqual(expect.objectContaining({ name: 'Posto Lobito', opening_hours: '07h-19h' }))
    expect(await screen.findByText('Informações guardadas.')).toBeInTheDocument()
  })

  it('promove um operador pelo telemóvel e mostra o erro do servidor', async () => {
    svc.overview.mockResolvedValue(overview())
    svc.addOperator.mockRejectedValue({ isAxiosError: true, response: { data: { message: 'Bruno é de outro município.' } } })
    renderPage()

    await userEvent.click(await screen.findByRole('tab', { name: /Operadores \(1\)/ }))
    expect(screen.getByText('Ana Operadora')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Telemóvel da pessoa a promover'), '923500002')
    await userEvent.click(screen.getByRole('button', { name: 'Promover' }))

    expect(svc.addOperator.mock.calls[0][0]).toBe('923500002')
    expect(await screen.findByText(/outro município|Não foi possível promover/)).toBeInTheDocument()
  })

  it('cria um condutor do posto', async () => {
    svc.overview.mockResolvedValue(overview())
    svc.createDriver.mockResolvedValue(overview({ drivers: [{ id: 9, name: 'Zé Condutor', phone: '+244923600001', status: 'ACTIVE', available: true }] }))
    renderPage()

    await userEvent.click(await screen.findByRole('tab', { name: /Condutores \(0\)/ }))
    expect(screen.getByText('Este posto ainda não tem condutores.')).toBeInTheDocument()

    const criar = screen.getByRole('button', { name: 'Criar condutor' })
    expect(criar).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Nome do condutor'), 'Zé Condutor')
    await userEvent.type(screen.getByLabelText('Telemóvel do condutor'), '923600001')
    await userEvent.type(screen.getByLabelText('Palavra-passe do condutor'), 'segredo-forte')
    await userEvent.click(criar)

    expect(svc.createDriver.mock.calls[0][0]).toEqual({ name: 'Zé Condutor', phone: '923600001', password: 'segredo-forte' })
    expect(await screen.findByText('Zé Condutor')).toBeInTheDocument()
  })

  it('sem posto atribuído mostra uma mensagem clara', async () => {
    svc.overview.mockRejectedValue(new Error('404'))
    renderPage()

    expect(await screen.findByText('Não existe posto atribuído à sua conta.')).toBeInTheDocument()
  })
})
