import { Link } from 'react-router-dom'
import { useState } from 'react'
import { useInfiniteQuery } from '@tanstack/react-query'
import { ChevronRight, PackageOpen, Star } from 'lucide-react'
import { motion } from 'framer-motion'
import { gasOrderService, type MyOrdersFilter } from '@/services/gasOrderService'
import { isTerminalFailure, statusTone } from '@/constants/orderStatus'
import { PageHero } from '@/components/fx/PageHero'

/**
 * "Os meus pedidos" (Setembro 2026). A API já tinha `GET /orders` desde
 * o início, mas nenhuma página o usava,e mesmo que usasse, a lista
 * vinha sempre vazia, porque os pedidos feitos com sessão iniciada eram
 * gravados sem ligação à conta (bug corrigido em GasOrderController::store).
 */
const FILTERS: { label: string; value: MyOrdersFilter | undefined }[] = [
  { label: 'Todos', value: undefined },
  { label: 'Em curso', value: 'active' },
  { label: 'Entregues', value: 'delivered' },
  { label: 'Cancelados', value: 'cancelled' },
]

export default function MyOrders() {
  const [filter, setFilter] = useState<MyOrdersFilter | undefined>(undefined)

  const ordersQuery = useInfiniteQuery({
    queryKey: ['my-orders', filter ?? 'all'],
    queryFn: ({ pageParam }) => gasOrderService.listMine(pageParam, filter),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined),
  })

  const orders = ordersQuery.data?.pages.flatMap((p) => p.data) ?? []

  return (
    <div className="mx-auto max-w-lg px-4 py-6">
      <PageHero title="Os meus" highlight="pedidos" />

      {/* Outubro 2026: pedidos feitos e pedidos cancelados, à parte. */}
      <div role="tablist" aria-label="Filtrar pedidos" className="mt-4 flex gap-1.5 overflow-x-auto pb-1">
        {FILTERS.map((f) => (
          <button
            key={f.label}
            role="tab"
            aria-selected={filter === f.value}
            onClick={() => setFilter(f.value)}
            className={`relative isolate shrink-0 rounded-full px-3.5 py-1.5 text-sm font-medium transition-colors ${
              filter === f.value ? 'text-white' : 'border border-gray/20 text-gray hover:text-dark'
            }`}
          >
            {/* A pílula azul desliza para o filtro escolhido. */}
            {filter === f.value && (
              <motion.span layoutId="my-orders-filter" className="absolute inset-0 -z-10 rounded-full bg-primary shadow-lg shadow-primary/30" transition={{ type: 'spring', stiffness: 420, damping: 34 }} />
            )}
            {f.label}
          </button>
        ))}
      </div>

      {ordersQuery.isLoading && (
        <div className="mt-4 space-y-3" aria-label="A carregar">
          {[1, 2, 3, 4].map((i) => <div key={i} className="fx-shimmer h-[74px] rounded-2xl" />)}
        </div>
      )}

      {!ordersQuery.isLoading && orders.length === 0 && (
        <div className="fx-slide-next mt-10 flex flex-col items-center text-center">
          <span className="fx-bubble fx-float h-16 w-16" aria-hidden="true"><PackageOpen size={30} /></span>
          <p className="mt-3 font-medium text-dark">{filter ? 'Nenhum pedido nesta lista' : 'Ainda não fez nenhum pedido'}</p>
          <p className="mt-1 text-sm text-gray">
            {filter ? 'Experimente outro filtro.' : 'Os pedidos que fizer com esta conta aparecem aqui.'}
          </p>
          <Link to="/pedir-gas" className="fx-btn-fire mt-6 rounded-xl px-6 py-3 text-sm font-semibold text-white">
            Pedir gás
          </Link>
        </div>
      )}

      <ul key={filter ?? 'all'} className="fx-cascade mt-4 space-y-3">
        {orders.map((order, i) => (
          <li key={order.id} style={{ ['--i' as string]: Math.min(i, 10) }}>
            <Link
              to={`/pedido/${encodeURIComponent(order.reference)}`}
              className="fx-row flex items-center gap-3 rounded-2xl border border-gray/15 bg-white p-4"
            >
              <div className="min-w-0 flex-1">
                <p className="truncate font-semibold tabular-nums text-dark">{order.reference}</p>
                <p className="mt-0.5 truncate text-xs text-gray">
                  {new Date(order.created_at).toLocaleDateString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric' })}
                  {order.municipality ? ` · ${order.municipality}` : ''}
                </p>
              </div>
              <span className={`flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold ${statusTone(order.status)}`}>
                {order.status !== 'DELIVERED' && !isTerminalFailure(order.status) && <span className="fx-live-dot" style={{ background: 'currentColor' }} aria-hidden="true" />}
                {order.status_label}
              </span>
              <ChevronRight size={18} className="fx-row-arrow shrink-0 text-gray" />
            </Link>
            {order.can_rate && (
              <Link
                to={`/avaliar/${encodeURIComponent(order.reference)}`}
                state={{ phone: order.customer_phone }}
                className="mt-1.5 flex items-center justify-center gap-1.5 rounded-xl bg-amber-500/10 px-3 py-2 text-xs font-semibold text-amber-700"
              >
                <Star size={14} className="fx-flicker-icon fill-amber-500 text-amber-500" /> Avaliar o condutor
              </Link>
            )}
          </li>
        ))}
      </ul>

      {ordersQuery.hasNextPage && (
        <button
          onClick={() => void ordersQuery.fetchNextPage()}
          disabled={ordersQuery.isFetchingNextPage}
          className="fx-btn-ghost mt-4 w-full rounded-xl border border-gray/20 py-3 text-sm font-medium text-dark disabled:opacity-60"
        >
          {ordersQuery.isFetchingNextPage ? 'A carregar...' : 'Ver mais pedidos'}
        </button>
      )}
    </div>
  )
}
