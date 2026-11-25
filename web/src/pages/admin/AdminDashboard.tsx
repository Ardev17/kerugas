import { useQuery } from '@tanstack/react-query'
import { adminService } from '@/services/adminService'
import { useAuth } from '@/hooks/useAuth'
import { isProvinceScoped } from '@/lib/roles'
import { useLiveRefetchInterval } from '@/stores/realtimeStore'
import { CountUp } from '@/components/fx/CountUp'
import { Spotlight } from '@/components/fx/Spotlight'
import { AngolaMapArt } from '@/components/illustrations/Admin'
import { stationService } from '@/services/stationService'

export default function AdminDashboard() {
  const liveInterval = useLiveRefetchInterval(60_000)
  const { data, isLoading } = useQuery({ queryKey: ['admin-dashboard'], queryFn: adminService.dashboard, refetchInterval: liveInterval })
  const { user } = useAuth()
  // A mesma chave do Mapa público: a cache guarda a resposta inteira
  // ({ stations, blocked, accessBlock }) e cada ecrã tira o que quer com
  // `select`. Antes este painel guardava só a lista debaixo da mesma chave e,
  // vindo do Mapa (ou da cache do aparelho), recebia o objecto e rebentava.
  const mapQuery = useQuery({
    queryKey: ['map-stations'],
    queryFn: stationService.forMap,
    select: (lista) => lista.stations,
    refetchInterval: liveInterval,
  })
  const isProvinceScopedAdmin = isProvinceScoped(user)

  if (isLoading || !data) {
    return (
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4" aria-label="A carregar dashboard...">
        {Array.from({ length: 8 }, (_, i) => <div key={i} className="fx-shimmer h-20 rounded-xl" />)}
        <span className="sr-only">A carregar dashboard...</span>
      </div>
    )
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-bold text-dark">
          Visão geral
          <span className="inline-flex items-center gap-1.5 rounded-full bg-success/10 px-2 py-0.5 text-[11px] font-semibold text-success">
            <span className="fx-live-dot" aria-hidden="true" /> ao vivo
          </span>
        </h1>
        {isProvinceScopedAdmin && (
          <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
            {user?.province
              ? <>Os KPIs de postos, pedidos, utilizadores e denúncias da província de <strong className="text-dark">{user.province}</strong></>
              : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin.'}
          </p>
        )}
        {/* Mapa de Angola com os postos reais (Outubro 2026). */}
        <section className="fx-slide-next mt-4 flex items-center gap-5 overflow-hidden rounded-2xl border border-gray/15 bg-white p-4">
          <AngolaMapArt points={mapQuery.data ?? []} className="h-40 w-auto shrink-0 sm:h-48" />
          <div className="min-w-0">
            <p className="text-xs font-bold uppercase tracking-[0.2em] text-primary">Rede KeruGás</p>
            <p className="mt-1 text-lg font-extrabold text-dark">
              <CountUp value={mapQuery.data?.length ?? 0} /> postos no mapa
            </p>
            <ul className="mt-2 space-y-1 text-xs text-gray">
              <li className="flex items-center gap-2"><span className="fx-live-dot" aria-hidden="true" /> Com gás agora: <strong className="text-dark"><CountUp value={(mapQuery.data ?? []).filter((s) => s.gas_available).length} /></strong></li>
              <li className="flex items-center gap-2"><span className="h-2 w-2 rounded-full bg-gray" aria-hidden="true" /> Sem gás: <strong className="text-dark"><CountUp value={(mapQuery.data ?? []).filter((s) => !s.gas_available).length} /></strong></li>
            </ul>
            <p className="mt-2 text-[11px] text-gray">Os pontos que pulsam têm gás. Mapa estilizado.</p>
          </div>
        </section>
        <div className="fx-cascade mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Kpi i={0} label="Postos ativos" value={data.stations.active} />
          <Kpi i={1} label="Postos com gás" value={data.stations.with_gas} accent="success" />
          <Kpi i={2} label="Postos sem gás" value={data.stations.without_gas} accent="danger" />
          <Kpi i={3} label="Desatualizados (3h+)" value={data.stations.stale} accent="warning" />
        </div>
      </div>

      <div>
        <h2 className="font-semibold text-dark">SMS</h2>
        <div className="fx-cascade mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Kpi i={0} label="Total" value={data.sms.total} />
          <Kpi i={1} label="Processados" value={data.sms.processed} accent="success" />
          <Kpi i={2} label="Inválidos" value={data.sms.invalid} accent="warning" />
          <Kpi i={3} label="Falhados" value={data.sms.failed} accent="danger" />
        </div>
      </div>

      <div>
        <h2 className="font-semibold text-dark">Pedidos de gás</h2>
        <div className="fx-cascade mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Kpi i={0} label="Hoje" value={data.gas_orders.today} />
          <Kpi i={1} label="Pendentes" value={data.gas_orders.pending} accent="warning" />
          <Kpi i={2} label="Em entrega" value={data.gas_orders.out_for_delivery} />
          <Kpi i={3} label="Entregues" value={data.gas_orders.delivered} accent="success" />
        </div>
      </div>

      <div>
        <h2 className="font-semibold text-dark">Utilizadores e denúncias</h2>
        <div className="fx-cascade mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Kpi i={0} label="Utilizadores totais" value={data.users.total} />
          <Kpi i={1} label="Utilizadores ativos" value={data.users.active} />
          <Kpi i={2} label="Denúncias abertas" value={data.reports.open} accent="danger" />
          <Kpi i={3} label="Em revisão" value={data.reports.under_review} accent="warning" />
        </div>
      </div>
    </div>
  )
}

const ACCENT_CLASS: Record<string, string> = {
  success: 'text-success',
  warning: 'text-warning',
  danger: 'text-danger',
}

const ACCENT_COLOR: Record<string, string> = {
  success: '#16A34A',
  warning: '#F59E0B',
  danger: '#DC2626',
}

/** `i` = posição na grelha (para a entrada em cascata). */
function Kpi({ i, label, value, accent }: { i: number; label: string; value: number; accent?: 'success' | 'warning' | 'danger' }) {
  const color = accent ? ACCENT_CLASS[accent] : 'text-dark'
  return (
    <div style={{ ['--i' as string]: i, ['--kpi-color' as string]: accent ? ACCENT_COLOR[accent] : '#FC7201' }}>
      <Spotlight className="fx-kpi h-full rounded-xl border border-gray/15 bg-white p-4 pl-5">
        <p className="text-xs text-gray">{label}</p>
        <p className={`mt-1 text-2xl font-bold ${color}`}>
          <CountUp value={value} />
        </p>
      </Spotlight>
    </div>
  )
}
