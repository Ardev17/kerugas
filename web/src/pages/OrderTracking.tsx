import { useEffect } from 'react'
import { Link, useParams } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { Check, XCircle, Loader2, Phone, MapPin, Package, ArrowLeft, Star } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { readActiveOrder } from '@/lib/activeOrder'
import { gasOrderService } from '@/services/gasOrderService'
import { CUSTOMER_STEPS, customerStepIndex, isTerminalFailure } from '@/constants/orderStatus'
import { CENTRAL_CONTACT_NUMBERS } from '@/constants/contact'
import { OrderPushPrompt } from '@/components/OrderPushPrompt'
import { Avatar } from '@/components/Avatar'
import { CancelledArt, OrderStageArt, type DeliveryStage } from '@/components/illustrations/Delivery'
import { DeliveryRoad } from '@/components/fx/DeliveryRoad'
import { celebrate } from '@/components/fx/celebrate'

/** Festa uma única vez por pedido entregue, neste aparelho. */
function useDeliveredParty(reference: string, delivered: boolean) {
  useEffect(() => {
    if (!delivered || !reference) return
    const key = `kerugas_party_${reference}`
    try {
      if (localStorage.getItem(key)) return
      localStorage.setItem(key, '1')
    } catch {
      // sem armazenamento: festeja na mesma
    }
    let stop = () => {}
    const t = window.setTimeout(() => {
      stop = celebrate()
    }, 900)
    return () => {
      window.clearTimeout(t)
      stop()
    }
  }, [reference, delivered])
}

/**
 * Acompanhamento do pedido (Setembro 2026),para onde leva a
 * notificação "Pedido confirmado / A caminho / Entregue" no app. Mostra
 * o estado REAL do pedido (não o texto fixo de "pedido recebido" da
 * página de sucesso) e atualiza-se sozinho enquanto está aberto.
 *
 * Só o dono do pedido (ou um Admin) consegue vê-lo,a API responde 403
 * a qualquer outra conta (GasOrderPolicy::view).
 */
export default function OrderTracking() {
  const { reference = '' } = useParams<{ reference: string }>()

  const { isAuthenticated } = useAuth()
  // Sem conta: o telefone guardado neste aparelho ao fazer o pedido.
  const stored = readActiveOrder()
  const guestPhone = stored?.reference === reference ? stored.phone : null

  const orderQuery = useQuery({
    queryKey: ['order-tracking', reference, isAuthenticated],
    enabled: isAuthenticated || !!guestPhone,
    retry: false,
    queryFn: () =>
      isAuthenticated ? gasOrderService.getByReference(reference) : gasOrderService.trackByPhone(reference, guestPhone as string),
    // Enquanto o pedido está em curso, verifica a cada 30s; parado
    // quando já terminou (entregue/cancelado),não há mais nada a mudar.
    refetchInterval: (query) => {
      const status = query.state.data?.status
      return status && (status === 'DELIVERED' || isTerminalFailure(status)) ? false : 30_000
    },
  })

  useDeliveredParty(reference, orderQuery.data?.status === 'DELIVERED')

  if (orderQuery.isLoading && (isAuthenticated || guestPhone)) {
    return (
      <div className="flex justify-center py-24 text-gray">
        <Loader2 className="animate-spin" size={28} aria-label="A carregar o pedido" />
      </div>
    )
  }

  if (orderQuery.isError || !orderQuery.data) {
    return (
      <div className="mx-auto max-w-lg px-4 py-16 text-center">
        <XCircle className="mx-auto text-gray" size={40} />
        <h1 className="mt-3 text-lg font-bold text-dark">Pedido não encontrado</h1>
        <p className="mt-1 text-sm text-gray">
          Confirme se tem sessão iniciada com a mesma conta com que fez o pedido.
        </p>
        <Link
          to={isAuthenticated ? '/pedidos' : '/login'}
          className="mt-6 inline-block rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white"
        >
          {isAuthenticated ? 'Ver os meus pedidos' : 'Iniciar sessão'}
        </Link>
      </div>
    )
  }

  const order = orderQuery.data
  const failed = isTerminalFailure(order.status)
  const current = customerStepIndex(order.status)
  const place = [order.neighborhood, order.address, order.municipality].filter(Boolean).join(', ')

  return (
    <div className="mx-auto max-w-lg px-4 py-6">
      <Link to={isAuthenticated ? '/pedidos' : '/'} className="inline-flex items-center gap-1 text-sm text-gray hover:text-dark">
        <ArrowLeft size={16} /> {isAuthenticated ? 'Os meus pedidos' : 'Início'}
      </Link>

      <div className="fx-glass fx-slide-next mt-4 rounded-3xl p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <p className="text-xs uppercase tracking-[0.25em] text-gray">Pedido</p>
            <p className="fx-gradient-text mt-0.5 text-2xl font-black tracking-wide">{order.reference}</p>
            <p className={`mt-2 inline-flex items-center gap-2 text-sm font-semibold ${failed ? 'text-danger' : 'text-primary'}`}>
              {!failed && order.status !== 'DELIVERED' && <span className="fx-live-dot" aria-hidden="true" />}
              {order.status_label}
            </p>
          </div>
          {/* Ilustração do estado atual (Outubro 2026). */}
          {failed ? (
            <CancelledArt className="h-20 w-20 shrink-0" />
          ) : (
            <OrderStageArt
              key={order.status}
              stage={order.status === 'DELIVERED' ? 'delivered' : (CUSTOMER_STEPS[Math.max(0, Math.min(current, 4))].key as DeliveryStage)}
              className="fx-slide-next h-20 w-20 shrink-0"
            />
          )}
        </div>

        {!failed && (
          <div className="mt-5">
            <DeliveryRoad
              progress={order.status === 'DELIVERED' ? 1 : order.status === 'OUT_FOR_DELIVERY' ? 0.62 : order.status === 'ASSIGNED' ? 0.3 : current * 0.1}
              moving={order.status === 'OUT_FOR_DELIVERY'}
              delivered={order.status === 'DELIVERED'}
            />
          </div>
        )}

        {failed ? (
          <div className="mt-5 flex items-start gap-3 rounded-xl bg-danger/5 p-4">
            <XCircle className="mt-0.5 shrink-0 text-danger" size={20} />
            <p className="text-sm text-dark">
              Este pedido foi {order.status === 'CANCELLED' ? 'cancelado' : 'marcado como não concluído'}. Se precisar de
              gás, pode fazer um novo pedido ou ligar para a nossa central.
            </p>
          </div>
        ) : (
          <ol className="mt-6 space-y-0" aria-label="Progresso do pedido">
            {CUSTOMER_STEPS.map((step, index) => {
              const done = index < current || order.status === 'DELIVERED'
              const active = index === current && order.status !== 'DELIVERED'
              const isLast = index === CUSTOMER_STEPS.length - 1
              return (
                <li key={step.key} className="relative flex gap-3 pb-6 last:pb-0">
                  {!isLast && (
                    <span
                      aria-hidden="true"
                      className={`absolute left-[13px] top-7 h-[calc(100%-1.5rem)] w-0.5 ${done ? 'fx-timeline-line bg-gradient-to-b from-primary to-secondary' : 'bg-gray/20'}`}
                      style={done ? { animationDelay: `${index * 0.15}s` } : undefined}
                    />
                  )}
                  <motion.span
                    initial={false}
                    animate={{ scale: active ? [1, 1.12, 1] : 1 }}
                    transition={{ repeat: active ? Infinity : 0, duration: 1.6 }}
                    className={`relative z-10 flex h-7 w-7 shrink-0 items-center justify-center rounded-full border-2 ${
                      done
                        ? 'border-primary bg-primary text-white'
                        : active
                          ? 'fx-step-dot is-active border-primary bg-white text-primary'
                          : 'border-gray/25 bg-white text-gray'
                    }`}
                  >
                    {done ? <Check size={14} strokeWidth={3} /> : <span className="h-2 w-2 rounded-full bg-current" />}
                  </motion.span>
                  <div className="pt-0.5">
                    <p className={`text-sm ${done || active ? 'font-semibold text-dark' : 'text-gray'}`}>{step.label}</p>
                    {active && step.key === 'on_the_way' && order.assigned_to && (
                      <p className="mt-1 flex items-center gap-2 text-xs text-gray">
                        <Avatar name={order.assigned_to} url={order.assigned_to_avatar_url} size="md" alt="" />
                        <span className="font-medium text-dark">{order.assigned_to}</span>
                      </p>
                    )}
                  </div>
                </li>
              )
            })}
          </ol>
        )}
      </div>

      <div className="fx-glass fx-slide-next mt-4 space-y-3 rounded-3xl p-5 text-sm">
        {place && (
          <p className="flex items-start gap-2 text-dark">
            <MapPin size={16} className="mt-0.5 shrink-0 text-gray" /> {place}
          </p>
        )}
        <p className="flex items-start gap-2 text-dark">
          <Package size={16} className="mt-0.5 shrink-0 text-gray" />
          {order.cylinder_types_confirmed ?? order.cylinder_type ?? 'Garrafa de gás'} · {order.quantity ?? 1} un.
        </p>
        {order.delivery_price != null && (
          <p className="font-semibold text-dark">
            Entrega: {order.delivery_price.toLocaleString('pt-AO', { minimumFractionDigits: 2 })} Kz
          </p>
        )}
      </div>

      {!failed && order.status !== 'DELIVERED' && <OrderPushPrompt reference={order.reference} phone={guestPhone} />}

      {order.status === 'DELIVERED' && order.can_rate && (
        <Link
          to={`/avaliar/${encodeURIComponent(order.reference)}`}
          state={{ phone: order.customer_phone ?? guestPhone }}
          className="mt-4 flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-4 py-3 text-sm font-semibold text-white"
        >
          <Star size={16} className="fill-white" /> Avaliar o condutor
        </Link>
      )}
      {order.status === 'DELIVERED' && order.driver_rating != null && (
        <p className="mt-4 flex items-center justify-center gap-1 text-sm text-gray">
          Avaliou a entrega:
          {[1, 2, 3, 4, 5].map((n) => (
            <Star key={n} size={16} className={n <= order.driver_rating! ? 'fill-amber-400 text-amber-400' : 'text-gray/30'} />
          ))}
        </p>
      )}

      {CENTRAL_CONTACT_NUMBERS[0] && !failed && order.status !== 'DELIVERED' && (
        <a
          href={`tel:${CENTRAL_CONTACT_NUMBERS[0].number}`}
          className="mt-4 flex items-center justify-center gap-2 rounded-xl border border-gray/20 bg-white px-4 py-3 text-sm font-semibold text-dark"
        >
          <Phone size={16} /> Falar com a central
        </a>
      )}
    </div>
  )
}
