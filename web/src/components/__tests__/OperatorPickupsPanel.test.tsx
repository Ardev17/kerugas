import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { OperatorPickupsPanel } from '../OperatorPickupsPanel'

const pickups = vi.hoisted(() => vi.fn())
const confirmPickup = vi.hoisted(() => vi.fn())
const disputePickup = vi.hoisted(() => vi.fn())
vi.mock('@/services/operatorService', () => ({ operatorService: { pickups, confirmPickup, disputePickup } }))

describe('OperatorPickupsPanel', () => {
  beforeEach(() => {
    pickups.mockReset().mockResolvedValue({
      station: { id: 3, name: 'Posto Norte' },
      counter: { since_reset: 14, today: 10, pending_confirmation: 1, last_reset_at: null, last_reset_bottles: null },
      pickups: [{ id: 5, quantity: 10, driver_name: 'Carlos', state: 'DRIVER_ONLY', registered_at: null, dispute_note: null }],
    })
    confirmPickup.mockReset().mockResolvedValue({})
    disputePickup.mockReset().mockResolvedValue({})
  })

  function renderIt() {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><OperatorPickupsPanel /></QueryClientProvider>)
  }

  it('mostra o contador e confirma', async () => {
    renderIt()
    expect(await screen.findByTestId('counter-since-reset')).toHaveTextContent('14')
    await userEvent.click(screen.getByRole('button', { name: /Confirmar 10 garrafas de Carlos/ }))
    await waitFor(() => expect(confirmPickup).toHaveBeenCalledWith(5))
  })

  it('contesta com uma nota', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Não confere' }))
    expect(screen.getByRole('button', { name: 'Enviar' })).toBeDisabled()
    await userEvent.type(screen.getByLabelText('O que não confere'), 'levou só 8')
    await userEvent.click(screen.getByRole('button', { name: 'Enviar' }))
    await waitFor(() => expect(disputePickup).toHaveBeenCalledWith(5, 'levou só 8'))
  })
})
