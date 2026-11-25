import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminDemand from '../AdminDemand'
import type { DemandOverview } from '@/types'

vi.mock('react-leaflet', () => ({
  MapContainer: ({ children }: { children?: React.ReactNode }) => <div data-testid="map">{children}</div>,
  TileLayer: () => null,
  CircleMarker: ({ children }: { children?: React.ReactNode }) => <div data-testid="marker">{children}</div>,
  Tooltip: ({ children }: { children?: React.ReactNode }) => <span>{children}</span>,
  useMap: () => ({ fitBounds: vi.fn() }),
}))
vi.mock('leaflet/dist/leaflet.css', () => ({}))

const auth = vi.hoisted(() => ({ user: { role: 'ADMIN', province_id: 5 } as Record<string, unknown> }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))
const demand = vi.hoisted(() => vi.fn())
vi.mock('@/services/adminService', () => ({ adminService: { demand } }))
vi.mock('@/services/territoryService', () => ({
  territoryService: {
    listProvinces: vi.fn().mockResolvedValue([{ id: 5, name: 'Huíla' }]),
    listMunicipalities: vi.fn().mockResolvedValue([{ id: 9, name: 'Lubango' }]),
  },
}))

const grid = Array.from({ length: 7 }, () => Array(24).fill(0))
grid[1][19] = 4
const data: DemandOverview = {
  scope: 'province', days: 30,
  totals: { orders: 12, delivered: 9, cancelled: 2, bottles: 15, with_location: 9, location_rate: 75 },
  peak: { hour: 19, weekday: 'Ter' },
  points: [{ lat: -14.917, lng: 13.492, count: 4, delivered: 3 }, { lat: -14.93, lng: 13.51, count: 1, delivered: 1 }],
  weekday_hour: { weekdays: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'], grid },
  by_hour: Array(24).fill(0),
  neighborhoods: [{ neighborhood: 'Lalula', municipality: 'Lubango', orders: 5, delivered: 4, bottles: 6 }],
  municipalities: [{ municipality: 'Lubango', orders: 12, delivered: 9 }],
  stations: [{ id: 1, name: 'Posto Lubango', lat: -14.92, lng: 13.5 }],
}

function renderPage() {
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><AdminDemand /></QueryClientProvider>)
}

describe('AdminDemand', () => {
  beforeEach(() => {
    auth.user = { role: 'ADMIN', province_id: 5 }
    demand.mockReset().mockResolvedValue(data)
  })

  it('mostra resumo, pico, mapa, grelha e bairros', async () => {
    renderPage()
    expect(await screen.findByText('19h')).toBeInTheDocument()
    expect(screen.getByText('Dia com mais pedidos: Ter')).toBeInTheDocument()
    expect(screen.getByText('75%')).toBeInTheDocument()
    expect(screen.getByText('4 pedido(s) · 3 entregue(s)')).toBeInTheDocument()
    expect(screen.getByText('Posto: Posto Lubango')).toBeInTheDocument()
    expect(screen.getByLabelText('Ter 19h: 4')).toBeInTheDocument()
    expect(screen.getByText('Lalula')).toBeInTheDocument()
  })

  it('muda o período e esconde os postos', async () => {
    renderPage()
    await screen.findByText('19h')
    await userEvent.click(screen.getByRole('button', { name: '7 dias' }))
    await waitFor(() => expect(demand).toHaveBeenLastCalledWith(expect.objectContaining({ days: 7 })))
    await userEvent.click(screen.getByLabelText('Mostrar postos'))
    expect(screen.queryByText('Posto: Posto Lubango')).not.toBeInTheDocument()
  })

  it('o Admin não escolhe a província; o Super Admin geral sim', async () => {
    renderPage()
    await screen.findByText('19h')
    expect(screen.queryByLabelText('Província')).not.toBeInTheDocument()

    auth.user = { role: 'SUPER_ADMIN' }
    renderPage()
    await screen.findByRole('option', { name: 'Huíla' })
    await userEvent.selectOptions(screen.getByLabelText('Província'), '5')
    await waitFor(() => expect(demand).toHaveBeenLastCalledWith(expect.objectContaining({ province_id: 5 })))
  })

  it('sem pedidos com localização explica em vez de mostrar um mapa vazio', async () => {
    demand.mockResolvedValue({ ...data, points: [] })
    renderPage()
    expect(await screen.findByText(/Ainda não há pedidos com localização/)).toBeInTheDocument()
  })
})
