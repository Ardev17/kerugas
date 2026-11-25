import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminOrders from '../AdminOrders'

const listOrders = vi.hoisted(() => vi.fn())
const confirmOrder = vi.hoisted(() => vi.fn())
const listStations = vi.hoisted(() => vi.fn())

vi.mock('@/services/adminService', () => ({
  adminService: { listOrders, confirmOrder, listStations, contactOrder: vi.fn(), deliveryOrder: vi.fn(), completeOrder: vi.fn() },
}))
const groupsSvc = vi.hoisted(() => ({ groups: vi.fn(), list: vi.fn() }))
vi.mock('@/services/adminGroupsService', async (orig) => ({ ...(await orig<typeof import('@/services/adminGroupsService')>()), adminGroupsService: groupsSvc }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { role: 'SUPER_ADMIN' } }) }))

const order = {
  id: 5, reference: 'QG-1', status: 'CONTACTING', status_label: 'Em contacto', customer_name: 'Ana', customer_phone: '923000000',
  quantity: 3, province: 'Luanda', municipality: 'Viana', created_at: '2026-10-05T10:00:00Z',
}

describe('Confirmar pedido (valor das garrafas + comissão, sem posto)', () => {
  beforeEach(() => {
    listOrders.mockReset().mockResolvedValue({ data: [order], meta: { current_page: 1, last_page: 1, total: 1 } })
    // Organização por estado → província → município: uma só província e um
    // só município já vêm abertos.
    groupsSvc.groups.mockReset().mockResolvedValue({
      tabs: [{ key: 'PENDING', count: 1 }],
      groups: [{ province_id: 1, province: 'Luanda', count: 1, municipalities: [{ municipality_id: 2, municipality: 'Viana', count: 1 }] }],
    })
    groupsSvc.list.mockReset().mockResolvedValue({ data: [order], meta: { current_page: 1, last_page: 1, total: 1 } })
    confirmOrder.mockReset().mockResolvedValue(undefined)
    listStations.mockReset().mockResolvedValue({ data: [{ id: 7, name: 'Posto Central' }], meta: { current_page: 1, last_page: 1 } })
  })

  it('soma os dois valores, não pede posto e não deixa baixar as garrafas', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><AdminOrders /></QueryClientProvider>)

    await userEvent.click(await screen.findByRole('button', { name: 'Confirmar pedido' }))
    expect(groupsSvc.list).toHaveBeenCalledWith('orders', expect.objectContaining({ status: 'PENDING', province_id: 1, municipality_id: 2 }))

    await userEvent.type(screen.getByLabelText(/Valor das garrafas/), '1200')
    await userEvent.type(screen.getByLabelText(/Comissão da KeruGas/), '500')
    expect(screen.getByTestId('confirm-total')).toHaveTextContent(/1[\s.\u00a0]?700/)
    await userEvent.type(screen.getByLabelText('Tipo(s) de garrafa'), '13kg')

    const submit = (() => { const b = screen.getAllByRole('button', { name: 'Confirmar' }); return b[b.length - 1] })()
    expect(screen.queryByLabelText('Posto')).not.toBeInTheDocument()
    expect(submit).toBeEnabled()

    const qty = screen.getByLabelText('Número de garrafas')
    await userEvent.clear(qty)
    await userEvent.type(qty, '2')
    expect(submit).toBeDisabled() // 2 < 3
    await userEvent.clear(qty)
    await userEvent.type(qty, '4')

    await userEvent.click(submit)
    await waitFor(() => expect(confirmOrder).toHaveBeenCalledWith(5, {
      bottle_price: 1200, commission: 500, quantity: 4, cylinder_types_confirmed: '13kg',
    }))
  })
})
