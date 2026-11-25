import { Link, useLocation } from 'react-router-dom'
import { Star, Truck, X } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useActiveOrder } from '@/hooks/useActiveOrder'
import { dismissRatingPrompt } from '@/lib/activeOrder'
import { isNativeApp } from '@/native/platform'

/**
 * Botão flutuante do pedido (Outubro 2026), sempre um destes dois:
 *
 * - "Acompanhar pedido": enquanto o pedido está em curso. Desaparece
 *   quando é entregue, cancelado ou falha.
 * - "Avaliar o condutor": depois de uma entrega por avaliar. Desaparece
 *   quando o cliente avalia, toca em "x" (Agora não) ou passa uma semana.
 *
 * Não aparece nas páginas para onde levaria (já lá está), nem nos
 * Vizinhos: lá tapava o botão "Enviar" da caixa de escrever (Outubro 2026).
 */
export function TrackOrderButton() {
  const order = useActiveOrder()
  const { pathname } = useLocation()
  const queryClient = useQueryClient()

  if (!order || pathname.startsWith('/pedido/') || pathname.startsWith('/avaliar/') || pathname === '/comunidade') return null

  const position = `fixed right-4 z-40 ${isNativeApp ? 'bottom-24' : 'bottom-5'}`

  if (order.kind === 'rate') {
    return (
      <div className={`${position} flex items-center rounded-full bg-amber-500 text-white shadow-lg`}>
        <Link
          to={`/avaliar/${encodeURIComponent(order.reference)}`}
          state={{ phone: order.phone }}
          aria-label="Avaliar o condutor"
          className="flex items-center gap-2 py-3 pl-5 pr-3 text-sm font-semibold"
        >
          <Star size={18} className="fill-white" aria-hidden="true" />
          <span className="flex flex-col items-start leading-tight">
            Avaliar o condutor
            <span className="text-[11px] font-normal opacity-90">{order.statusLabel}</span>
          </span>
        </Link>
        <button
          type="button"
          aria-label="Agora não"
          onClick={() => {
            dismissRatingPrompt(order.reference)
            void queryClient.invalidateQueries({ queryKey: ['active-order'] })
          }}
          className="mr-2 rounded-full p-1.5 hover:bg-black/10"
        >
          <X size={16} aria-hidden="true" />
        </button>
      </div>
    )
  }

  return (
    <Link
      to={`/pedido/${encodeURIComponent(order.reference)}`}
      aria-label={`Acompanhar pedido ${order.reference}: ${order.statusLabel}`}
      className={`${position} flex items-center gap-2 rounded-full bg-primary px-5 py-3 text-sm font-semibold text-white shadow-lg`}
    >
      <Truck size={18} aria-hidden="true" />
      <span className="flex flex-col items-start leading-tight">
        Acompanhar pedido
        <span className="text-[11px] font-normal opacity-90">{order.statusLabel}</span>
      </span>
    </Link>
  )
}
