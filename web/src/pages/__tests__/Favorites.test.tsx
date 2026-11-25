import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import Favorites from '../Favorites'
import type { FavoriteList } from '@/services/favoriteService'

const svc = vi.hoisted(() => ({ list: vi.fn(), remove: vi.fn(), add: vi.fn() }))
vi.mock('@/services/favoriteService', () => ({ favoriteService: svc }))

const posto = {
  id: 7, name: 'Posto Caxila', province: 'Benguela', municipality: 'Lobito',
  address: 'Rua 1', latitude: -12.3, longitude: 13.5, gas_available: true,
  queue_level: 'LOW', confidence_score: 80, status_updated_at: null, cylinder_types: '12kg',
  phone: null, opening_hours: null, photo_url: null, distance_km: null,
}

function lista(over: Partial<FavoriteList> = {}): FavoriteList {
  return { favorites: [], hiddenCount: 0, blocked: [], accessBlock: null, ...over }
}

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><Favorites /></MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => vi.clearAllMocks())

describe('Favorites', () => {
  it('sem favoritos nenhuns, mostra a mensagem de sempre', async () => {
    svc.list.mockResolvedValue(lista())
    renderPage()

    expect(await screen.findByText(/Ainda não tem postos favoritos/)).toBeInTheDocument()
    expect(screen.queryByText(/assinantes/)).not.toBeInTheDocument()
  })

  it('um favorito escondido por zona fechada é explicado, não omitido em silêncio', async () => {
    svc.list.mockResolvedValue(lista({
      hiddenCount: 1,
      blocked: [{ id: 3, name: 'Lobito', reason: 'Só para assinantes.' }],
    }))
    renderPage()

    expect(await screen.findByRole('heading', { name: /Lobito: pague a subscrição, para ver os dados/ })).toBeInTheDocument()
    expect(screen.getByText(/Um dos seus favoritos não está a ser mostrado/)).toBeInTheDocument()
    expect(screen.getByText(/Continuam guardados/)).toBeInTheDocument()
    // e não cai na mensagem de "não tem favoritos", que seria mentira
    expect(screen.queryByText(/Ainda não tem postos favoritos/)).not.toBeInTheDocument()
  })

  it('vários escondidos: o número bate certo', async () => {
    svc.list.mockResolvedValue(lista({ hiddenCount: 3, blocked: [{ id: 3, name: 'Lobito', reason: '' }] }))
    renderPage()

    expect(await screen.findByText(/3 dos seus favoritos não estão a ser mostrados/)).toBeInTheDocument()
  })

  it('conta bloqueada: culpa a conta, não a zona', async () => {
    svc.list.mockResolvedValue(lista({
      hiddenCount: 2,
      accessBlock: { reason: 'user_blocked', message: 'A sua conta está sem acesso à consulta dos postos.' },
    }))
    renderPage()

    expect(await screen.findByRole('heading', { name: /A sua conta está sem acesso/ })).toBeInTheDocument()
  })

  it('favoritos visíveis aparecem normalmente, sem aviso nenhum', async () => {
    svc.list.mockResolvedValue(lista({ favorites: [{ id: 1, station: posto, created_at: '2026-11-19T10:00:00Z' }] as never }))
    renderPage()

    expect(await screen.findByText('Posto Caxila')).toBeInTheDocument()
    expect(screen.queryByText(/não está a ser mostrado/)).not.toBeInTheDocument()
  })
})
