import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminMetrics from '../AdminMetrics'
import type { SuperAdminMetrics } from '@/types'

const auth = vi.hoisted(() => ({ user: { role: 'SUPER_ADMIN' } as { role: string } | null }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

const metrics = vi.hoisted(() => vi.fn())
vi.mock('@/services/adminService', () => ({ adminService: { metrics: metrics } }))

// jsdom não tem layout: o ResponsiveContainer do Recharts ficaria com 0 px.
vi.mock('recharts', async (importOriginal) => {
  const actual = await importOriginal<typeof import('recharts')>()
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: React.ReactElement }) => <div style={{ width: 600, height: 300 }}>{children}</div>,
  }
})

const sample = (over: Partial<SuperAdminMetrics> = {}): SuperAdminMetrics => ({
  period: { days: 30, since: '2026-09-04', until: '2026-10-03' },
  orders: {
    total: 40, delivered: 30, cancelled: 4, failed: 0, in_progress: 6,
    completion_rate: 75, cancellation_rate: 10, revenue: 75000, average_ticket: 2500,
    average_minutes_to_contact: 12, average_minutes_to_deliver: 95,
  },
  previous_orders: { total: 20, delivered: 30, revenue: 0 },
  orders_by_day: [
    { date: '2026-10-01', total: 3, delivered: 2, cancelled: 1 },
    { date: '2026-10-02', total: 5, delivered: 4, cancelled: 0 },
  ],
  orders_by_status: [{ status: 'DELIVERED', label: 'Entregue', total: 30 }],
  orders_by_province: [{ name: 'Huíla', total: 25, delivered: 20 }],
  funnel: [
    { key: 'created', label: 'Pedidos feitos', total: 40 },
    { key: 'contacted', label: 'Contactados', total: 36 },
    { key: 'confirmed', label: 'Confirmados', total: 32 },
    { key: 'delivered', label: 'Entregues', total: 30 },
  ],
  drivers: { total: 5, available: 3, top: [{ id: 1, name: 'Paulo Condutor', delivered: 12, cancelled: 1, average_rating: 4.5, ratings_count: 8 }] },
  ratings: { count: 8, average: 4.5, distribution: [1, 2, 3, 4, 5].map((stars) => ({ stars, total: stars === 5 ? 5 : stars === 4 ? 3 : 0 })) },
  stations: { active: 20, with_gas: 12, without_gas: 8, stale: 3 },
  users: { total: 300, new_in_period: 40, by_role: [{ role: 'USER', label: 'Utilizador', total: 280 }] },
  notifications: { sent: 500, read_rate: 62.5, orders_sent: 120, web_push_subscriptions: 14, order_push_subscriptions: 6, device_tokens: 9 },
  ...over,
})

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/admin/metrics']}>
        <Routes>
          <Route path="/admin/metrics" element={<AdminMetrics />} />
          <Route path="/admin" element={<p>Dashboard do admin</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  auth.user = { role: 'SUPER_ADMIN' }
  metrics.mockResolvedValue(sample())
})

describe('AdminMetrics', () => {
  it('mostra os KPIs, o funil, os condutores e a saúde das notificações', async () => {
    renderPage()

    expect(await screen.findByText('Pedidos feitos')).toBeTruthy()
    expect(screen.getByText('1 h 35 min')).toBeTruthy()           // tempo até à entrega
    expect(screen.getByText('12 min')).toBeTruthy()               // até ao 1.º contacto
    expect(screen.getByText(/Conclusão: 75%/)).toBeTruthy()
    expect(screen.getByText('Paulo Condutor')).toBeTruthy()
    expect(screen.getByText('4,5 (8)')).toBeTruthy()
    expect(screen.getByText('Navegadores a seguir pedidos')).toBeTruthy()
    expect(screen.getByText('62,5%')).toBeTruthy()
    expect(metrics).toHaveBeenCalledWith(30)
  })

  it('tendência face ao período anterior; a partir de zero diz "novo" em vez de uma percentagem enganadora', async () => {
    renderPage()
    await screen.findByText('Pedidos feitos')

    // Pedidos: 40 vs 20 → +100%.  Faturação: 75 000 vs 0 → "novo".
    expect(screen.getByText('+100%')).toBeTruthy()
    expect(screen.getByText('novo')).toBeTruthy()
    // Entregues: 30 vs 30 → 0%.
    expect(screen.getByText('0%')).toBeTruthy()
  })

  it('mudar o período volta a pedir os dados com esse período', async () => {
    renderPage()
    await screen.findByText('Pedidos feitos')

    fireEvent.click(screen.getByRole('button', { name: '7 dias' }))

    await waitFor(() => expect(metrics).toHaveBeenLastCalledWith(7))
    expect(screen.getByRole('button', { name: '7 dias' }).getAttribute('aria-pressed')).toBe('true')
  })

  it('sem dados nenhum não rebenta e mostra traços em vez de NaN', async () => {
    metrics.mockResolvedValue(sample({
      orders: {
        total: 0, delivered: 0, cancelled: 0, failed: 0, in_progress: 0, completion_rate: null, cancellation_rate: null,
        revenue: 0, average_ticket: null, average_minutes_to_contact: null, average_minutes_to_deliver: null,
      },
      previous_orders: { total: 0, delivered: 0, revenue: 0 },
      funnel: [{ key: 'created', label: 'Pedidos feitos', total: 0 }],
      orders_by_province: [],
      drivers: { total: 0, available: 0, top: [] },
      ratings: { count: 0, average: null, distribution: [] },
      notifications: { sent: 0, read_rate: null, orders_sent: 0, web_push_subscriptions: 0, order_push_subscriptions: 0, device_tokens: 0 },
    }))
    renderPage()

    await screen.findByText('Pedidos feitos')
    expect(screen.getByText(/Ainda sem entregas atribuídas/)).toBeTruthy()
    expect(document.body.textContent).not.toMatch(/NaN|undefined|Infinity/)
  })

  it('erro de rede: avisa e deixa tentar de novo', async () => {
    metrics.mockRejectedValueOnce(new Error('rede'))
    renderPage()

    const alert = await screen.findByRole('alert')
    metrics.mockResolvedValue(sample())
    fireEvent.click(within(alert).getByRole('button', { name: /tentar novamente/i }))

    expect(await screen.findByText('Pedidos feitos')).toBeTruthy()
  })

  it('quem não é Super Admin é reencaminhado e não chega a pedir os dados', async () => {
    auth.user = { role: 'ADMIN' }
    renderPage()

    expect(await screen.findByText('Dashboard do admin')).toBeTruthy()
    expect(metrics).not.toHaveBeenCalled()
  })
})
