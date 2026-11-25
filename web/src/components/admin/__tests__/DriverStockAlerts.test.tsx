import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { DriverStockAlerts } from '../DriverStockAlerts'

const svc = vi.hoisted(() => ({ pickupAlerts: vi.fn() }))
vi.mock('@/services/adminService', () => ({ adminService: svc }))

const renderIt = () => render(<QueryClientProvider client={new QueryClient()}><DriverStockAlerts /></QueryClientProvider>)

describe('Garrafas por justificar', () => {
  it('lista cada condutor com os motivos', async () => {
    svc.pickupAlerts.mockResolvedValue([{
      driver_id: 1, driver_name: 'Mateus', on_hand: 18,
      reasons: [{ type: 'idle', bottles: 18 }, { type: 'low_delivery', picked: 20, delivered: 2, percent: 10 }],
    }])
    renderIt()
    expect(await screen.findByText('Garrafas por justificar (1)')).toBeInTheDocument()
    expect(screen.getByText(/18 garrafa\(s\) paradas há mais de 3 dias/)).toBeInTheDocument()
    expect(screen.getByText(/levou 20 garrafas e entregou 2 com pedido \(10 %\)/)).toBeInTheDocument()
  })

  it('sem alertas não mostra nada', async () => {
    svc.pickupAlerts.mockResolvedValue([])
    const { container } = renderIt()
    await vi.waitFor(() => expect(svc.pickupAlerts).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})
