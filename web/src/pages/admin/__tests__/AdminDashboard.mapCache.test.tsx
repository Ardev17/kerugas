import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminDashboard from '../AdminDashboard'

const forMap = vi.hoisted(() => vi.fn())
vi.mock('@/services/stationService', () => ({ stationService: { forMap } }))
const resumo = {
  gas_orders: { delivered: 0, out_for_delivery: 0, pending: 0, today: 0 },
  reports: { open: 0, under_review: 0 },
  sms: { failed: 0, invalid: 0, processed: 0, total: 0 },
  stations: { active: 3, stale: 0, with_gas: 2, without_gas: 1 },
  users: { active: 1, total: 1 },
}
vi.mock('@/services/adminService', () => ({ adminService: { dashboard: vi.fn(async () => resumo) } }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: 'SUPER_ADMIN', province_id: null } }) }))

const posto = (id: number, gas: boolean) => ({ id, name: `P${id}`, latitude: -12.3, longitude: 13.5, gas_available: gas, queue_level: 'LOW' })

/**
 * O painel e o Mapa público partilham a chave 'map-stations'. Vindo do Mapa,
 * a cache já tem a resposta inteira ({ stations, blocked, accessBlock }),e o
 * painel rebentava com "(mapQuery.data ?? []).filter is not a function".
 */
describe('AdminDashboard com a cache do Mapa já preenchida', () => {
  it('lê a lista de dentro da resposta inteira e conta os postos', async () => {
    const lista = { stations: [posto(1, true), posto(2, false), posto(3, true)], blocked: [], accessBlock: null }
    forMap.mockResolvedValue(lista)
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, staleTime: Infinity } } })
    client.setQueryData(['map-stations'], lista)

    render(
      <QueryClientProvider client={client}>
        <MemoryRouter><AdminDashboard /></MemoryRouter>
      </QueryClientProvider>,
    )

    expect(await screen.findByText(/postos no mapa/)).toBeInTheDocument()
    expect(screen.getByText(/Com gás agora/)).toBeInTheDocument()
  })
})
