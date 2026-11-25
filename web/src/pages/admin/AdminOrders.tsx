import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Navigation, X, MapPin, Package, Clock, User, FileText, DollarSign, Pencil, Truck } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { adminGroupsService } from '@/services/adminGroupsService'
import { GroupTabs } from '@/components/admin/GroupTabs'
import { LocationGroupsView } from '@/components/admin/LocationGroupsView'
import { useAuth } from '@/hooks/useAuth'
import { RouteLink } from '@/components/RouteLink'
import { directionsUrl } from '@/lib/geo'
import { parseKz } from '@/lib/kz'
import type { ConfirmOrderPayload, DriverWithStock, NearbyDriver } from '@/services/adminService'
import { elapsedLabel } from '@/lib/timeAgo'
import type { GasOrder } from '@/types'
import { isProvinceScoped, hasSuperPowers } from '@/lib/roles'
import { useLiveRefetchInterval } from '@/stores/realtimeStore'

const STATUS_FILTERS = ['PENDING', 'RECEIVED', 'CONTACTING', 'CONFIRMED', 'ASSIGNED', 'OUT_FOR_DELIVERY', 'DELIVERED', 'CANCELLED']
// Mesmos nomes que o backend usa (GasOrderStatus::label()),antes o
// filtro mostrava os códigos internos em inglês ("OUT_FOR_DELIVERY").
const STATUS_FILTER_LABEL: Record<string, string> = {
  PENDING: 'Pendente', RECEIVED: 'Recebido', CONTACTING: 'Em contacto', CONFIRMED: 'Confirmado',
  ASSIGNED: 'Atribuído', OUT_FOR_DELIVERY: 'Em entrega', DELIVERED: 'Entregue', CANCELLED: 'Cancelado',
}

// "Traçar rota" só faz sentido depois do pedido confirmado (o cliente já
// validou a localização) e antes de ficar num estado terminal.
const ROUTE_ELIGIBLE_STATUSES = new Set(['CONFIRMED', 'ASSIGNED', 'OUT_FOR_DELIVERY', 'DELIVERED'])

// Envio a um Condutor (Setembro 2026): só faz sentido depois de
// finalizado (preço + garrafas já confirmados) e antes de já estar
// atribuído/concluído/cancelado.
const DRIVER_ASSIGNABLE_STATUSES = new Set(['CONFIRMED'])

type NextActionKind = keyof typeof ACTIONS | 'confirm'

const NEXT_ACTION: Record<string, { label: string; action: NextActionKind } | undefined> = {
  PENDING: { label: 'Marcar em contacto', action: 'contact' },
  RECEIVED: { label: 'Marcar em contacto', action: 'contact' },
  // "confirm" não passa pelo dispatcher genérico abaixo,precisa do
  // preço acertado com o cliente, por isso abre um pequeno modal em
  // vez de disparar a ação de imediato (ver `confirming`).
  CONTACTING: { label: 'Confirmar pedido', action: 'confirm' },
  CONFIRMED: { label: 'Iniciar entrega', action: 'delivery' },
  ASSIGNED: { label: 'Iniciar entrega', action: 'delivery' },
  OUT_FOR_DELIVERY: { label: 'Marcar entregue', action: 'complete' },
}

const ACTIONS = {
  contact: adminService.contactOrder,
  delivery: adminService.deliveryOrder,
  complete: adminService.completeOrder,
}

export default function AdminOrders() {
  const [status, setStatus] = useState('PENDING')
  const [viewingId, setViewingId] = useState<number | null>(null)
  const [confirming, setConfirming] = useState<GasOrder | null>(null)
  const [assigning, setAssigning] = useState<GasOrder | null>(null)
  const queryClient = useQueryClient()
  const { user } = useAuth()
  // Um ADMIN só vê pedidos da própria província,o backend já filtra
  // isto sozinho (AdminOrderController::scopeToAdminProvince), mas sem
  // este aviso a lista parece "faltar pedidos" sem explicação nenhuma
  // para quem gere a conta. Só SUPER_ADMIN vê tudo, sem aviso.
  const isProvinceScopedAdmin = isProvinceScoped(user)

  const liveInterval = useLiveRefetchInterval(20_000)
  // Organização (Outubro 2026): estado → província → município. Sem tempo
  // real ligado, os pedidos novos aparecem na mesma (atualização automática).
  const groupsQuery = useQuery({
    queryKey: ['admin-orders', 'groups', status],
    queryFn: () => adminGroupsService.groups('orders', { status }),
    refetchInterval: liveInterval,
  })

  const actionMutation = useMutation({
    mutationFn: ({ id, action }: { id: number; action: keyof typeof ACTIONS }) => ACTIONS[action](id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-orders'] }),
  })

  const confirmMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: ConfirmOrderPayload }) =>
      adminService.confirmOrder(id, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-orders'] })
      setConfirming(null)
    },
  })

  const cancelMutation = useMutation({
    mutationFn: (id: number) => adminService.cancelOrder(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-orders'] }),
  })

  const assignDriverMutation = useMutation({
    mutationFn: ({ id, driverId }: { id: number; driverId: number }) => adminService.assignOrderToDriver(id, driverId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-orders'] })
      setAssigning(null)
    },
  })

  const withdrawMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => adminService.withdrawOrderFromStation(id, reason),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-orders'] }),
  })

  // Cartão de um pedido (dentro de cada grupo província → município).
  const renderOrder = (o: GasOrder) => {
    // Pedido aceite por um posto (Novembro 2026): o posto trata dele; só o
    // Super Admin geral/Provincial o retira para reatribuir.
    const stationOrder = !!o.station_id
    const next = stationOrder ? undefined : NEXT_ACTION[o.status]
    return (
      <div key={o.id} className="rounded-xl border border-gray/15 bg-white p-4">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="font-medium text-dark">{o.reference}</p>
            <p className="text-sm text-gray">{o.customer_name} · {o.customer_phone}</p>
            <p className="text-sm text-gray">{o.municipality}, {o.province}</p>
            {stationOrder && (
              <p className="mt-1 inline-block rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                Aceite pelo posto {o.accepted_station?.name ?? ''}{o.assigned_to ? ` · ${o.assigned_to}` : ''}
              </p>
            )}
          </div>
          <span className="rounded-full bg-light px-2.5 py-1 text-xs font-semibold text-dark">{o.status_label}</span>
        </div>

        <div className="mt-3 flex flex-wrap gap-2">
          <button
            onClick={() => setViewingId(o.id)}
            className="flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark"
          >
            <FileText size={14} /> Ver detalhes
          </button>
          <a href={`tel:${o.customer_phone}`} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium">
            Contactar cliente
          </a>
          {ROUTE_ELIGIBLE_STATUSES.has(o.status) && (
            <RouteLink
              latitude={o.latitude}
              longitude={o.longitude}
              className="flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-1.5 text-sm font-medium text-primary"
            >
              <Navigation size={14} /> Traçar rota até ao cliente
            </RouteLink>
          )}
          {next && (next.action !== 'complete' || o.assigned_to_role !== 'DRIVER' || hasSuperPowers(user)) && (
            <button
              onClick={() =>
                next.action === 'confirm'
                  ? setConfirming(o)
                  : actionMutation.mutate({ id: o.id, action: next.action })
              }
              disabled={actionMutation.isPending}
              className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
            >
              {next.label}
            </button>
          )}
          {stationOrder && hasSuperPowers(user) && ['CONFIRMED', 'ASSIGNED'].includes(o.status) && (
            <button
              disabled={withdrawMutation.isPending}
              onClick={() => {
                const reason = window.prompt(`Retirar o pedido ${o.reference} do posto ${o.accepted_station?.name ?? ''} para o reatribuir? Motivo (opcional):`)
                if (reason !== null) withdrawMutation.mutate({ id: o.id, reason: reason || undefined })
              }}
              className="rounded-lg border border-warning px-3 py-1.5 text-sm font-medium text-warning disabled:opacity-50"
            >
              Retirar do posto
            </button>
          )}
          {!stationOrder && DRIVER_ASSIGNABLE_STATUSES.has(o.status) && (
            <button
              onClick={() => setAssigning(o)}
              className="flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-1.5 text-sm font-medium text-primary"
            >
              <Truck size={14} /> Enviar a condutor
            </button>
          )}
          {!['DELIVERED', 'CANCELLED'].includes(o.status) &&
            // RESTRIÇÃO DE PAPEL (pedido explícito do cliente): assim
            // que um pedido tem um Condutor atribuído, só o próprio
            // condutor (no seu dashboard) ou o Super Admin pode
            // marcá-lo como feito/cancelado,o backend já recusa
            // (403) um Admin comum, esconder o botão aqui evita um
            // erro confuso para quem gere a conta.
            ((o.assigned_to_role !== 'DRIVER' && !stationOrder) || hasSuperPowers(user)) && (
              <button
                onClick={() => cancelMutation.mutate(o.id)}
                className="rounded-lg border border-danger/40 px-3 py-1.5 text-sm font-medium text-danger"
              >
                Cancelar
              </button>
            )}
        </div>
      </div>
    )
  }

  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-bold text-dark">Pedidos de gás</h1>
      </div>
      <div className="mt-4">
        <GroupTabs
          ariaLabel="Estado dos pedidos"
          tabs={STATUS_FILTERS.map((k) => groupsQuery.data?.tabs.find((t) => t.key === k) ?? { key: k, count: 0 })}
          labels={STATUS_FILTER_LABEL}
          value={status}
          onChange={setStatus}
        />
      </div>

      {isProvinceScopedAdmin && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          {user?.province
            ? <>A mostrar apenas pedidos da província de <strong className="text-dark">{user.province}</strong></>
            : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin para conseguir ver pedidos.'}
        </p>
      )}

      <div className="mt-6">
        <LocationGroupsView<GasOrder>
          resource="orders"
          queryKey={['admin-orders']}
          params={{ status }}
          groups={groupsQuery.data?.groups}
          isLoading={groupsQuery.isLoading}
          withMunicipalities
          renderItem={renderOrder}
          refetchInterval={liveInterval}
          emptyText="Nenhum pedido neste estado."
        />
      </div>

      {viewingId && <OrderDetailsModal orderId={viewingId} onClose={() => setViewingId(null)} />}

      {confirming && (
        <ConfirmPriceModal
          onClose={() => setConfirming(null)}
          currentQuantity={confirming.quantity ?? 1}
          onSubmit={(payload) => confirmMutation.mutate({ id: confirming.id, payload })}
          isPending={confirmMutation.isPending}
          error={confirmMutation.isError ? 'Não foi possível confirmar o pedido. Verifique os dados e tente novamente.' : null}
        />
      )}

      {assigning && (
        <AssignDriverModal
          order={assigning}
          onClose={() => setAssigning(null)}
          showLocation={hasSuperPowers(user)}
          onSubmit={(driverId) => assignDriverMutation.mutate({ id: assigning.id, driverId })}
          isPending={assignDriverMutation.isPending}
          error={assignDriverMutation.isError ? 'Não foi possível enviar o pedido a este condutor. Verifique se está disponível e se tem garrafas suficientes.' : null}
        />
      )}
    </div>
  )
}

/**
 * Preço da entrega (Setembro 2026): nunca calculado automaticamente —
 * é sempre o valor que o Admin acertou por telefone com o cliente,
 * introduzido aqui só depois de confirmar. O backend recusa (422) sem
 * este valor.
 */
/**
 * Confirmação do preço E do(s) tipo(s) de garrafa (Setembro 2026) —
 * os dois dados fundem-se num só passo, porque o Admin confirma ambos
 * com o cliente na mesma chamada. `cylinderTypesConfirmed` é texto
 * livre, separado por vírgula quando há mais de um tipo/garrafa (ex:
 * "6kg, 13kg").
 */
function ConfirmPriceModal({
  onClose,
  onSubmit,
  isPending,
  error,
  currentQuantity,
}: {
  onClose: () => void
  onSubmit: (payload: ConfirmOrderPayload) => void
  isPending: boolean
  error: string | null
  currentQuantity: number
}) {
  const [bottle, setBottle] = useState('')
  const [commission, setCommission] = useState('')
  const [quantity, setQuantity] = useState(String(currentQuantity))
  const [cylinders, setCylinders] = useState('')
  const bottleValue = parseKz(bottle)
  const commissionValue = parseKz(commission)
  const qty = Number(quantity)
  const qtyValid = Number.isInteger(qty) && qty >= currentQuantity && qty <= 100
  const total = bottleValue !== null && commissionValue !== null ? bottleValue + commissionValue : null
  const isValid = total !== null && qtyValid && cylinders.trim() !== ''

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[92vh] w-full max-w-sm overflow-y-auto rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">Confirmar pedido</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        <p className="mt-2 text-sm text-gray">
          Introduza o valor real das garrafas (total do pedido) e a comissão da KeruGas. O cliente vê apenas o total. As garrafas saem do stock do condutor.
        </p>

        <label htmlFor="confirm-bottle" className="mt-3 block text-xs font-medium text-gray">Valor das garrafas (Kz),total do pedido</label>
        <input
          id="confirm-bottle"
          type="text"
          inputMode="decimal"
          autoFocus
          value={bottle}
          onChange={(e) => setBottle(e.target.value)}
          placeholder="Ex.: 1200"
          className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
        />

        <label htmlFor="confirm-commission" className="mt-3 block text-xs font-medium text-gray">Comissão da KeruGas (Kz)</label>
        <input
          id="confirm-commission"
          type="text"
          inputMode="decimal"
          value={commission}
          onChange={(e) => setCommission(e.target.value)}
          placeholder="Ex.: 500"
          className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
        />

        <p className="mt-3 rounded-lg bg-light px-3 py-2 text-sm text-dark" data-testid="confirm-total">
          Total a cobrar ao cliente: <strong>{total !== null ? formatKz(total) : '—'}</strong>
        </p>

        <label htmlFor="confirm-qty" className="mt-3 block text-xs font-medium text-gray">Número de garrafas</label>
        <input
          id="confirm-qty"
          type="number"
          min={currentQuantity}
          max={100}
          value={quantity}
          onChange={(e) => setQuantity(e.target.value)}
          className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
        />
        <p className="mt-1 text-xs text-gray">Pode aumentar, nunca diminuir (pedido: {currentQuantity}).</p>

        <label htmlFor="confirm-cyl" className="mt-3 block text-xs font-medium text-gray">Tipo(s) de garrafa</label>
        <input
          id="confirm-cyl"
          type="text"
          value={cylinders}
          onChange={(e) => setCylinders(e.target.value)}
          placeholder="Ex.: 13kg ou 6kg, 13kg"
          className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
        />
        <p className="mt-1 text-xs text-gray">Separe por vírgula se for mais de uma garrafa/tipo.</p>

        {error && <p className="mt-2 text-xs text-danger">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">
            Cancelar
          </button>
          <button
            onClick={() =>
              isValid &&
              onSubmit({
                bottle_price: bottleValue as number,
                commission: commissionValue as number,
                quantity: qty,
                cylinder_types_confirmed: cylinders.trim(),
              })
            }
            disabled={!isValid || isPending}
            className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {isPending ? 'A confirmar...' : 'Confirmar'}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * Envio do pedido a um Condutor (Setembro 2026): só lista condutores
 * DISPONÍVEIS do MESMO município do pedido,o backend já recusa
 * (422) qualquer outra combinação, mas filtrar aqui na origem evita
 * mostrar opções que seriam sempre rejeitadas.
 */
function AssignDriverModal({
  order,
  onClose,
  onSubmit,
  isPending,
  error,
  showLocation = false,
}: {
  order: { id: number; municipality?: string | null; quantity?: number } | null
  /** Super Admin geral/Provincial: vê a distância de cada condutor ao cliente (Novembro 2026). */
  showLocation?: boolean
  onClose: () => void
  onSubmit: (driverId: number) => void
  isPending: boolean
  error: string | null
}) {
  const [selectedId, setSelectedId] = useState<number | null>(null)

  const driversQuery = useQuery({
    queryKey: ['admin-drivers-for-order', order?.id],
    queryFn: () => adminService.listDrivers({ available_only: true }),
    enabled: !!order,
  })

  // Localização (Novembro 2026): a lista vem ordenada do mais perto para o
  // mais longe. É só uma ajuda,quem escolhe é sempre o admin.
  const nearbyQuery = useQuery({
    queryKey: ['admin-nearby-drivers', order?.id],
    queryFn: () => adminService.nearbyDrivers(order!.id),
    enabled: !!order && showLocation,
    refetchInterval: 60_000,
  })
  const nearby = nearbyQuery.data?.data ?? []
  const nearbyById = new Map(nearby.map((n, i) => [n.id, { ...n, rank: i }]))

  // O backend já recusa um condutor de outro município,este filtro
  // é só para não listar opções inúteis na interface.
  const eligibleDrivers = (driversQuery.data?.data ?? [])
    .filter((d: DriverWithStock) => !order?.municipality || d.municipality === order.municipality)
    .sort((a, b) => (nearbyById.get(a.id)?.rank ?? 9999) - (nearbyById.get(b.id)?.rank ?? 9999))
  // Stock (Outubro 2026): só se envia a quem tem garrafas suficientes.
  const need = Math.max(1, order?.quantity ?? 1)
  const hasStock = (d: DriverWithStock) => (d.bottle_stock?.available ?? 0) >= need

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">Enviar a condutor</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        <p className="mt-2 text-sm text-gray">
          Condutores disponíveis {order?.municipality ? <>em <strong className="text-dark">{order.municipality}</strong></> : null}.
          {showLocation && ' Do mais perto do cliente para o mais longe,escolha quem envia.'}
        </p>
        {showLocation && nearbyQuery.data && !nearbyQuery.data.meta.order_has_location && (
          <p className="mt-1 text-xs text-warning">Este pedido não tem localização GPS do cliente: não dá para calcular distâncias.</p>
        )}

        <div className="mt-3 max-h-64 space-y-2 overflow-y-auto">
          {driversQuery.isLoading && <p className="text-sm text-gray">A carregar...</p>}
          {!driversQuery.isLoading && eligibleDrivers.length === 0 && (
            <p className="text-sm text-gray">Nenhum condutor disponível neste município.</p>
          )}
          {eligibleDrivers.map((d: DriverWithStock) => (
            <label
              key={d.id}
              className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                hasStock(d) ? 'cursor-pointer' : 'cursor-not-allowed opacity-60'
              } ${selectedId === d.id ? 'border-primary bg-primary/5' : 'border-gray/20'}`}
            >
              <input
                type="radio"
                name="driver"
                checked={selectedId === d.id}
                disabled={!hasStock(d)}
                onChange={() => setSelectedId(d.id)}
                aria-label={d.name}
              />
              <span className="min-w-0">
                <span className="block text-dark">{d.name}</span>
                {showLocation && <DriverDistance info={nearbyById.get(d.id)} />}
              </span>
              <span className="ml-auto shrink-0 text-xs text-gray">
                {d.bottle_stock?.available ?? 0} garrafa(s)
              </span>
            </label>
          ))}
          {eligibleDrivers.length > 0 && eligibleDrivers.every((d) => !hasStock(d)) && (
            <p className="text-xs text-danger">Nenhum condutor tem {need} garrafa(s) disponível(eis). O condutor tem de registar um carregamento num posto.</p>
          )}
        </div>

        {error && <p className="mt-2 text-xs text-danger">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">
            Cancelar
          </button>
          <button
            onClick={() => selectedId && onSubmit(selectedId)}
            disabled={!selectedId || isPending}
            className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {isPending ? 'A enviar...' : 'Enviar pedido'}
          </button>
        </div>
      </div>
    </div>
  )
}

/** Distância ao cliente, há quanto tempo e link para o mapa (Novembro 2026). */
function DriverDistance({ info }: { info?: NearbyDriver }) {
  const loc = info?.location
  if (!loc || !loc.fresh) {
    return (
      <span className="block text-xs text-gray">
        Sem localização{loc ? ` (última ${elapsedLabel(loc.updated_at)})` : ''}
      </span>
    )
  }
  const km = info?.distance_km
  return (
    <span className="block text-xs text-gray">
      {km !== null && km !== undefined && <strong className="text-success">{km < 1 ? `${Math.round(km * 1000)} m` : `${km.toLocaleString('pt-PT', { maximumFractionDigits: 1 })} km`}</strong>}
      {km !== null && km !== undefined && ' · '}
      {elapsedLabel(loc.updated_at)} ·{' '}
      <a
        href={`https://www.google.com/maps?q=${loc.latitude},${loc.longitude}`}
        target="_blank"
        rel="noopener noreferrer"
        className="text-primary underline"
        onClick={(e) => e.stopPropagation()}
      >
        mapa
      </a>
    </span>
  )
}

const LOCATION_SOURCE_LABEL: Record<string, string> = { GPS: 'Localização exata (GPS)', MANUAL: 'Endereço inserido manualmente' }

const EVENT_ACTION_LABEL: Record<string, string> = {
  created: 'Pedido criado',
  status_changed: 'Estado alterado',
}

function DetailRow({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value) return null
  return (
    <div>
      <p className="text-xs uppercase tracking-wide text-gray">{label}</p>
      <p className="text-sm text-dark">{value}</p>
    </div>
  )
}

function Section({ icon: Icon, title, children }: { icon: typeof MapPin; title: string; children: ReactNode }) {
  return (
    <div className="border-t border-gray/10 pt-4 first:border-0 first:pt-0">
      <p className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-dark">
        <Icon size={15} className="text-primary" /> {title}
      </p>
      <div className="grid grid-cols-2 gap-3">{children}</div>
    </div>
  )
}

/**
 * Modal de detalhes completos do pedido,tudo o que o cliente
 * preencheu no formulário de 4 passos (contacto, localização, pedido)
 * mais o histórico de eventos (gas_order_events, já existente desde
 * a Etapa 6/GasOrderService, mas nunca antes mostrado na interface).
 */
function formatKz(value: number): string {
  return `${value.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`
}

function OrderDetailsModal({ orderId, onClose }: { orderId: number; onClose: () => void }) {
  const { user } = useAuth()
  const isSuperAdmin = hasSuperPowers(user)
  const queryClient = useQueryClient()
  const [editingPrice, setEditingPrice] = useState(false)
  const [bottleValue, setBottleValue] = useState('')
  const [commissionValue, setCommissionValue] = useState('')

  const { data: order, isLoading } = useQuery({
    queryKey: ['admin-order', orderId],
    queryFn: () => adminService.getOrder(orderId),
  })

  const correctPriceMutation = useMutation({
    mutationFn: (payload: Parameters<typeof adminService.updateOrderPrice>[1]) => adminService.updateOrderPrice(orderId, payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-order', orderId] })
      queryClient.invalidateQueries({ queryKey: ['admin-orders'] })
      setEditingPrice(false)
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">{order?.reference ?? 'Detalhes do pedido'}</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>

        {isLoading && <p className="mt-6 text-sm text-gray">A carregar...</p>}

        {order && (
          <div className="mt-4 space-y-4">
            <span className="inline-block rounded-full bg-light px-2.5 py-1 text-xs font-semibold text-dark">
              {order.status_label}
            </span>

            <Section icon={User} title="Contacto (fornecido pelo cliente)">
              <DetailRow label="Nome" value={order.customer_name} />
              <DetailRow label="Telefone" value={order.customer_phone} />
            </Section>

            <Section icon={MapPin} title="Localização (fornecida pelo cliente)">
              <DetailRow label="Província" value={order.province} />
              <DetailRow label="Município" value={order.municipality} />
              <DetailRow label="Bairro / Zona" value={order.neighborhood} />
              <DetailRow label="Endereço" value={order.address} />
              <DetailRow label="Ponto de referência" value={order.reference_point} />
              <DetailRow label="Origem" value={order.location_source ? LOCATION_SOURCE_LABEL[order.location_source] : undefined} />
              {directionsUrl(order.latitude, order.longitude) && (
                <div className="col-span-2">
                  <RouteLink
                    latitude={order.latitude}
                    longitude={order.longitude}
                    className="flex w-fit items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-1.5 text-sm font-medium text-primary"
                  >
                    <Navigation size={14} /> Ver localização exata no mapa
                  </RouteLink>
                </div>
              )}
            </Section>

            <Section icon={Package} title="Pedido">
              <DetailRow label="Quantidade" value={order.quantity ? String(order.quantity) : undefined} />
              <DetailRow label="Tipo de botija (indicado pelo cliente)" value={order.cylinder_type} />
              <DetailRow label="Tipo(s) de garrafa confirmados" value={order.cylinder_types_confirmed} />
              {order.notes && (
                <div className="col-span-2">
                  <p className="text-xs uppercase tracking-wide text-gray">Observações</p>
                  <p className="text-sm text-dark">{order.notes}</p>
                </div>
              )}
              <DetailRow label="Prioridade" value={order.priority} />
              <DetailRow label="Atribuído a" value={order.assigned_to} />
            </Section>

            {order.delivery_price != null && (
              <Section icon={DollarSign} title="Faturação">
                <DetailRow label="Total cobrado ao cliente" value={formatKz(order.delivery_price)} />
                {order.bottle_price != null && <DetailRow label="Valor das garrafas (posto)" value={formatKz(order.bottle_price)} />}
                {order.commission != null && <DetailRow label="Comissão KeruGas" value={formatKz(order.commission)} />}
                {order.billing_complete === false && (
                  <p className="col-span-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
                    Pedido antigo, só com total,complete a divisão em Faturação › Por completar.
                  </p>
                )}

                <DetailRow label="Registado por" value={order.price_set_by} />
                <DetailRow label="Registado em" value={order.price_set_at ? new Date(order.price_set_at).toLocaleString('pt-PT') : undefined} />
                {order.price_corrected_by && (
                  <>
                    <DetailRow label="Corrigido por" value={order.price_corrected_by} />
                    <DetailRow label="Corrigido em" value={order.price_corrected_at ? new Date(order.price_corrected_at).toLocaleString('pt-PT') : undefined} />
                  </>
                )}

                {isSuperAdmin && (
                  <div className="col-span-2">
                    {!editingPrice ? (
                      <button
                        onClick={() => {
                          setBottleValue(order.bottle_price != null ? String(order.bottle_price) : String(order.delivery_price))
                          setCommissionValue(order.commission != null ? String(order.commission) : '0')
                          setEditingPrice(true)
                        }}
                        className="flex items-center gap-1.5 text-sm font-medium text-primary"
                      >
                        <Pencil size={14} /> Corrigir valores
                      </button>
                    ) : (
                      <div className="mt-1 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <label className="text-xs text-gray" htmlFor="fix-bottle">Garrafas</label>
                          <input id="fix-bottle" type="text" inputMode="decimal" value={bottleValue} onChange={(e) => setBottleValue(e.target.value)} className="w-28 rounded-lg border border-gray/20 px-2 py-1 text-sm" />
                          <label className="text-xs text-gray" htmlFor="fix-comm">Comissão</label>
                          <input id="fix-comm" type="text" inputMode="decimal" value={commissionValue} onChange={(e) => setCommissionValue(e.target.value)} className="w-28 rounded-lg border border-gray/20 px-2 py-1 text-sm" />
                        </div>
                        <div className="flex gap-2">
                          <button
                            onClick={() => {
                              const b = parseKz(bottleValue)
                              const c = parseKz(commissionValue)
                              if (b !== null && c !== null) {
                                correctPriceMutation.mutate({ bottle_price: b, commission: c })
                              }
                            }}
                            disabled={correctPriceMutation.isPending}
                            className="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-60"
                          >
                            Guardar
                          </button>
                          <button onClick={() => setEditingPrice(false)} className="rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-medium text-dark">
                            Cancelar
                          </button>
                        </div>
                        {correctPriceMutation.isError && (
                          <p className="text-xs text-danger">Não foi possível corrigir os valores.</p>
                        )}
                      </div>
                    )}
                  </div>
                )}
              </Section>
            )}

            {order.events && order.events.length > 0 && (
              <div className="border-t border-gray/10 pt-4">
                <p className="mb-3 flex items-center gap-1.5 text-sm font-semibold text-dark">
                  <Clock size={15} className="text-primary" /> Histórico do pedido
                </p>
                <div className="space-y-3">
                  {order.events.map((e, i) => (
                    <div key={i} className="flex gap-3 text-sm">
                      <div className="mt-1 h-2 w-2 shrink-0 rounded-full bg-primary" />
                      <div>
                        <p className="text-dark">
                          {EVENT_ACTION_LABEL[e.action] ?? e.action}
                          {e.to_status && <span className="text-gray"> → {e.to_status}</span>}
                          {e.user && <span className="text-gray"> · {e.user}</span>}
                        </p>
                        {e.notes && <p className="text-xs text-gray">{e.notes}</p>}
                        <p className="text-xs text-gray">{new Date(e.created_at).toLocaleString('pt-PT')}</p>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}
