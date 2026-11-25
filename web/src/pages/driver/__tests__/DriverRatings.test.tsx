import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import DriverRatings from '../DriverRatings'
import type { DriverRatingsPage } from '@/types'

const svc = vi.hoisted(() => ({ getRatings: vi.fn() }))
vi.mock('@/services/driverService', () => ({ driverService: svc }))

const summary = {
  average: 4.25, count: 4, with_comment: 2,
  distribution: { '5': 2, '4': 1, '3': 0, '2': 1, '1': 0 },
  last_30_days: { average: 4.5, count: 2 },
}

function page(data: DriverRatingsPage['data'], current = 1, last = 1): DriverRatingsPage {
  return { data, meta: { current_page: current, last_page: last, total: 4 }, summary }
}

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><DriverRatings /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('As minhas avaliações (condutor)', () => {
  beforeEach(() => svc.getRatings.mockReset())

  it('mostra o resumo, a tendência e cada avaliação com o comentário e o dia', async () => {
    svc.getRatings.mockResolvedValue(page([
      { id: 2, rating: 5, comment: 'Muito rápido, obrigado!', date: '2026-10-05' },
      { id: 1, rating: 2, comment: null, date: '2026-09-01' },
    ]))
    renderPage()

    const resumo = await screen.findByRole('region', { name: 'Resumo das avaliações' })
    expect(within(resumo).getByText('4,3')).toBeInTheDocument()
    expect(within(resumo).getByText('4 avaliações')).toBeInTheDocument()
    expect(within(resumo).getByText('4,5')).toBeInTheDocument() // últimos 30 dias
    expect(within(resumo).getByText(/2 com comentário/)).toBeInTheDocument()

    expect(screen.getByText('“Muito rápido, obrigado!”')).toBeInTheDocument()
    expect(screen.getByText('Sem comentário')).toBeInTheDocument()
    // só o dia (sem hora), no formato local; o atributo guarda a data exata
    const times = document.querySelectorAll('time')
    expect([...times].map((t) => t.getAttribute('datetime'))).toEqual(['2026-10-05', '2026-09-01'])
    expect(times[0].textContent).toMatch(/05/)
    expect(times[0].textContent).toMatch(/2026/)
    expect(times[0].textContent).not.toMatch(/:/)
    expect(screen.getByText(/não mostramos o nome do cliente/)).toBeInTheDocument()
  })

  it('tocar numa barra filtra por estrelas; tocar de novo volta a todas', async () => {
    svc.getRatings.mockImplementation(async (_page: number, stars?: number) =>
      stars === 2 ? page([{ id: 1, rating: 2, comment: 'Atrasado', date: '2026-09-01' }]) : page([{ id: 2, rating: 5, comment: 'Top', date: '2026-10-05' }]))
    renderPage()

    const bar = await screen.findByRole('button', { name: '2 estrelas: 1' })
    await userEvent.click(bar)
    expect(svc.getRatings).toHaveBeenLastCalledWith(1, 2)
    expect(await screen.findByText('“Atrasado”')).toBeInTheDocument()
    expect(screen.getByText('Avaliações de 2 estrelas')).toBeInTheDocument()
    expect(bar).toHaveAttribute('aria-pressed', 'true')

    // barras sem avaliações não são clicáveis
    expect(screen.getByRole('button', { name: '3 estrelas: 0' })).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'Ver todas' }))
    expect(await screen.findByText('“Top”')).toBeInTheDocument()
  })

  it('"Ver mais" carrega a página seguinte', async () => {
    svc.getRatings
      .mockResolvedValueOnce(page([{ id: 3, rating: 5, comment: 'Primeira página', date: '2026-10-05' }], 1, 2))
      .mockResolvedValueOnce(page([{ id: 1, rating: 4, comment: 'Segunda página', date: '2026-09-01' }], 2, 2))
    renderPage()

    await userEvent.click(await screen.findByRole('button', { name: 'Ver mais avaliações' }))
    expect(await screen.findByText('“Segunda página”')).toBeInTheDocument()
    expect(screen.getByText('“Primeira página”')).toBeInTheDocument()
    expect(svc.getRatings).toHaveBeenLastCalledWith(2, undefined)
  })

  it('sem avaliações explica como elas chegam', async () => {
    svc.getRatings.mockResolvedValue({
      data: [], meta: { current_page: 1, last_page: 1, total: 0 },
      summary: { average: null, count: 0, with_comment: 0, distribution: { '5': 0, '4': 0, '3': 0, '2': 0, '1': 0 }, last_30_days: { average: null, count: 0 } },
    })
    renderPage()
    expect(await screen.findByText('Ainda não tem avaliações')).toBeInTheDocument()
  })
})
