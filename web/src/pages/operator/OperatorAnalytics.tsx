import type { ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  LineChart, Line, BarChart, Bar, PieChart, Pie, Cell,
  XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts'
import { ShieldOff } from 'lucide-react'
import { operatorService } from '@/services/operatorService'
import { ExportControls } from '@/components/ExportControls'
import { OperatorSubscriptionCard } from '@/components/OperatorSubscriptionCard'
import { getApiErrorMessage } from '@/lib/api'
import { theme, queueLevelLabel as QUEUE_LABEL } from '@/constants/theme'

const PIE_COLORS = [theme.success, theme.danger]
const SOURCE_COLORS = [theme.primary, theme.secondary, theme.warning, theme.gray]

function formatDay(dateStr: string) {
  return new Date(dateStr).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' })
}

function formatDateTime(dateStr: string) {
  return new Date(dateStr).toLocaleString('pt-PT', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}

/**
 * Dashboard de analytics do Operador,mesma profundidade que o Admin
 * tem em /admin/analytics + /admin (KPIs), mas escopado apenas ao
 * posto do operador autenticado. O backend (OperatorStationController
 * ::analytics) já garante esse escopo,o frontend não precisa de
 * filtrar nada, só de apresentar bem o que recebe.
 */
export default function OperatorAnalytics() {
  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['operator-analytics'],
    queryFn: operatorService.analytics,
    retry: false, // um 403 (desativado pelo Super Admin) não se resolve tentando outra vez
  })

  if (isLoading) {
    return <div className="mx-auto max-w-4xl px-4 py-10 text-gray">A carregar analytics do seu posto...</div>
  }

  if (isError) {
    const message = getApiErrorMessage(error, 'Não foi possível carregar os dados do seu posto.')
    // Novembro 2026: o analytics vem com a subscrição que mantém o posto visível.
    const semSubscricao = message.includes('subscrição que mantém o seu posto visível')

    return (
      <div className="mx-auto max-w-md px-4 py-16 text-center">
        <ShieldOff className="mx-auto text-gray" size={32} />
        <p className="mt-4 font-medium text-dark">
          {semSubscricao ? 'O seu posto não está visível na aplicação' : 'Não foi possível carregar os dados do seu posto.'}
        </p>
        <p className="mt-1 text-sm text-gray">{message}</p>
        {semSubscricao && (
          <p className="mt-4 text-xs text-gray">
            Continua a poder actualizar o estado do posto normalmente,o que está fechado são estes gráficos, a
            exportação e a presença no mapa. Renove a subscrição para repor as três coisas.
          </p>
        )}
        {semSubscricao && <div className="text-left"><OperatorSubscriptionCard /></div>}
      </div>
    )
  }

  if (!data) {
    return null
  }

  const updatesByDay = data.updates_by_day.map((d) => ({ ...d, day: formatDay(d.date) }))
  const confidenceTrend = data.confidence_trend.map((d) => ({ ...d, label: formatDateTime(d.date) }))
  const hasEnoughData = data.kpis.total_updates_30d > 0

  return (
    <div className="mx-auto max-w-4xl space-y-8 px-4 py-10">
      <div>
        <h1 className="text-2xl font-bold text-dark">Analytics do meu posto</h1>
        <p className="mt-1 text-gray">Desempenho e histórico de atualizações dos últimos 30 dias.</p>
      </div>

      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
        <Kpi label="Atualizações (30 dias)" value={data.kpis.total_updates_30d} />
        <Kpi label="Atualizações hoje" value={data.kpis.updates_today} />
        <Kpi label="Confiança atual" value={`${data.kpis.current_confidence_score}%`} accent="primary" />
        <Kpi
          label="Confiança média (30d)"
          value={data.kpis.avg_confidence_score_30d !== null ? `${data.kpis.avg_confidence_score_30d}%` : '—'}
        />
        <Kpi
          label="% do tempo com gás"
          value={data.kpis.gas_available_percentage_30d !== null ? `${data.kpis.gas_available_percentage_30d}%` : '—'}
          accent="success"
        />
        <Kpi
          label="Fila mais comum"
          value={data.kpis.most_common_queue_level ? QUEUE_LABEL[data.kpis.most_common_queue_level] : '—'}
        />
      </div>

      <ExportControls
        onExport={(period, date, format) => operatorService.exportStationStatus({ period, date, format })}
      />

      {!hasEnoughData ? (
        <div className="rounded-xl border border-dashed border-gray/30 p-8 text-center">
          <p className="font-medium text-dark">Ainda sem dados suficientes.</p>
          <p className="mt-1 text-sm text-gray">
            Os gráficos aparecem aqui assim que começar a atualizar o estado do seu posto.
          </p>
        </div>
      ) : (
        <>
          <ChartCard title="Atualizações por dia (últimos 14 dias)">
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={updatesByDay}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                <XAxis dataKey="day" fontSize={12} stroke={theme.gray} />
                <YAxis fontSize={12} stroke={theme.gray} allowDecimals={false} />
                <Tooltip />
                <Line type="monotone" dataKey="total" stroke={theme.primary} strokeWidth={2} dot={false} name="Atualizações" />
              </LineChart>
            </ResponsiveContainer>
          </ChartCard>

          <ChartCard title="Confiança ao longo das últimas atualizações">
            <ResponsiveContainer width="100%" height={240}>
              <LineChart data={confidenceTrend}>
                <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                <XAxis dataKey="label" fontSize={11} stroke={theme.gray} height={50} />
                <YAxis fontSize={12} stroke={theme.gray} domain={[0, 100]} />
                <Tooltip />
                <Line type="monotone" dataKey="confidence_score" stroke={theme.secondary} strokeWidth={2} dot={{ r: 3 }} name="Confiança (%)" />
              </LineChart>
            </ResponsiveContainer>
          </ChartCard>

          <div className="grid gap-6 md:grid-cols-2">
            <ChartCard title="Disponibilidade (últimos 30 dias)">
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie data={data.gas_availability_ratio} dataKey="value" nameKey="name" outerRadius={75} label>
                    {data.gas_availability_ratio.map((_, i) => <Cell key={i} fill={PIE_COLORS[i % PIE_COLORS.length]} />)}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Distribuição da fila (últimos 30 dias)">
              <ResponsiveContainer width="100%" height={220}>
                <BarChart data={data.queue_level_distribution}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                  <XAxis dataKey="name" fontSize={11} stroke={theme.gray} />
                  <YAxis fontSize={12} stroke={theme.gray} allowDecimals={false} />
                  <Tooltip />
                  <Bar dataKey="value" fill={theme.primary} radius={[4, 4, 0, 0]} name="Atualizações" />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          </div>

          <ChartCard title="Origem das atualizações (últimos 30 dias)">
            {data.source_breakdown.length === 0 ? (
              <p className="flex h-[200px] items-center justify-center text-sm text-gray">Sem dados suficientes.</p>
            ) : (
              <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                  <Pie data={data.source_breakdown} dataKey="value" nameKey="name" outerRadius={75} label>
                    {data.source_breakdown.map((_, i) => <Cell key={i} fill={SOURCE_COLORS[i % SOURCE_COLORS.length]} />)}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            )}
            <p className="mt-3 text-xs text-gray">
              Mostra quanto do estado do seu posto veio de SMS, do painel do operador, ou de ajustes do Admin —
              útil para perceber se o código SMS está mesmo a ser usado.
            </p>
          </ChartCard>
        </>
      )}
    </div>
  )
}

const ACCENT_CLASS: Record<string, string> = {
  success: 'text-success',
  primary: 'text-primary',
  warning: 'text-warning',
  danger: 'text-danger',
}

function Kpi({ label, value, accent }: { label: string; value: string | number; accent?: 'success' | 'primary' | 'warning' | 'danger' }) {
  const color = accent ? ACCENT_CLASS[accent] : 'text-dark'
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <p className="text-xs text-gray">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${color}`}>{value}</p>
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
