import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { Navigation, Home, Star, Truck } from 'lucide-react'
import { driverService } from '@/services/driverService'
import { useAuth } from '@/hooks/useAuth'
import { useAuthStore } from '@/stores/authStore'
import { DriversRoomChat } from '@/components/roomchat/DriversRoomChat'
import { RouteLink } from '@/components/RouteLink'
import { successFeedback } from '@/native/haptics'
import { DriverStockPanel } from '@/components/driver/DriverStockPanel'
import { DriverLocationCard } from '@/components/driver/DriverLocationSharing'
import { CountUp } from '@/components/fx/CountUp'
import { DriverSkyBanner, Medal, ParkedTruckArt } from '@/components/illustrations/Driver'
import { driverAchievements, greetingFor } from '@/components/illustrations/driverData'
import { EmptyArt } from '@/components/illustrations/Admin'
import { Spotlight } from '@/components/fx/Spotlight'
import { celebrate } from '@/components/fx/celebrate'

const ORDER_COLOR: Record<string, string> = { DELIVERED: '#16A34A', CANCELLED: '#DC2626', FAILED: '#DC2626' }

const STATUS_FILTERS = ['ASSIGNED', 'DELIVERED', 'CANCELLED']

/**
 * Dashboard do Condutor (pedido explícito do cliente, Setembro 2026):
 * só os pedidos que lhe foram atribuídos (o backend já garante isto),
 * com o fluxo direto ASSIGNED -> Feita/Cancelado,sem passo
 * intermédio de "em entrega". Inclui a alternância manual de
 * disponibilidade e o chat do seu próprio município (sem escolha —
 * um Condutor só vê/participa no seu).
 */
export default function DriverDashboard() {
  const { user } = useAuth()
  const [params] = useSearchParams()
  const chatFocus = Number(params.get('mensagem')) || undefined
  const { token, setSession } = useAuthStore()
  const [status, setStatus] = useState('ASSIGNED')
  const queryClient = useQueryClient()
  const hour = new Date().getHours()

  const ordersQuery = useQuery({
    queryKey: ['driver-orders', status],
    queryFn: () => driverService.listOrders({ status }),
    // Entregas novas aparecem sem recarregar (Outubro 2026); com a app em
    // segundo plano não há pedidos,o push avisa.
    refetchInterval: 30_000,
  })

  // Ranking/estatísticas simples (Setembro 2026),mesmo cálculo que
  // o Admin/Super Admin veem deste condutor (ver DriverStatsService).
  const statsQuery = useQuery({
    queryKey: ['driver-stats'],
    queryFn: () => driverService.getStats(),
  })

  // Condutor de Posto (Novembro 2026): entrega os pedidos do seu posto,sem
  // stock, carregamentos nem conversa dos condutores KeruGás.
  const isStationDriver = user?.role === 'STATION_DRIVER'

  // Stock (Outubro 2026): sem garrafas em mão não se confirma a entrega.
  const stockQuery = useQuery({ queryKey: ['driver-stock'], queryFn: driverService.getStock, enabled: !isStationDriver })
  const onHand = isStationDriver ? undefined : stockQuery.data?.stock.on_hand

  const completeMutation = useMutation({
    mutationFn: (id: number) => driverService.completeOrder(id),
    onSuccess: () => {
      void successFeedback()
      // Entrega feita = festa (Outubro 2026).
      celebrate()
      void queryClient.invalidateQueries({ queryKey: ['driver-stock'] })
      return queryClient.invalidateQueries({ queryKey: ['driver-orders'] })
    },
  })
  const completeError = (completeMutation.error as { response?: { data?: { errors?: { stock?: string[] }; message?: string } } } | null)
    ?.response?.data

  const cancelMutation = useMutation({
    mutationFn: (id: number) => driverService.cancelOrder(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['driver-orders'] }),
  })

  const toggleAvailabilityMutation = useMutation({
    mutationFn: driverService.toggleAvailability,
    onSuccess: (updated) => {
      if (token) setSession(updated, token)
    },
  })

  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      {/* Céu da hora do dia, com o camião a passar quando está disponível (Outubro 2026). */}
      <div className="fx-slide-next relative mb-5 overflow-hidden rounded-2xl">
        <DriverSkyBanner hour={hour} driving={!!user?.driver_available} className="block h-24 w-full sm:h-28" />
        <p className="absolute left-4 top-3 text-lg font-extrabold text-white drop-shadow">
          {greetingFor(hour)}, {user?.name?.split(' ')[0]}!
        </p>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-extrabold text-dark">
            <span className={`flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-primary to-secondary text-white shadow-lg shadow-primary/30 ${user?.driver_available ? 'fx-float' : ''}`} aria-hidden="true">
              <Truck size={18} />
            </span>
            Painel do <span className="fx-gradient-text">Condutor</span>
          </h1>
          <p className="text-sm text-gray">{user?.municipality}, {user?.province}</p>
        </div>
        <div className="flex items-center gap-3">
          <button
            onClick={() => toggleAvailabilityMutation.mutate()}
            disabled={toggleAvailabilityMutation.isPending}
            aria-pressed={!!user?.driver_available}
            className={`flex items-center gap-2.5 rounded-xl px-3 py-2 text-sm font-semibold transition-colors ${
              user?.driver_available ? 'bg-success/10 text-success' : 'bg-gray/10 text-gray'
            }`}
          >
            <span className={`fx-switch ${user?.driver_available ? 'is-on' : ''}`} aria-hidden="true" />
            {user?.driver_available ? 'Disponível' : 'Indisponível'}
          </button>
          <Link to="/" className="flex items-center gap-1.5 text-xs text-gray hover:text-primary">
            <Home size={14} /> Site
          </Link>
        </div>
      </div>

      {/* Localização (Novembro 2026): partilhada com o Super Admin geral/Provincial. */}
      <div className="mt-4">
        <DriverLocationCard />
      </div>

      {statsQuery.data && (
        <div className="fx-cascade mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Spotlight className="rounded-2xl border border-gray/15 bg-white p-3 text-center" >
            <p className="text-2xl font-extrabold text-dark"><CountUp value={statsQuery.data.total_delivered} /></p>
            <p className="text-xs text-gray">Entregas feitas</p>
          </Spotlight>
          <Spotlight className="rounded-2xl border border-gray/15 bg-white p-3 text-center">
            <p className="text-2xl font-extrabold text-dark"><CountUp value={statsQuery.data.cancellation_rate} decimals={Number.isInteger(statsQuery.data.cancellation_rate) ? 0 : 1} suffix="%" /></p>
            <p className="text-xs text-gray">Taxa de cancelamento</p>
          </Spotlight>
          <Spotlight className="rounded-2xl border border-gray/15 bg-white p-3 text-center">
            <p className="text-2xl font-extrabold text-dark">
              {statsQuery.data.average_delivery_minutes != null ? <CountUp value={statsQuery.data.average_delivery_minutes} suffix=" min" /> : '—'}
            </p>
            <p className="text-xs text-gray">Tempo médio de entrega</p>
          </Spotlight>
          {/* Outubro 2026: abre as avaliações recebidas */}
          <Link
            to="/driver/avaliacoes"
            aria-label="Ver as minhas avaliações"
            className="fx-btn-ghost rounded-2xl border border-gray/15 bg-white p-3 text-center transition-colors hover:border-amber-400 active:bg-light"
          >
            <p className="flex items-center justify-center gap-1 text-xl font-bold text-dark">
              {statsQuery.data.average_rating != null ? (
                <>
                  <Star size={16} className="fx-flicker-icon fill-amber-400 text-amber-400" /> {statsQuery.data.average_rating}
                </>
              ) : '—'}
            </p>
            <p className="text-xs text-gray">
              Avaliação média {statsQuery.data.ratings_count > 0 ? `(${statsQuery.data.ratings_count})` : ''}
            </p>
            <p className="mt-0.5 text-[11px] font-medium text-primary">Ver avaliações ›</p>
          </Link>
        </div>
      )}

      {statsQuery.data && (
        <section aria-label="Conquistas" className="mt-4 rounded-2xl border border-gray/15 bg-white p-4">
          <p className="text-sm font-semibold text-dark">Conquistas</p>
          <ul className="mt-2 flex gap-3 overflow-x-auto pb-1">
            {driverAchievements(statsQuery.data.total_delivered, statsQuery.data.average_rating, statsQuery.data.ratings_count).map((a) => (
              <li key={a.key} className="flex w-20 shrink-0 flex-col items-center text-center" title={a.detail}>
                <Medal achievement={a} size={52} />
                <span className={`mt-1 text-[11px] font-semibold leading-tight ${a.unlocked ? 'text-dark' : 'text-gray'}`}>{a.label}</span>
                <span className="sr-only">{a.unlocked ? 'conquistada' : 'por conquistar'},{a.detail}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {isStationDriver ? (
        <p className="mt-6 rounded-xl border border-gray/15 bg-white p-4 text-sm text-gray">
          Condutor de posto{user?.station ? <> · <strong className="text-dark">{user.station.name}</strong></> : null}. Recebe aqui os pedidos que o seu posto lhe atribuir.
        </p>
      ) : <DriverStockPanel />}

      <select
        value={status}
        onChange={(e) => setStatus(e.target.value)}
        aria-label="Filtrar pedidos por estado"
        className="fx-input mt-5 rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
      >
        {STATUS_FILTERS.map((s) => <option key={s} value={s}>{s === 'ASSIGNED' ? 'Atribuídos' : s === 'DELIVERED' ? 'Feitos' : 'Cancelados'}</option>)}
      </select>

      <div key={status} className="fx-cascade mt-4 space-y-3">
        {ordersQuery.data?.data.map((o, i) => (
          <div
            key={o.id}
            style={{ ['--i' as string]: Math.min(i, 8), ['--order-color' as string]: ORDER_COLOR[o.status] ?? '#FC7201' }}
            className={`fx-order-card rounded-2xl border border-gray/15 bg-white p-4 pl-5 ${o.status === 'ASSIGNED' ? 'is-live' : ''}`}
          >
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div>
                <p className="font-medium text-dark">{o.reference}</p>
                {o.contact_hidden ? (
                  <p className="text-sm text-gray">Por segurança, os dados do cliente só aparecem durante a entrega.</p>
                ) : (
                  <>
                    <p className="text-sm text-gray">{o.customer_name} · {o.customer_phone}</p>
                    <p className="text-sm text-gray">{o.address ?? o.neighborhood ?? '—'}, {o.municipality}</p>
                  </>
                )}
                {o.cylinder_types_confirmed && <p className="text-sm text-gray">Garrafas: {o.cylinder_types_confirmed}</p>}
              </div>
              <span className="rounded-full bg-light px-2.5 py-1 text-xs font-semibold text-dark">{o.status_label}</span>
            </div>

            <div className="mt-3 flex flex-wrap gap-2">
              {!o.contact_hidden && (o.status === 'ASSIGNED' ? (
                <a href={`tel:${o.customer_phone}`} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium">
                  Contactar cliente
                </a>
              ) : (
                // Entrega feita ou cancelada: já não há motivo para ligar ao cliente (Outubro 2026).
                <button
                  type="button"
                  disabled
                  title="A entrega já terminou"
                  className="cursor-not-allowed rounded-lg border border-gray/15 px-3 py-1.5 text-sm font-medium text-gray opacity-60"
                >
                  Contactar cliente
                </button>
              ))}
              {!o.contact_hidden && <RouteLink
                latitude={o.latitude}
                longitude={o.longitude}
                className="flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-1.5 text-sm font-medium text-primary"
              >
                <Navigation size={14} /> Traçar rota
              </RouteLink>}
              {o.status === 'ASSIGNED' && (
                <>
                  <button
                    onClick={() => completeMutation.mutate(o.id)}
                    disabled={completeMutation.isPending || (onHand !== undefined && onHand < Math.max(1, o.quantity ?? 1))}
                    className="fx-btn-fire rounded-xl px-4 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
                  >
                    Marcar Feita
                  </button>
                  <button
                    onClick={() => cancelMutation.mutate(o.id)}
                    disabled={cancelMutation.isPending}
                    className="rounded-lg border border-danger/40 px-3 py-1.5 text-sm font-medium text-danger"
                  >
                    Cancelado
                  </button>
                </>
              )}
            </div>
            {o.status === 'ASSIGNED' && onHand !== undefined && onHand < Math.max(1, o.quantity ?? 1) && (
              <p className="mt-2 text-xs text-danger">
                Precisa de {Math.max(1, o.quantity ?? 1)} garrafa(s) e tem {onHand}. Registe um carregamento num posto.
              </p>
            )}
            {completeMutation.isError && completeMutation.variables === o.id && (
              <p className="mt-2 text-xs text-danger">{completeError?.errors?.stock?.[0] ?? completeError?.message ?? 'Não foi possível marcar como feita.'}</p>
            )}
          </div>
        ))}
        {ordersQuery.data?.data.length === 0 && (
          <div className="rounded-2xl border border-dashed border-gray/25 p-5 text-center">
            {status === 'ASSIGNED' ? <ParkedTruckArt className="mx-auto h-28 w-auto" /> : <EmptyArt className="mx-auto h-24 w-auto" />}
            <p className="mt-2 text-sm text-gray">Nenhum pedido neste estado.</p>
            {status === 'ASSIGNED' && <p className="text-xs text-gray">Tudo em dia,as novas entregas aparecem aqui sozinhas.</p>}
          </div>
        )}
      </div>

      {user?.municipality_id && !isStationDriver && (
        <div className="mt-8">
          <DriversRoomChat municipalityId={user.municipality_id} municipalityLabel={user.municipality} focusId={chatFocus} />
        </div>
      )}
    </div>
  )
}
