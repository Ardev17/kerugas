import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminPickups from '../AdminPickups'

const pickups = vi.hoisted(() => vi.fn())
const correctPickupAmount = vi.hoisted(() => vi.fn())
const resetStationCounter = vi.hoisted(() => vi.fn())
const role = vi.hoisted(() => ({ value: 'ADMIN' }))
vi.mock('@/services/adminService', () => ({ adminService: { pickups, correctPickupAmount, resetStationCounter } }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { role: role.value } }) }))

function renderIt() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  render(<QueryClientProvider client={client}><AdminPickups /></QueryClientProvider>)
}

describe('AdminPickups', () => {
  beforeEach(() => {
    role.value = 'ADMIN'
    pickups.mockReset().mockResolvedValue({
      counters: [{ station_id: 3, station_name: 'Posto Norte', since_reset: 14, today: 10, pending_confirmation: 1, last_reset_at: null, last_reset_bottles: null }],
      pickups: [{ id: 5, quantity: 10, station_name: 'Posto Norte', driver_name: 'Carlos', amount: 12000, state: 'DRIVER_ONLY', registered_at: null, synced_offline: true, dispute_note: null, station_paid_at: null }],
      resets: [],
    })
    correctPickupAmount.mockReset().mockResolvedValue({})
    resetStationCounter.mockReset().mockResolvedValue({ bottles_before: 14 })
  })

  it('o Admin só vê: não corrige o valor nem reinicia contadores', async () => {
    renderIt()
    expect(await screen.findByText(/registado sem internet/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Reiniciar contador/ })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Corrigir valor/ })).not.toBeInTheDocument()
  })

  it('o Super Admin Provincial corrige o valor do condutor', async () => {
    role.value = 'PROVINCE_SUPER_ADMIN'
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Corrigir valor/ }))
    const input = screen.getByLabelText('Novo valor')
    await userEvent.clear(input)
    await userEvent.type(input, '13000')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(correctPickupAmount).toHaveBeenCalledWith(5, 13000))
  })

  it('o Super Admin reinicia o contador depois de confirmar', async () => {
    role.value = 'SUPER_ADMIN'
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Reiniciar contador Posto Norte' }))
    expect(resetStationCounter).not.toHaveBeenCalled()
    await userEvent.click(screen.getByRole('button', { name: 'Reiniciar' }))
    await waitFor(() => expect(resetStationCounter).toHaveBeenCalledWith(3))
  })
})
