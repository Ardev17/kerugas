import { useRealtimeStore } from '@/stores/realtimeStore'

/**
 * Velocidade (Outubro 2026): a ligação de tempo real guardada aqui, sem
 * importar `laravel-echo`/`pusher-js`. O store da sessão só precisa de a
 * fechar ao sair,assim essas bibliotecas (dezenas de KB) só descarregam
 * nas páginas que usam tempo real (mapa, chat, Vizinhos, painel admin),
 * e não no arranque de toda a gente. Ver lib/echo.ts.
 */
interface Disconnectable {
  disconnect: () => void
}

let current: Disconnectable | null = null

export function getEchoConnection<T extends Disconnectable>(): T | null {
  return current as T | null
}

export function setEchoConnection(echo: Disconnectable | null): void {
  current = echo
}

export function disconnectEcho(): void {
  current?.disconnect()
  current = null
  useRealtimeStore.setState({ connected: false })
}
