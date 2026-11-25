import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { InvestorBilling } from '../InvestorBilling'
import { presetRange } from '@/lib/investor'
import type { InvestorOverview } from '@/services/investorService'

function overview(over: Partial<InvestorOverview> = {}): InvestorOverview {
  return {
    municipalities: [
      { id: 1, name: 'Lobito', province: 'Benguela', percentage: 10, since: '2026-10-01T00:00:00Z' },
      { id: 2, name: 'Catumbela', province: 'Benguela', percentage: 12.5, since: '2026-10-05T00:00:00Z' },
    ],
    selected_municipality_id: null,
    range: { from: '2026-10-01', to: '2026-10-13' },
    summary: { orders: 3, bottles: 4, collected_total: 30000, commission_total: 5000, share_total: 550, pending_orders: 0 },
    by_municipality: [
      { municipality_id: 1, name: 'Lobito', orders: 2, collected_total: 18000, commission_total: 3000, share_total: 300 },
      { municipality_id: 2, name: 'Catumbela', orders: 1, collected_total: 12000, commission_total: 2000, share_total: 250 },
    ],
    daily: [{ date: '2026-10-12', orders: 3, collected: 30000, commission: 5000, share: 550 }],
    periods: [],
    rule: 'A sua parte é a sua percentagem sobre a comissão do KeruGás.',
    ...over,
  }
}

const load = vi.fn()

const onExport = vi.fn()

function renderIt() {
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><InvestorBilling queryKey={['t']} load={load} onExport={onExport} /></QueryClientProvider>)
}

describe('Faturação do investidor', () => {
  beforeEach(() => load.mockReset().mockResolvedValue(overview()))

  it('mostra total arrecadado, comissão e a sua parte, com o detalhe por município', async () => {
    renderIt()
    expect(await screen.findByText('Total arrecadado')).toBeInTheDocument()
    const summary = screen.getByRole('region', { name: 'Resumo' })
    expect(summary).toHaveTextContent(/30.?000 Kz/)
    expect(summary).toHaveTextContent(/5.?000 Kz/)
    expect(summary).toHaveTextContent(/550 Kz/)
    expect(screen.getByRole('table')).toHaveTextContent('Catumbela')
  })

  it('filtra por um município', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Catumbela · 12,5%/ }))
    await waitFor(() => expect(load).toHaveBeenLastCalledWith(expect.objectContaining({ municipality_id: 2 })))
  })

  it('muda o período', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Mês passado' }))
    const r = presetRange('last_month')!
    await waitFor(() => expect(load).toHaveBeenLastCalledWith(expect.objectContaining({ from: r.from, to: r.to })))
  })

  it('sem municípios mostra o aviso', async () => {
    load.mockResolvedValue(overview({ municipalities: [], by_municipality: [] }))
    renderIt()
    expect(await screen.findByText('Ainda não está associado a nenhum município')).toBeInTheDocument()
  })

  it('períodos: o mês passado vai do dia 1 ao último dia', () => {
    expect(presetRange('last_month', new Date(2026, 9, 13))).toEqual({ from: '2026-09-01', to: '2026-09-30' })
    expect(presetRange('month', new Date(2026, 9, 13))).toEqual({ from: '2026-10-01', to: '2026-10-13' })
  })

  it('exporta em Excel e PDF o município e o período que estão no ecrã', async () => {
    onExport.mockReset().mockResolvedValue(undefined)
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Catumbela · 12,5%/ }))
    await userEvent.click(screen.getByRole('button', { name: /Excel/ }))
    const r = presetRange('month')!
    await waitFor(() => expect(onExport).toHaveBeenCalledWith('csv', { municipality_id: 2, from: r.from, to: r.to }))
    await userEvent.click(screen.getByRole('button', { name: /PDF/ }))
    await waitFor(() => expect(onExport).toHaveBeenLastCalledWith('pdf', expect.objectContaining({ municipality_id: 2 })))
  })
})
