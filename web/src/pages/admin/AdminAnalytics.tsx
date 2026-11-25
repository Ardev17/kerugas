import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  LineChart, Line, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts'
import { adminService } from '@/services/adminService'
import { theme } from '@/constants/theme'

const PIE_COLORS = [theme.success, theme.danger, theme.warning]

function formatDay(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' })
}

export default function AdminAnalytics() {
  const { data, isLoading } = useQuery({ queryKey: ['admin-analytics'], queryFn: adminService.analytics })

  if (isLoading || !data) {
    return <div className="text-gray">A carregar analytics...</div>
  }

  const searches = data.searches_by_day.map((d) => ({ ...d, day: formatDay(d.date) }))
  const orders = data.orders_by_day.map((d) => ({ ...d, day: formatDay(d.date) }))

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-xl font-bold text-dark">Analytics</h1>
        {data.scope.type === 'province' && (
          <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
            {data.scope.province
              ? <>A mostrar dados da província de <strong className="text-dark">{data.scope.province}</strong></>
              : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin.'}
          </p>
        )}
      </div>

      <ChartCard title="Consultas de gás por dia (últimos 14 dias)">
        <ResponsiveContainer width="100%" height={260}>
          <LineChart data={searches}>
            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
            <XAxis dataKey="day" fontSize={12} stroke={theme.gray} />
            <YAxis fontSize={12} stroke={theme.gray} allowDecimals={false} />
            <Tooltip />
            <Line type="monotone" dataKey="total" stroke={theme.primary} strokeWidth={2} dot={false} name="Consultas" />
          </LineChart>
        </ResponsiveContainer>
      </ChartCard>

      <ChartCard title="Pedidos de gás por dia (últimos 14 dias)">
        <ResponsiveContainer width="100%" height={260}>
          <BarChart data={orders}>
            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
            <XAxis dataKey="day" fontSize={12} stroke={theme.gray} />
            <YAxis fontSize={12} stroke={theme.gray} allowDecimals={false} />
            <Tooltip />
            <Bar dataKey="total" fill={theme.secondary} radius={[4, 4, 0, 0]} name="Pedidos" />
          </BarChart>
        </ResponsiveContainer>
      </ChartCard>

      <div className="grid gap-6 md:grid-cols-2">
        <ChartCard title="Disponibilidade de gás nos postos">
          <ResponsiveContainer width="100%" height={240}>
            <PieChart>
              <Pie data={data.stations_by_gas_availability} dataKey="value" nameKey="name" outerRadius={80} label>
                {data.stations_by_gas_availability.map((_, i) => (
                  <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />
                ))}
              </Pie>
              <Tooltip />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </ChartCard>

        <ChartCard title="Municípios mais pesquisados">
          {data.top_municipalities_searched.length === 0 ? (
            <p className="flex h-[240px] items-center justify-center text-sm text-gray">Ainda sem dados suficientes.</p>
          ) : (
            <ResponsiveContainer width="100%" height={240}>
              <BarChart data={data.top_municipalities_searched} layout="vertical">
                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                <XAxis type="number" fontSize={12} stroke={theme.gray} allowDecimals={false} />
                <YAxis type="category" dataKey="name" fontSize={12} stroke={theme.gray} width={100} />
                <Tooltip />
                <Bar dataKey="total" fill={theme.primary} radius={[0, 4, 4, 0]} name="Consultas" />
              </BarChart>
            </ResponsiveContainer>
          )}
        </ChartCard>
      </div>
    </div>
  )
}

function ChartCard({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-5">
      <p className="mb-4 font-semibold text-dark">{title}</p>
      {children}
    </div>
  )
}
