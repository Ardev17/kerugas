import { useState, type ReactNode } from 'react'
import { Navigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import {
  LineChart, Line, BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts'
import { ArrowDownRight, ArrowUpRight, Minus, Star } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { useAuth } from '@/hooks/useAuth'
import { theme } from '@/constants/theme'
import { formatKz, formatMinutes, formatPercent, trend, type Trend } from '@/lib/metrics'
import type { SuperAdminMetrics } from '@/types'
import { hasSuperPowers, isProvinceSuperAdmin } from '@/lib/roles'

const PERIODS = [7, 30, 90] as const
type Period = (typeof PERIODS)[number]

function formatDay(dateStr: string) {
  return new Date(`${dateStr}T00:00:00`).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' })
}

/**
 * Painel de métricas do Super Admin (Outubro 2026): o negócio inteiro numa
 * página. O servidor também recusa (403) a qualquer outro papel,esta
 * guarda só evita mostrar uma página vazia a quem chegar aqui por link.
 * Definições dos números: ver SuperAdminMetricsController.
 */
export default function AdminMetrics() {
  const { user } = useAuth()
  const [days, setDays] = useState<Period>(30)

  const isSuperAdmin = hasSuperPowers(user)
  const { data, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin-metrics', days],
    queryFn: () => adminService.metrics(days),
    enabled: isSuperAdmin,
    staleTime: 60_000,
  })

  if (user && !isSuperAdmin) return <Navigate to="/admin" replace />

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-dark">Métricas</h1>
          <p className="text-sm text-gray">
            {isProvinceSuperAdmin(user)
              ? <>Visão da província de <strong className="text-dark">{user?.province ?? '—'}</strong>,Super Admin Provincial.</>
              : 'Visão geral da plataforma,só visível para o Super Admin.'}
          </p>
        </div>
        <div role="group" aria-label="Período" className="flex rounded-lg border border-gray/20 bg-white p-0.5">
          {PERIODS.map((p) => (
            <button
              key={p}
              type="button"
              onClick={() => setDays(p)}
              aria-pressed={days === p}
              className={`rounded-md px-3 py-1.5 text-sm font-medium ${days === p ? 'bg-primary text-white' : 'text-gray hover:text-dark'}`}
            >
              {p} dias
            </button>
          ))}
        </div>
      </div>

      {isLoading && <p className="text-gray">A carregar métricas...</p>}

      {isError && (
        <div role="alert" className="rounded-xl border border-danger/30 bg-danger/5 p-4 text-sm text-dark">
          Não foi possível carregar as métricas.{' '}
          <button type="button" onClick={() => void refetch()} className="font-semibold text-primary underline">
            Tentar novamente
          </button>
        </div>
      )}

      {data && <MetricsBody data={data} />}
    </div>
  )
}

function MetricsBody({ data }: { data: SuperAdminMetrics }) {
  const { orders, previous_orders: previous } = data
  const byDay = data.orders_by_day.map((d) => ({ ...d, day: formatDay(d.date) }))
  const funnelMax = Math.max(...data.funnel.map((f) => f.total), 1)

  return (
    <>
      <section aria-label="Pedidos" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Kpi label="Pedidos" value={String(orders.total)} trend={trend(orders.total, previous.total)} />
        <Kpi label="Entregues" value={String(orders.delivered)} trend={trend(orders.delivered, previous.delivered)} hint={`Conclusão: ${formatPercent(orders.completion_rate)}`} />
        <Kpi label="Faturação" value={formatKz(orders.revenue)} trend={trend(orders.revenue, previous.revenue)} hint={orders.average_ticket !== null ? `Média: ${formatKz(orders.average_ticket)}` : undefined} />
        {orders.commission !== undefined && (
          <Kpi label="Comissão KeruGas" value={formatKz(orders.commission)} hint="Entregas com divisão registada" />
        )}
        <Kpi label="Cancelados" value={String(orders.cancelled)} hint={`Cancelamento: ${formatPercent(orders.cancellation_rate)}`} />
        <Kpi label="Em curso" value={String(orders.in_progress)} />
        <Kpi label="Até ao 1.º contacto" value={formatMinutes(orders.average_minutes_to_contact)} hint="Tempo médio" />
        <Kpi label="Até à entrega" value={formatMinutes(orders.average_minutes_to_deliver)} hint="Tempo médio" />
        <Kpi
          label="Avaliação dos condutores"
          value={data.ratings.average !== null ? `${data.ratings.average.toLocaleString('pt-PT')} / 5` : '—'}
          hint={`${data.ratings.count} avaliações`}
        />
      </section>

      <ChartCard title={`Pedidos por dia (últimos ${data.period.days} dias)`}>
        <ResponsiveContainer width="100%" height={260}>
          <LineChart data={byDay}>
            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
            <XAxis dataKey="day" fontSize={12} stroke={theme.gray} interval="preserveStartEnd" minTickGap={24} />
            <YAxis fontSize={12} stroke={theme.gray} allowDecimals={false} />
            <Tooltip />
            <Legend />
            <Line type="monotone" dataKey="total" name="Pedidos" stroke={theme.primary} strokeWidth={2} dot={false} />
            <Line type="monotone" dataKey="delivered" name="Entregues" stroke={theme.success} strokeWidth={2} dot={false} />
            <Line type="monotone" dataKey="cancelled" name="Cancelados" stroke={theme.danger} strokeWidth={2} dot={false} />
          </LineChart>
        </ResponsiveContainer>
      </ChartCard>

      <div className="grid gap-6 lg:grid-cols-2">
        <ChartCard title="Funil: onde se perdem pedidos">
          <ul className="space-y-3">
            {data.funnel.map((step) => (
              <li key={step.key}>
                <div className="flex justify-between text-sm">
                  <span className="text-dark">{step.label}</span>
                  <span className="font-semibold text-dark">
                    {step.total}
                    {data.funnel[0].total > 0 && (
                      <span className="ml-1 font-normal text-gray">({Math.round((step.total / data.funnel[0].total) * 100)}%)</span>
                    )}
                  </span>
                </div>
                <div className="mt-1 h-2.5 overflow-hidden rounded-full bg-gray/10">
                  <div className="h-full rounded-full bg-primary" style={{ width: `${(step.total / funnelMax) * 100}%` }} />
                </div>
              </li>
            ))}
          </ul>
        </ChartCard>

        <ChartCard title="Pedidos por estado">
          <ResponsiveContainer width="100%" height={300}>
            <BarChart data={data.orders_by_status} layout="vertical">
              <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
              <XAxis type="number" fontSize={12} stroke={theme.gray} allowDecimals={false} />
              {/* interval={0}: mostra TODOS os estados (por omissão o Recharts salta nomes). */}
              <YAxis type="category" dataKey="label" fontSize={12} stroke={theme.gray} width={96} interval={0} />
              <Tooltip />
              <Bar dataKey="total" name="Pedidos" fill={theme.secondary} radius={[0, 4, 4, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </ChartCard>
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <ChartCard title="Províncias com mais pedidos">
          {data.orders_by_province.length === 0 ? (
            <Empty />
          ) : (
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={data.orders_by_province} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                <XAxis type="number" fontSize={12} stroke={theme.gray} allowDecimals={false} />
                <YAxis type="category" dataKey="name" fontSize={12} stroke={theme.gray} width={96} interval={0} />
                <Tooltip />
                <Legend />
                <Bar dataKey="total" name="Pedidos" fill={theme.primary} radius={[0, 4, 4, 0]} />
                <Bar dataKey="delivered" name="Entregues" fill={theme.success} radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </ChartCard>

        <ChartCard title="Avaliações dos condutores">
          {data.ratings.count === 0 ? (
            <Empty />
          ) : (
            <ul className="space-y-2">
              {[...data.ratings.distribution].reverse().map((row) => (
                <li key={row.stars} className="flex items-center gap-3 text-sm">
                  <span className="flex w-10 items-center gap-0.5 text-dark">
                    {row.stars} <Star size={13} className="fill-amber-400 text-amber-400" />
                  </span>
                  <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-gray/10">
                    <div className="h-full rounded-full bg-amber-400" style={{ width: `${(row.total / Math.max(data.ratings.count, 1)) * 100}%` }} />
                  </div>
                  <span className="w-8 text-right text-gray">{row.total}</span>
                </li>
              ))}
            </ul>
          )}
        </ChartCard>
      </div>

      <ChartCard title={`Condutores (${data.drivers.available} disponíveis de ${data.drivers.total})`}>
        {data.drivers.top.length === 0 ? (
          <Empty text="Ainda sem entregas atribuídas neste período." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray/15 text-xs uppercase tracking-wide text-gray">
                  <th className="py-2 pr-3 font-medium">Condutor</th>
                  <th className="py-2 pr-3 text-right font-medium">Entregas</th>
                  <th className="py-2 pr-3 text-right font-medium">Cancelados</th>
                  <th className="py-2 text-right font-medium">Avaliação</th>
                </tr>
              </thead>
              <tbody>
                {data.drivers.top.map((d) => (
                  <tr key={d.id} className="border-b border-gray/10 last:border-0">
                    <td className="py-2 pr-3 font-medium text-dark">{d.name}</td>
                    <td className="py-2 pr-3 text-right text-dark">{d.delivered}</td>
                    <td className="py-2 pr-3 text-right text-dark">{d.cancelled}</td>
                    <td className="py-2 text-right text-dark">
                      {d.average_rating !== null ? `${d.average_rating.toLocaleString('pt-PT')} (${d.ratings_count})` : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </ChartCard>

      <section aria-label="Plataforma" className="grid gap-6 lg:grid-cols-3">
        <ChartCard title="Postos">
          <Stats
            rows={[
              ['Ativos', data.stations.active],
              ['Com gás', data.stations.with_gas],
              ['Sem gás', data.stations.without_gas],
              ['Sem atualização há +3 h', data.stations.stale],
            ]}
          />
        </ChartCard>

        <ChartCard title="Utilizadores">
          <Stats
            rows={[
              ['Total', data.users.total],
              [`Novos (${data.period.days} dias)`, data.users.new_in_period],
              ...data.users.by_role.map((r): [string, number] => [r.label, r.total]),
            ]}
          />
        </ChartCard>

        <ChartCard title="Notificações">
          <Stats
            rows={[
              ['Enviadas no período', data.notifications.sent],
              ['De pedidos', data.notifications.orders_sent],
              ['Taxa de leitura', formatPercent(data.notifications.read_rate)],
              ['Navegadores com push (conta)', data.notifications.web_push_subscriptions],
              ['Navegadores a seguir pedidos', data.notifications.order_push_subscriptions],
              ['Telemóveis com a app', data.notifications.device_tokens],
            ]}
          />
        </ChartCard>
      </section>
    </>
  )
}

function Kpi({ label, value, hint, trend: t }: { label: string; value: string; hint?: string; trend?: Trend }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <p className="text-xs text-gray">{label}</p>
      <p className="mt-1 text-xl font-bold text-dark">{value}</p>
      <div className="mt-1 flex items-center gap-2 text-xs">
        {t && <TrendBadge trend={t} />}
        {hint && <span className="text-gray">{hint}</span>}
      </div>
    </div>
  )
}

function TrendBadge({ trend: t }: { trend: Trend }) {
  if (t.direction === 'none') return null
  if (t.percent === null) {
    return <span className="rounded bg-primary/10 px-1.5 py-0.5 font-medium text-primary">novo</span>
  }
  const Icon = t.direction === 'up' ? ArrowUpRight : t.direction === 'down' ? ArrowDownRight : Minus
  const color = t.direction === 'up' ? 'text-success' : t.direction === 'down' ? 'text-danger' : 'text-gray'
  return (
    <span className={`inline-flex items-center gap-0.5 font-medium ${color}`} title="Face ao período anterior">
      <Icon size={13} /> {t.percent > 0 ? '+' : ''}{t.percent}%
    </span>
  )
}

function Stats({ rows }: { rows: [string, number | string][] }) {
  return (
    <dl className="space-y-2 text-sm">
      {rows.map(([label, value]) => (
        <div key={label} className="flex justify-between gap-3">
          <dt className="text-gray">{label}</dt>
          <dd className="font-semibold text-dark">{value}</dd>
        </div>
      ))}
    </dl>
  )
}

function Empty({ text = 'Ainda sem dados suficientes.' }: { text?: string }) {
  return <p className="flex h-[120px] items-center justify-center text-sm text-gray">{text}</p>
}

function ChartCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-5">
      <p className="mb-4 font-semibold text-dark">{title}</p>
      {children}
    </div>
  )
}
