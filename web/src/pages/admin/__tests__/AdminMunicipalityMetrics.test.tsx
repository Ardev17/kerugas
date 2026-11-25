import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminMunicipalityMetrics from '../AdminMunicipalityMetrics'
import type { MunicipalityMetrics } from '@/types'

const auth = vi.hoisted(() => ({ user: { id: 1, role: 'SUPER_ADMIN', name: 'Ardev', province: null } as Record<string, unknown> }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

const svc = vi.hoisted(() => ({ municipalityMetrics: vi.fn() }))
vi.mock('@/services/adminService', () => ({ adminService: svc }))
const territory = vi.hoisted(() => ({ listProvinces: vi.fn(), listMunicipalities: vi.fn() }))
vi.mock('@/services/territoryService', () => ({ territoryService: territory }))

function sample(over: Partial<MunicipalityMetrics> = {}): MunicipalityMetrics {
  return {
    period: { from: '2026-11-10T00:00:00+00:00', to: '2026-11-16T12:00:00+00:00', seconds: 100000 },
    top_gas_availability: [
      { station_id: 1, station: 'Posto A', code: '00001', municipality: 'Lobito', municipality_id: 1, seconds_with_gas: 14400, label: '4h', percent: 50 },
    ],
    top_requests: [
      { municipality_id: 1, municipality: 'Lobito', province: 'Benguela', stations_count: 2, seconds_with_gas: 14400, label_with_gas: '4h', avg_seconds_per_station: 7200, avg_label_per_station: '2h', requests: 12 },
    ],
    municipalities: [
      { municipality_id: 1, municipality: 'Lobito', province: 'Benguela', stations_count: 2, seconds_with_gas: 14400, label_with_gas: '4h', avg_seconds_per_station: 7200, avg_label_per_station: '2h', requests: 12 },
    ],
    totals: { stations: 2, municipalities: 1, requests: 12 },
    scope: { type: 'national', province: null, can_pick_province: true },
    ...over,
  }
}

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/admin/metricas-municipios']}>
        <Routes>
          <Route path="/admin/metricas-municipios" element={<AdminMunicipalityMetrics />} />
          <Route path="/admin" element={<div>Dashboard</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Métricas por município (Novembro 2026)', () => {
  beforeEach(() => {
    svc.municipalityMetrics.mockReset()
    territory.listProvinces.mockResolvedValue([{ id: 1, name: 'Benguela' }])
    territory.listMunicipalities.mockResolvedValue([{ id: 1, name: 'Lobito' }])
    auth.user = { id: 1, role: 'SUPER_ADMIN', name: 'Ardev', province: null }
  })

  it('mostra os postos com mais gás e os municípios com mais consultas', async () => {
    svc.municipalityMetrics.mockResolvedValue(sample())
    renderPage()

    expect(await screen.findByText('Posto A')).toBeInTheDocument()
    expect(screen.getByText('Postos que mais disponibilizam gás')).toBeInTheDocument()
    expect(screen.getByText('Municípios com mais requisições')).toBeInTheDocument()
    // Pediu 7 dias por omissão.
    await waitFor(() => expect(svc.municipalityMetrics).toHaveBeenCalledWith(expect.objectContaining({ period: '7d' })))
  })

  it('o período muda o pedido ao servidor', async () => {
    svc.municipalityMetrics.mockResolvedValue(sample())
    const user = userEvent.setup()
    renderPage()
    await screen.findByText('Posto A')

    await user.click(screen.getByRole('button', { name: 'Hoje' }))
    await waitFor(() => expect(svc.municipalityMetrics).toHaveBeenCalledWith(expect.objectContaining({ period: 'today' })))
  })

  it('o intervalo à escolha só pede depois das duas datas', async () => {
    svc.municipalityMetrics.mockResolvedValue(sample())
    const user = userEvent.setup()
    renderPage()
    await screen.findByText('Posto A')
    svc.municipalityMetrics.mockClear()

    await user.click(screen.getByRole('button', { name: 'Escolher datas' }))
    expect(screen.getByText(/Escolha as duas datas/)).toBeInTheDocument()
    expect(svc.municipalityMetrics).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText('De'), '2026-11-01')
    await user.type(screen.getByLabelText('Até'), '2026-11-10')
    await waitFor(() => expect(svc.municipalityMetrics).toHaveBeenCalledWith(expect.objectContaining({ period: 'custom', from: '2026-11-01', to: '2026-11-10' })))
  })

  it('o Admin comum é reencaminhado', async () => {
    auth.user = { id: 2, role: 'ADMIN', name: 'Admin', province: 'Benguela' }
    renderPage()
    expect(await screen.findByText('Dashboard')).toBeInTheDocument()
    expect(svc.municipalityMetrics).not.toHaveBeenCalled()
  })

  it('o Provincial não vê o filtro de província', async () => {
    auth.user = { id: 3, role: 'PROVINCE_SUPER_ADMIN', name: 'PSA', province: 'Benguela' }
    svc.municipalityMetrics.mockResolvedValue(sample({ scope: { type: 'province', province: 'Benguela', can_pick_province: false } }))
    renderPage()
    await screen.findByText('Posto A')
    expect(screen.queryByText('Província')).not.toBeInTheDocument()
  })
})
