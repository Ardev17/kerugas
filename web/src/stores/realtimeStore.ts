import { create } from 'zustand'

/**
 * Estado da ligação de tempo real (Laravel Reverb), Outubro 2026. Quando
 * não está ligada (Reverb não configurado, servidor em baixo, rede fraca),
 * os ecrãs importantes passam a atualizar-se sozinhos de X em X segundos —
 * ver useLiveRefetchInterval.
 */
export const useRealtimeStore = create<{ connected: boolean }>(() => ({ connected: false }))

/**
 * Intervalo de atualização automática de um ecrã: o pedido `ms` quando o
 * tempo real não está ligado; com tempo real ligado, só uma verificação de
 * segurança espaçada (5×), para nunca ficar desatualizado se um evento se
 * perder.
 */
export function useLiveRefetchInterval(ms: number): number {
  const connected = useRealtimeStore((s) => s.connected)
  return connected ? ms * 5 : ms
}
