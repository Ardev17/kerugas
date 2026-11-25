import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminBilling from '../AdminBilling'
import type { BillingOverview } from '@/types'

const billing = vi.hoisted(() => vi.fn())
const payStation = vi.hoisted(() => vi.fn())
const receiveDriverCash = vi.hoisted(() => vi.fn())
const billingLedger = vi.hoisted(() => vi.fn())
const undoBillingLedger = vi.hoisted(() => vi.fn())
const completeOrderBilling = vi.hoisted(() => vi.fn())
const listStations = vi.hoisted(() => vi.fn())
const exportBillingReport = vi.hoisted(() => vi.fn())
const role = vi.hoisted(() => ({ value: 'ADMIN' }))

vi.mock('@/services/adminService', () => ({
  adminService: { billing, payStation, receiveDriverCash, billingLedger, undoBillingLedger, completeOrderBilling, listStations, exportBillingReport },
}))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { role: role.value } }) }))
vi.mock('recharts', () => {
  const Stub = ({ children }: { children?: React.ReactNode }) => <div>{children}</div>
  return { BarChart: Stub, Bar: Stub, XAxis: Stub, YAxis: Stub, CartesianGrid: Stub, Tooltip: Stub, Legend: Stub, ResponsiveContainer: Stub }
})

const overview: BillingOverview = {
  scope: 'all',
  date: '2026-10-05',
  summary: { orders: 2, bottles: 3, collected_total: 4700, bottles_total: 3600, commission_total: 1100, legacy_orders: 1, legacy_total: 3000, stations_day_total: 12000, stations_unpaid_total: 12000 },
  stations: [{ station_id: 7, station_name: 'Posto Central', day_pickups: 1, day_bottles: 10, day_total: 12000, unpaid_pickups: 1, unpaid_bottles: 10, unpaid_total: 12000, unconfirmed_pickups: 1 }],
  daily: [{ date: '2026-10-05', count: 2, collected: 4700, bottles: 3600, commission: 1100 }],
  legacy: { count: 1, items: [{ id: 9, order_reference: 'QG-OLD', customer_name: 'Ana', quantity: 2, delivery_price: 3000, completed_at: null }] },
  drivers: [{ driver_id: 4, driver_name: 'Carlos', phone: null, orders: 2, collected_day: 4700, collected_total: 4700, received_total: 1700, outstanding: 3000, stock: { picked: 10, delivered: 3, on_hand: 7, reserved: 2, available: 5 } }],
  by_admin: [],
}

function renderPage() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><AdminBilling /></QueryClientProvider>)
}

describe('AdminBilling', () => {
  beforeEach(() => {
    role.value = 'ADMIN'
    billing.mockReset().mockResolvedValue(overview)
    payStation.mockReset().mockResolvedValue({ amount: 12000, pickups: 1 })
    receiveDriverCash.mockReset().mockResolvedValue({ amount: 1000, outstanding: 2000 })
    billingLedger.mockReset().mockResolvedValue([])
    undoBillingLedger.mockReset().mockResolvedValue(undefined)
    completeOrderBilling.mockReset().mockResolvedValue(undefined)
    exportBillingReport.mockReset().mockResolvedValue(undefined)
    listStations.mockReset().mockResolvedValue({ data: [{ id: 7, name: 'Posto Central' }], meta: { current_page: 1, last_page: 1 } })
  })

  it('mostra comissão, valor dos postos e dívida acumulada', async () => {
    renderPage()
    // O nome da marca no rótulo pode ser "Quero Gás" ou "KeruGás",o teste aceita os dois.
    expect(await screen.findByText(/^Comissão (Quero Gás|KeruG[aá]s) \(dia\)$/)).toBeInTheDocument()
    expect(screen.getByText(/Dívida total aos postos/)).toBeInTheDocument()
    expect(screen.getByText('Posto Central')).toBeInTheDocument()
    expect(screen.getByText('1 sem confirmação do posto')).toBeInTheDocument()
    expect(screen.getByText(/Garrafas disponíveis:/)).toHaveTextContent('5')
  })

  it('marca pago ao posto só depois de confirmar', async () => {
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: /Marcar pago Posto Central/ }))
    expect(payStation).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Confirmar pagamento' }))
    await waitFor(() => expect(payStation).toHaveBeenCalledWith(7))
  })

  it('regista um recebimento parcial do condutor', async () => {
    renderPage()
    const input = await screen.findByLabelText('Valor recebido de Carlos')
    await userEvent.type(input, '1000')
    await userEvent.click(screen.getByRole('button', { name: 'Recebi' }))
    await waitFor(() => expect(receiveDriverCash).toHaveBeenCalledWith(4, 1000))
  })

  it('não deixa receber acima do que falta', async () => {
    renderPage()
    await userEvent.type(await screen.findByLabelText('Valor recebido de Carlos'), '3500')
    expect(screen.getByRole('button', { name: 'Recebi' })).toBeDisabled()
  })

  it('completa um pedido antigo só quando a soma iguala o total', async () => {
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Completar' }))
    const dialog = screen.getByText('Completar QG-OLD').closest('div')!.parentElement as HTMLElement
    await userEvent.type(within(dialog).getByLabelText(/Valor das garrafas/), '2000')
    await userEvent.type(within(dialog).getByLabelText(/Comissão/), '500')
    expect(within(dialog).getByText(/tem de ser igual ao total/)).toBeInTheDocument()
    expect(within(dialog).getByRole('button', { name: 'Guardar' })).toBeDisabled()

    await userEvent.clear(within(dialog).getByLabelText(/Comissão/))
    await userEvent.type(within(dialog).getByLabelText(/Comissão/), '1000')
    await userEvent.click(within(dialog).getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(completeOrderBilling).toHaveBeenCalledWith(9, { bottle_price: 2000, commission: 1000, quantity: 2 }))
  })

  it('só o Super Admin vê o registo e pode desfazer', async () => {
    billingLedger.mockResolvedValue([
      { id: 31, action: 'billing.station_paid', by: 'Admin X', at: null, details: { amount: 3600, station_name: 'Posto Central' }, undone: false, can_undo: true },
    ])
    renderPage()
    await screen.findByText('Posto Central')
    expect(screen.queryByText('Registo de pagamentos e recebimentos')).not.toBeInTheDocument()

    role.value = 'SUPER_ADMIN'
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Desfazer' }))
    await waitFor(() => expect(undoBillingLedger).toHaveBeenCalledWith(31))
  })

  it('descarrega os relatórios em Excel e PDF com os parâmetros certos', async () => {
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: 'Fecho do dia em PDF' }))
    await waitFor(() => expect(exportBillingReport).toHaveBeenCalledWith(expect.objectContaining({ type: 'day', format: 'pdf', date: expect.any(String) })))

    await userEvent.click(screen.getByRole('button', { name: 'Dívida aos postos em Excel' }))
    await waitFor(() => expect(exportBillingReport).toHaveBeenLastCalledWith(expect.objectContaining({ type: 'stations', format: 'csv' })))

    await userEvent.click(screen.getByRole('button', { name: 'Dinheiro dos condutores em Excel' }))
    await waitFor(() => expect(exportBillingReport).toHaveBeenLastCalledWith(expect.objectContaining({ type: 'drivers', from: expect.stringMatching(/-01$/), to: expect.any(String) })))
  })
})
