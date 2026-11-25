import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DriverStockPanel } from '../driver/DriverStockPanel'
import { readQueue } from '@/lib/pickupQueue'

const getStock = vi.hoisted(() => vi.fn())
const searchStations = vi.hoisted(() => vi.fn())
const registerPickup = vi.hoisted(() => vi.fn())
vi.mock('@/services/driverService', () => ({ driverService: { getStock, searchStations, registerPickup } }))

function renderPanel() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><DriverStockPanel /></QueryClientProvider>)
}

function setOnline(value: boolean) {
  Object.defineProperty(window.navigator, 'onLine', { configurable: true, get: () => value })
}

async function fillForm() {
  await userEvent.click(await screen.findByRole('button', { name: /Registar carregamento/ }))
  await screen.findByRole('option', { name: 'Posto Norte' })
  await userEvent.selectOptions(screen.getByLabelText('Posto'), '3')
  await userEvent.type(screen.getByLabelText('Número de garrafas'), '10')
  await userEvent.type(screen.getByLabelText(/Valor a pagar ao posto/), '12000')
}

describe('DriverStockPanel', () => {
  beforeEach(() => {
    localStorage.clear()
    setOnline(true)
    getStock.mockReset().mockResolvedValue({
      stock: { picked: 12, delivered: 4, on_hand: 8, reserved: 2, available: 6 },
      pickups: [{ id: 1, station_id: 3, station_name: 'Posto Norte', quantity: 12, state: 'DRIVER_ONLY', registered_at: null }],
    })
    searchStations.mockReset().mockResolvedValue([{ id: 3, name: 'Posto Norte', municipality: 'Lubango' }])
    registerPickup.mockReset().mockResolvedValue({})
  })
  afterEach(() => setOnline(true))

  it('mostra as garrafas disponíveis e o estado de confirmação', async () => {
    renderPanel()
    expect(await screen.findByText('Só condutor')).toBeInTheDocument()
    expect(screen.getByTestId('stock-available')).toHaveTextContent('6')
  })

  it('regista um carregamento com uuid', async () => {
    renderPanel()
    await fillForm()
    await userEvent.click(screen.getByRole('button', { name: 'Registar' }))
    await waitFor(() => expect(registerPickup).toHaveBeenCalledWith(expect.objectContaining({ station_id: 3, quantity: 10, amount: 12000 })))
    expect(registerPickup.mock.calls[0][0].client_uuid).toMatch(/^[0-9a-f-]{36}$/)
    expect(await screen.findByText(/O posto foi avisado/)).toBeInTheDocument()
  })

  it('sem rede guarda na fila e envia quando a rede volta', async () => {
    registerPickup.mockRejectedValueOnce(new Error('Network Error'))
    renderPanel()
    await fillForm()
    await userEvent.click(screen.getByRole('button', { name: 'Registar' }))

    expect(await screen.findByText(/1 carregamento\(s\) por enviar/)).toBeInTheDocument()
    expect(readQueue()).toHaveLength(1)

    registerPickup.mockResolvedValue({})
    window.dispatchEvent(new Event('online'))
    await waitFor(() => expect(readQueue()).toHaveLength(0))
    expect(registerPickup).toHaveBeenLastCalledWith(expect.objectContaining({ offline: true, quantity: 10 }))
  })
})
