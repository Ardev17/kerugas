import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MapPin, Phone, Truck } from 'lucide-react'
import { stationOrderService } from '@/services/stationOrderService'
import { getApiErrorMessage } from '@/lib/api'
import { formatKz } from '@/lib/metrics'
import type { GasOrder, StationOrdersPage, StationOrdersSubscriptionInfo } from '@/types'

const dateTime = (iso: string) => new Date(iso).toLocaleString('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })

/**
 * Pedidos do município para postos subscritos (Novembro 2026): gerente e
 * operadores. "Abertos" = pedidos do município ainda sem dono (quem aceitar
 * primeiro fica com ele); "Do posto" = os que este posto aceitou.
 */
export function StationOrdersPanel() {
  const [scope, setScope] = useState<'open' | 'mine'>('open')
  const query = useQuery({
    queryKey: ['station-orders', scope],
    queryFn: () => stationOrderService.list(scope),
    refetchInterval: 30_000,
  })

  const sub = query.data?.meta.subscription

  return (
    <div className="mt-6 space-y-4">
      {sub && <SubscriptionCard sub={sub} />}

      <div role="tablist" aria-label="Pedidos do posto" className="grid grid-cols-2 gap-1 rounded-xl bg-light p-1 text-sm font-medium">
        {([['open', 'Abertos no município'], ['mine', 'Do nosso posto']] as const).map(([k, label]) => (
          <button key={k} role="tab" aria-selected={scope === k} onClick={() => setScope(k)} className={`rounded-lg py-2 ${scope === k ? 'bg-white text-dark shadow-sm' : 'text-gray'}`}>
            {label}
          </button>
        ))}
      </div>

      {query.isLoading && <p className="text-center text-sm text-gray">A carregar pedidos...</p>}
      {query.isError && <p className="text-center text-sm text-danger">{getApiErrorMessage(query.error, 'Não foi possível carregar os pedidos.')}</p>}

      {query.data && scope === 'open' && !query.data.meta.subscription.can_accept && (
        <p className="rounded-xl border border-dashed border-gray/20 p-6 text-center text-sm text-gray">
          Com a subscrição ativa, os pedidos de {query.data.meta.subscription.municipality ?? 'seu município'} aparecem aqui.
        </p>
      )}

      {query.data && query.data.data.length === 0 && (scope === 'mine' || query.data.meta.subscription.can_accept) && (
        <p className="rounded-xl border border-dashed border-gray/20 p-6 text-center text-sm text-gray">
          {scope === 'open' ? 'Sem pedidos abertos no município neste momento.' : 'O posto ainda não aceitou pedidos.'}
        </p>
      )}

      <ul className="space-y-3">
        {query.data?.data.map((o) => (
          <li key={o.id}>
            {scope === 'open' ? <OpenOrderCard order={o} /> : <OwnOrderCard order={o} drivers={query.data!.meta.drivers} />}
          </li>
        ))}
      </ul>
    </div>
  )
}

function SubscriptionCard({ sub }: { sub: StationOrdersSubscriptionInfo }) {
  // O cartão de subscrição/pagamento aparece em dois casos:
  // 1. O posto está marcado como "nunca recebe pedidos" (override === BLOCK)
  // 2. A presença na aplicação está ativada (listing_enforced) e o posto não
  //    tem a subscrição paga (active === false)
  // Em qualquer outro caso o cartão fica escondido.
  const nuncaRecebe = sub.override === 'BLOCK'
  const presencaSemPagar = sub.listing_enforced && !sub.active
  if (!nuncaRecebe && !presencaSemPagar) return null

  return (
    <div className="rounded-xl border border-primary/30 bg-primary/5 p-4 text-sm">
      <p className="font-semibold text-dark">Receba os pedidos de gás de {sub.municipality ?? 'seu município'}</p>
      <p className="mt-1 text-gray">
        Subscrição de <strong className="text-dark">{sub.price_label}</strong>.
        Envie o comprovativo para <strong className="text-dark">{sub.payment.contact}</strong> e a KeruGás ativa o posto.
      </p>
      {sub.payment.iban && (
        <p className="mt-2 font-mono text-xs text-dark">
          {sub.payment.bank_name ? `${sub.payment.bank_name} · ` : ''}{sub.payment.account_holder ? `${sub.payment.account_holder} · ` : ''}IBAN {sub.payment.iban}
        </p>
      )}
    </div>
  )
}

function useRefresh() {
  const queryClient = useQueryClient()
  return () => void queryClient.invalidateQueries({ queryKey: ['station-orders'] })
}

function OrderHeader({ order }: { order: GasOrder }) {
  const place = [order.neighborhood, order.address, order.reference_point].filter(Boolean).join(' · ')
  return (
    <>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="font-semibold text-dark">{order.reference}</p>
        <span className="rounded-full bg-light px-2 py-0.5 text-xs font-medium text-dark">{order.status_label}</span>
      </div>
      <p className="mt-1 text-sm text-dark">
        {order.customer_name} · {order.quantity ?? 1} garrafa{(order.quantity ?? 1) > 1 ? 's' : ''}{order.cylinder_type ? ` (${order.cylinder_type})` : ''}
      </p>
      <a href={`tel:${order.customer_phone}`} className="mt-0.5 flex items-center gap-1 text-sm font-medium text-primary">
        <Phone size={14} /> {order.customer_phone}
      </a>
      {place && <p className="mt-0.5 flex items-start gap-1 text-xs text-gray"><MapPin size={12} className="mt-0.5 shrink-0" /> {place}</p>}
      {order.notes && <p className="mt-1 text-xs text-gray">Nota: {order.notes}</p>}
    </>
  )
}

function OpenOrderCard({ order }: { order: GasOrder }) {
  const refresh = useRefresh()
  const [price, setPrice] = useState('')
  const [open, setOpen] = useState(false)
  const accept = useMutation({
    mutationFn: () => stationOrderService.accept(order.id, { delivery_price: Number(price) }),
    onSuccess: refresh,
    onError: refresh,
  })

  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <OrderHeader order={order} />
      <p className="mt-1 text-xs text-gray">Pedido às {dateTime(order.created_at)}</p>

      {open ? (
        <form onSubmit={(e) => { e.preventDefault(); if (price !== '') accept.mutate() }} className="mt-3 flex flex-wrap items-center gap-2">
          <input
            type="number"
            min={0}
            inputMode="decimal"
            autoFocus
            value={price}
            onChange={(e) => setPrice(e.target.value)}
            placeholder="Preço acertado (Kz)"
            aria-label={`Preço acertado com o cliente do pedido ${order.reference}`}
            className="w-44 rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
          <button disabled={price === '' || accept.isPending} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
            {accept.isPending ? 'A aceitar...' : 'Confirmar e aceitar'}
          </button>
          <button type="button" onClick={() => setOpen(false)} className="text-sm text-gray">Cancelar</button>
          <p className="w-full text-xs text-gray">Ligue ao cliente, acerte o preço da entrega (garrafas incluídas) e confirme. A KeruGás não cobra comissão.</p>
        </form>
      ) : (
        <button onClick={() => setOpen(true)} className="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">
          Aceitar pedido
        </button>
      )}
      {accept.isError && <p className="mt-2 text-sm text-danger">{getApiErrorMessage(accept.error, 'Não foi possível aceitar o pedido.')}</p>}
    </div>
  )
}

function OwnOrderCard({ order, drivers }: { order: GasOrder; drivers: StationOrdersPage['meta']['drivers'] }) {
  const refresh = useRefresh()
  const [driverId, setDriverId] = useState('')
  const assign = useMutation({ mutationFn: () => stationOrderService.assign(order.id, Number(driverId)), onSuccess: refresh })
  const release = useMutation({ mutationFn: () => stationOrderService.release(order.id), onSuccess: refresh })
  const active = order.status === 'CONFIRMED' || order.status === 'ASSIGNED'

  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <OrderHeader order={order} />
      {order.delivery_price != null && <p className="mt-1 text-sm font-semibold text-dark">{formatKz(order.delivery_price)}</p>}
      {order.assigned_to && <p className="mt-1 flex items-center gap-1 text-sm text-gray"><Truck size={14} /> {order.assigned_to}</p>}

      {active && (
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {drivers.length === 0 ? (
            <p className="text-xs text-gray">O posto ainda não tem condutores ativos — crie-os em Gestão do posto.</p>
          ) : (
            <>
              <select value={driverId} onChange={(e) => setDriverId(e.target.value)} aria-label={`Condutor para o pedido ${order.reference}`} className="rounded-lg border border-gray/20 px-2 py-2 text-sm">
                <option value="">{order.status === 'ASSIGNED' ? 'Trocar de condutor...' : 'Escolher condutor...'}</option>
                {drivers.map((d) => <option key={d.id} value={d.id}>{d.name}{d.available ? '' : ' (ocupado)'}</option>)}
              </select>
              <button disabled={!driverId || assign.isPending} onClick={() => assign.mutate()} className="rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">
                Enviar ao condutor
              </button>
            </>
          )}
          <button
            disabled={release.isPending}
            onClick={() => { if (window.confirm(`Devolver o pedido ${order.reference} à KeruGás? O posto deixa de o ter.`)) release.mutate() }}
            className="rounded-lg border border-danger px-3 py-2 text-sm font-medium text-danger disabled:opacity-50"
          >
            Devolver à KeruGás
          </button>
        </div>
      )}
      {(assign.isError || release.isError) && (
        <p className="mt-2 text-sm text-danger">{getApiErrorMessage(assign.error ?? release.error, 'Não foi possível guardar.')}</p>
      )}
    </div>
  )
}
