import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DriverCentralInbox } from '../driver/DriverCentralInbox'
import type { DriverStockOverview } from '@/services/driverService'

const svc = vi.hoisted(() => ({ confirmCentralPickup: vi.fn(), disputeCentralPickup: vi.fn(), setSmsPin: vi.fn() }))
vi.mock('@/services/driverService', () => ({ driverService: svc }))

const base = {
  stock: { picked: 10, delivered: 0, on_hand: 10, reserved: 0, available: 10 },
  pickups: [{
    id: 5, station_id: 3, station_name: 'Posto Norte', quantity: 10, state: 'DRIVER_ONLY', registered_at: null, awaiting_confirmation: true,
    source: 'CENTRAL', registered_by_name: 'Admin Ana', central_channel: 'PHONE', central_reason: 'Ligou do posto',
  }],
  central_pickup: { active: true, expires_at: '2026-10-20T10:00:00Z' },
  sms_pin_set: false,
} as unknown as DriverStockOverview

function renderIt(data = base) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><DriverCentralInbox data={data} /></QueryClientProvider>)
}

describe('DriverCentralInbox', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset().mockResolvedValue({}))
  })

  it('mostra o que a central registou e o condutor confirma', async () => {
    renderIt()
    expect(screen.getByText(/A central registou por si/)).toBeInTheDocument()
    expect(screen.getByText(/Ligou do posto/)).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Confirmar 10 garrafas/ }))
    await waitFor(() => expect(svc.confirmCentralPickup).toHaveBeenCalledWith(5))
  })

  it('contestar exige uma explicação', async () => {
    renderIt()
    await userEvent.click(screen.getByRole('button', { name: 'Não é verdade' }))
    const enviar = screen.getByRole('button', { name: 'Contestar' })
    expect(enviar).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Porque não é verdade?'), 'Não estive lá')
    await userEvent.click(enviar)
    await waitFor(() => expect(svc.disputeCentralPickup).toHaveBeenCalledWith(5, 'Não estive lá'))
  })

  it('define o PIN do SMS com a palavra-passe', async () => {
    renderIt()
    await userEvent.click(screen.getByRole('button', { name: 'Definir PIN' }))
    await userEvent.type(screen.getByLabelText('Novo PIN'), '48a21')
    expect(screen.getByLabelText('Novo PIN')).toHaveValue('4821')
    await userEvent.type(screen.getByLabelText('A sua palavra-passe'), 'Segredo123')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))
    await waitFor(() => expect(svc.setSmsPin).toHaveBeenCalledWith('Segredo123', '4821'))
  })

  it('com o PIN definido mostra o comando de SMS e não mostra pedidos por confirmar', () => {
    renderIt({ ...base, pickups: [], central_pickup: { active: false, expires_at: null }, sms_pin_set: true })
    expect(screen.getByText(/CARREGAR 10 00023/)).toBeInTheDocument()
    expect(screen.queryByTestId('central-pickup')).toBeNull()
    expect(screen.getByRole('button', { name: 'Mudar o PIN' })).toBeInTheDocument()
  })

  it('avisa que o PIN é provisório e convida a mudá-lo', () => {
    renderIt({ ...base, pickups: [], central_pickup: { active: false, expires_at: null }, sms_pin_set: true, sms_pin_temporary: true })
    expect(screen.getByRole('status')).toHaveTextContent(/definido por um administrador/)
    expect(screen.getByRole('button', { name: 'Mudar o PIN agora' })).toBeInTheDocument()
  })

  it('um SMS com PIN provisório aparece para o condutor confirmar', () => {
    const sms = { ...base.pickups[0], source: 'SMS', central_reason: null }
    renderIt({ ...base, pickups: [sms] } as unknown as DriverStockOverview)
    expect(screen.getByText(/Pedido por SMS com o PIN provisório/)).toBeInTheDocument()
    expect(screen.getByText(/Confirme só se foi você que enviou/)).toBeInTheDocument()
  })
})
