import { useQuery } from '@tanstack/react-query'
import { useAuth } from '@/hooks/useAuth'
import { gasOrderService } from '@/services/gasOrderService'
import type { GasOrder } from '@/types'
import {
  RATING_PROMPT_DAYS,
  clearActiveOrder,
  isOrderFinished,
  isRatingPromptDismissed,
  readActiveOrder,
} from '@/lib/activeOrder'

export interface ActiveOrder {
  reference: string
  statusLabel: string
  /** `track` = pedido em curso; `rate` = entregue, falta avaliar o condutor. */
  kind: 'track' | 'rate'
  /** Telefone do pedido (só quando o aparelho o guardou),pré-preenche a avaliação. */
  phone?: string
}

function recentEnough(order: Pick<GasOrder, 'completed_at' | 'created_at'>): boolean {
  const when = new Date(order.completed_at ?? order.created_at).getTime()
  return Date.now() - when < RATING_PROMPT_DAYS * 86_400_000
}

/**
 * O pedido a que o botão flutuante se refere (Outubro 2026):
 *
 * 1. um pedido ainda em curso -> "Acompanhar pedido";
 * 2. senão, uma entrega recente por avaliar -> "Avaliar o condutor";
 * 3. senão `null` e o botão desaparece.
 *
 * Com conta, vem dos pedidos da conta. Sem conta, do pedido guardado
 * neste aparelho, confirmado na API com referência + telefone,e só é
 * esquecido quando já não há nada a acompanhar nem a avaliar.
 */
export function useActiveOrder(): ActiveOrder | null {
  const { isAuthenticated } = useAuth()

  const query = useQuery({
    queryKey: ['active-order', isAuthenticated],
    queryFn: async (): Promise<ActiveOrder | null> => {
      if (isAuthenticated) {
        const { data } = await gasOrderService.listMine(1)
        const open = data.find((o) => !isOrderFinished(o.status))
        if (open) return { reference: open.reference, statusLabel: open.status_label, kind: 'track', phone: open.customer_phone }
        const toRate = data.find((o) => o.can_rate && recentEnough(o) && !isRatingPromptDismissed(o.reference))
        return toRate ? { reference: toRate.reference, statusLabel: 'Como foi a entrega?', kind: 'rate', phone: toRate.customer_phone } : null
      }

      const stored = readActiveOrder()
      if (!stored) return null
      try {
        const order = await gasOrderService.trackByPhone(stored.reference, stored.phone)
        if (!isOrderFinished(order.status)) {
          return { reference: order.reference, statusLabel: order.status_label, kind: 'track', phone: stored.phone }
        }
        if (order.can_rate && recentEnough(order) && !isRatingPromptDismissed(order.reference)) {
          return { reference: order.reference, statusLabel: 'Como foi a entrega?', kind: 'rate', phone: stored.phone }
        }
        // Terminou e não há nada a avaliar (cancelado, já avaliado, sem condutor, dispensado).
        clearActiveOrder()
        return null
      } catch (e) {
        // 404/422 = a API não reconhece este par: deixa de fazer sentido
        // guardá-lo. Falhas de rede não apagam nada.
        const status = (e as { response?: { status?: number } }).response?.status
        if (status === 404 || status === 422) clearActiveOrder()
        return null
      }
    },
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    retry: false,
  })

  return query.data ?? null
}
