import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { StationOrdersPanel } from '../StationOrdersPanel'
import type { GasOrder, StationOrdersPage } from '@/types'

const svc = vi.hoisted(() => ({ list: vi.fn(), accept: vi.fn(), assign: vi.fn(), release: vi.fn(), subscription: vi.fn() }))
vi.mock('@/services/stationOrderService', () => ({ stationOrderService: svc }))

const sub = (active: boolean, override: 'INHERIT' | 'ALLOW' | 'BLOCK' = 'INHERIT', listing_enforced = false) => ({
  active, can_accept: active || override === 'ALLOW',
  override, override_reason: override === 'BLOCK' ? 'Sem subscrição' : null,
  listing_enforced, listing_visible: active || !listing_enforced,
  valid_until: active ? '2026-11-30T10:00:00Z' : null, municipality: 'Lobito',
  price_per_week_kz: 1000, price_label: '1.000 Kz por semana',
  payment: { bank_name: 'BAI', account_holder: 'KeruGás', iban: 'AO06 0040 0000 1234 5678 9012 3', contact: '+244 922 761 180' },
})

const order = (extra: Partial<GasOrder> = {}): GasOrder => ({
  id: 5, reference: 'KG-20261116-000005', customer_name: 'Maria', customer_phone: '+244923777000',
  municipality: 'Lobito', province: 'Benguela', address: 'Rua da Praia', quantity: 2, status: 'PENDING',
  status_label: 'Pendente', created_at: '2026-11-16T09:00:00Z', ...extra,
} as GasOrder)

function page(scope: 'open' | 'mine', data: GasOrder[], active = true, override: 'INHERIT' | 'ALLOW' | 'BLOCK' = 'INHERIT', listing_enforced = false): StationOrdersPage {
  return { data, meta: { current_page: 1, last_page: 1, total: data.length, scope, subscription: sub(active, override, listing_enforced), drivers: [{ id: 9, name: 'Zé Condutor', phone: '+244923600001', available: true }] } }
}

function renderPanel() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <StationOrdersPanel />
    </QueryClientProvider>,
  )
}

describe('Pedidos do posto', () => {
  beforeEach(() => Object.values(svc).forEach((f) => f.mockReset()))

  it('posto marcado como "nunca recebe" mostra o preço semanal e como pagar', async () => {
    svc.list.mockResolvedValue(page('open', [], false, 'BLOCK'))
    renderPanel()

    expect(await screen.findByText(/1\.000 Kz por semana/)).toBeInTheDocument()
    expect(screen.getByText(/IBAN AO06/)).toBeInTheDocument()
    expect(screen.getByText(/os pedidos de Lobito aparecem aqui/)).toBeInTheDocument()
  })

  it('presença ativada sem subscrição paga mostra o cartão de pagamento', async () => {
    svc.list.mockResolvedValue(page('open', [], false, 'INHERIT', true))
    renderPanel()

    expect(await screen.findByText(/1\.000 Kz por semana/)).toBeInTheDocument()
    expect(screen.getByText(/IBAN AO06/)).toBeInTheDocument()
  })

  it('presença ativada com subscrição paga esconde o cartão', async () => {
    svc.list.mockResolvedValue(page('open', [], true, 'INHERIT', true))
    renderPanel()

    expect(await screen.findByText('Abertos no município')).toBeInTheDocument()
    expect(screen.queryByText(/1\.000 Kz por semana/)).not.toBeInTheDocument()
  })

  it('aceita um pedido aberto com o preço acertado com o cliente', async () => {
    svc.list.mockResolvedValue(page('open', [order()]))
    svc.accept.mockResolvedValue(order({ status: 'CONFIRMED' }))
    renderPanel()

    expect(await screen.findByText('KG-20261116-000005')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /\+244923777000/ })).toHaveAttribute('href', 'tel:+244923777000')

    await userEvent.click(screen.getByRole('button', { name: 'Aceitar pedido' }))
    await userEvent.type(screen.getByLabelText(/Preço acertado/), '18000')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar e aceitar' }))

    expect(svc.accept).toHaveBeenCalledWith(5, { delivery_price: 18000 })
  })

  it('mostra o erro quando outro posto aceitou primeiro', async () => {
    svc.list.mockResolvedValue(page('open', [order()]))
    svc.accept.mockRejectedValue({ isAxiosError: true, response: { status: 409, data: { message: 'Este pedido já foi aceite por outro posto ou pela KeruGás.' } } })
    renderPanel()

    await userEvent.click(await screen.findByRole('button', { name: 'Aceitar pedido' }))
    await userEvent.type(screen.getByLabelText(/Preço acertado/), '9000')
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar e aceitar' }))

    expect(await screen.findByText(/outro posto|Não foi possível aceitar/)).toBeInTheDocument()
  })

  it('envia um pedido do posto a um condutor do posto', async () => {
    svc.list.mockImplementation(async (scope: 'open' | 'mine') => scope === 'mine'
      ? page('mine', [order({ status: 'CONFIRMED', status_label: 'Confirmado', delivery_price: 18000, station_id: 7 })])
      : page('open', []))
    svc.assign.mockResolvedValue(order({ status: 'ASSIGNED' }))
    renderPanel()

    await userEvent.click(await screen.findByRole('tab', { name: 'Do nosso posto' }))
    await userEvent.selectOptions(await screen.findByLabelText(/Condutor para o pedido/), '9')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar ao condutor' }))

    expect(svc.assign.mock.calls[0][0]).toBe(5)
    expect(svc.assign.mock.calls[0][1]).toBe(9)
  })
})
