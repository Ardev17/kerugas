import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Heart, Bell, Fuel, ArrowRight, Package, Truck } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { favoriteService } from '@/services/favoriteService'
import { alertService } from '@/services/alertService'
import { notificationService } from '@/services/notificationService'
import { CountUp } from '@/components/fx/CountUp'
import { Spotlight } from '@/components/fx/Spotlight'
import { ClientHomeBanner } from '@/components/illustrations/ClientHome'
import { greetingFor } from '@/components/illustrations/driverData'
import { useActiveOrder } from '@/hooks/useActiveOrder'

/**
 * Etapa 10,Dashboard do utilizador com dados reais (favoritos,
 * alertas, notificações) em vez de placeholders.
 */
export default function Dashboard() {
  const { user } = useAuth()
  const activeOrder = useActiveOrder()
  const hour = new Date().getHours()

  const favoritesQuery = useQuery({ queryKey: ['favorites'], queryFn: favoriteService.list })
  const alertsQuery = useQuery({ queryKey: ['alerts'], queryFn: alertService.list })
  const notificationsQuery = useQuery({ queryKey: ['notifications'], queryFn: notificationService.list })

  const recentNotifications = notificationsQuery.data?.data.slice(0, 5) ?? []

  return (
    <div className="mx-auto max-w-4xl px-4 py-10">
      {/* "A minha casa" (Outubro 2026): céu da hora real; o camião chega quando há um pedido em curso. */}
      <div className="fx-slide-next relative overflow-hidden rounded-3xl shadow-xl shadow-primary/10">
        <ClientHomeBanner hour={hour} truckArriving={activeOrder?.kind === 'track'} className="block h-36 w-full sm:h-60" />
        <div className="pointer-events-none absolute inset-0 bg-gradient-to-r from-black/35 via-black/10 to-transparent" aria-hidden="true" />
        <div className="absolute inset-x-0 top-0 p-4 sm:p-5">
          <h1 className="text-2xl font-extrabold tracking-tight text-white drop-shadow sm:text-3xl">
            {greetingFor(hour)}, {user?.name?.split(' ')[0]}! <span className="fx-wave" aria-hidden="true">👋</span>
          </h1>
          {(user?.municipality || user?.province) && (
            <p className="mt-0.5 text-sm font-medium text-white/90 drop-shadow">
              {user?.municipality}{user?.province ? `, ${user.province}` : ''}
            </p>
          )}
          {activeOrder?.kind === 'track' && (
            <Link to={`/pedido/${encodeURIComponent(activeOrder.reference)}`} className="mt-2 inline-flex items-center gap-1.5 rounded-full bg-white/90 px-3 py-1 text-xs font-bold text-primary shadow">
              <span className="fx-live-dot" aria-hidden="true" /> O seu gás: {activeOrder.statusLabel}
            </Link>
          )}
        </div>
      </div>

      <div className="fx-ring-border fx-slide-next mt-8 rounded-2xl">
        <Link to="/pedidos" className="group flex items-center justify-between gap-4 rounded-2xl bg-white p-5">
          <span className="flex items-center gap-4">
            <span className="fx-bubble fx-float h-12 w-12 shrink-0" aria-hidden="true"><Truck size={22} /></span>
            <span>
              <span className="flex items-center gap-1.5 text-base font-bold text-dark"><Package size={16} /> Os meus pedidos</span>
              <span className="mt-1 block text-sm text-gray">Veja os pedidos feitos, entregues e cancelados.</span>
            </span>
          </span>
          <ArrowRight size={20} className="shrink-0 text-primary transition-transform group-hover:translate-x-1.5" />
        </Link>
      </div>

      <div className="fx-cascade mt-4 grid gap-4 sm:grid-cols-3">
        <Link to="/encontrar-gas" style={{ ['--i' as string]: 1 }} className="block">
          <Spotlight className="h-full rounded-2xl border border-gray/15 bg-white p-5">
            <p className="flex items-center gap-2 text-sm text-gray">
              <span className="fx-bubble h-8 w-8" aria-hidden="true"><Fuel size={15} /></span> Encontrar gás
            </p>
            <p className="mt-3 flex items-center gap-1 text-sm font-semibold text-primary">
              Ver postos próximos <ArrowRight size={14} />
            </p>
          </Spotlight>
        </Link>

        <Link to="/favorites" style={{ ['--i' as string]: 2 }} className="block">
          <Spotlight className="h-full rounded-2xl border border-gray/15 bg-white p-5">
            <p className="flex items-center gap-2 text-sm text-gray">
              <span className="fx-bubble h-8 w-8" style={{ ['--bubble-a' as string]: '#E11D48', ['--bubble-b' as string]: '#FB7185' }} aria-hidden="true">
                <Heart size={15} className="fx-heartbeat fill-white" />
              </span>
              Favoritos
            </p>
            <p className="mt-2 text-3xl font-extrabold text-dark">
              {favoritesQuery.isLoading ? '—' : <CountUp value={favoritesQuery.data?.favorites.length ?? 0} />}
            </p>
          </Spotlight>
        </Link>

        <Link to="/alerts" style={{ ['--i' as string]: 3 }} className="block">
          <Spotlight className="h-full rounded-2xl border border-gray/15 bg-white p-5">
            <p className="flex items-center gap-2 text-sm text-gray">
              <span className="fx-bubble h-8 w-8" style={{ ['--bubble-a' as string]: '#F59E0B', ['--bubble-b' as string]: '#FF6B1A' }} aria-hidden="true">
                <Bell size={15} className="fx-bell" />
              </span>
              Alertas ativos
            </p>
            <p className="mt-2 text-3xl font-extrabold text-dark">
              {alertsQuery.isLoading ? '—' : <CountUp value={alertsQuery.data?.filter((a) => a.active).length ?? 0} />}
            </p>
          </Spotlight>
        </Link>
      </div>

      <div className="fx-glass fx-slide-next mt-8 rounded-2xl p-5">
        <div className="flex items-center justify-between">
          <p className="font-semibold text-dark">Atividade recente</p>
          <Link to="/alerts" className="text-sm text-primary">Ver tudo</Link>
        </div>

        <div className="fx-cascade mt-4 space-y-3">
          {notificationsQuery.isLoading && <p className="text-sm text-gray">A carregar...</p>}
          {notificationsQuery.isSuccess && recentNotifications.length === 0 && (
            <p className="text-sm text-gray">Sem atividade recente.</p>
          )}
          {recentNotifications.map((n, i) => (
            <div key={n.id} style={{ ['--i' as string]: i }} className={`border-b border-gray/10 pb-3 text-sm last:border-0 ${!n.read ? 'font-medium text-dark' : 'text-gray'}`}>
              <p className="flex items-center gap-2">
                {!n.read && <span className="fx-live-dot shrink-0" style={{ background: '#FC7201' }} aria-hidden="true" />}
                {n.title}
              </p>
              <p className="mt-0.5 text-xs text-gray">{n.body}</p>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
