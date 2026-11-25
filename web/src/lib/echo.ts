import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { api } from '@/lib/api'
import { useRealtimeStore } from '@/stores/realtimeStore'
import { getEchoConnection, setEchoConnection } from '@/lib/echoConnection'

export { disconnectEcho } from '@/lib/echoConnection'

let warnedOnce = false

/**
 * Cliente WebSocket (Laravel Reverb) com inicialização preguiçosa.
 *
 * BUG CORRIGIDO: antes, esta função tentava sempre instanciar o Pusher,
 * mesmo sem VITE_REVERB_APP_KEY configurada,o construtor do Pusher
 * lança uma exceção síncrona nesse caso ("You must pass your app key"),
 * que ficava por apanhar e rebentava a página toda (Mapa e Admin) para
 * qualquer pessoa que não tivesse o Reverb configurado. Real-time é uma
 * funcionalidade opcional (Etapa 16),a ausência dela nunca deve
 * impedir o resto da aplicação de funcionar. Agora devolve `null`
 * quando não está configurado, e quem chama tem de verificar isso.
 *
 * BUGS CORRIGIDOS (Setembro 2026, varredura antes do app Android):
 * 1. `forceTLS: false` fixo,com o site agora em https://, o browser
 *    bloqueia ligações ws:// sem TLS (mixed content) e o tempo real
 *    deixava de funcionar em produção. Agora segue VITE_REVERB_SCHEME
 *    (https por omissão), e em https liga por wss:// na porta 443,
 *    através do nginx (ver location /app/ no nginx.conf).
 * 2. O token Bearer era copiado UMA vez, no momento em que o Echo era
 *    criado,quem abrisse o site como visitante e só depois fizesse
 *    login ficava com canais privados a falhar até recarregar a página
 *    (e no app Android, que fica aberto dias seguidos, isto era muito
 *    pior). Agora a autorização de cada canal passa pelo `authorizer`,
 *    que usa o cliente axios central,que já lê o token atual a cada
 *    pedido.
 * 3. O endpoint de autorização passou de `/broadcasting/auth` (que o
 *    nginx nem encaminhava para o PHP, e que autenticava por sessão)
 *    para `/api/broadcasting/auth` com `auth:sanctum`,ver
 *    bootstrap/app.php.
 */
export function getEcho(): Echo<'reverb'> | null {
  const existing = getEchoConnection<Echo<'reverb'>>()
  if (existing) {
    return existing
  }

  const key = import.meta.env.VITE_REVERB_APP_KEY

  if (!key) {
    if (!warnedOnce) {
      console.info(
        '[KERUGAS] Real-time (Reverb) não configurado,VITE_REVERB_APP_KEY em falta. ' +
        'A aplicação funciona normalmente sem isto; só não recebe atualizações automáticas ' +
        'do mapa/pedidos em tempo real. Ver apps/api/README.md, secção "Real-time" para ativar.'
      )
      warnedOnce = true
    }
    return null
  }

  // @ts-expect-error,Echo espera Pusher no objeto global quando se usa o broadcaster 'reverb'
  window.Pusher = Pusher

  const useTls = (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https'
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? (useTls ? 443 : 8080))

  const echoInstance = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: port,
    wssPort: port,
    forceTLS: useTls,
    enabledTransports: useTls ? ['wss'] : ['ws'],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: unknown) => void) => {
        api
          // baseURL vazio: o URL já vem completo de broadcastAuthUrl() —
          // sem isto, com um VITE_API_URL relativo (ex: "/api/v1"), o
          // axios juntava os dois e pedia "/api/v1/api/broadcasting/auth".
          .post(broadcastAuthUrl(), { socket_id: socketId, channel_name: channel.name }, { baseURL: '' })
          .then((response) => callback(null, response.data))
          .catch((error: Error) => callback(error, null))
      },
    }),
  })

  // Outubro 2026: o estado da ligação decide se os ecrãs precisam de se
  // atualizar sozinhos (ver stores/realtimeStore.ts).
  type Conn = { bind: (event: string, cb: (s: { current: string }) => void) => void; state?: string }
  const connection = (echoInstance.connector as { pusher?: { connection?: Conn } }).pusher?.connection
  connection?.bind('state_change', ({ current }) => useRealtimeStore.setState({ connected: current === 'connected' }))
  useRealtimeStore.setState({ connected: connection?.state === 'connected' })

  setEchoConnection(echoInstance)
  return echoInstance
}

/**
 * `/api/broadcasting/auth` fica FORA do prefixo `/v1` (é uma rota do
 * próprio Laravel, não da nossa API versionada),por isso deriva-se da
 * base configurada tirando o `/v1` final, em vez de a escrever à mão e
 * arriscar divergir entre web e app.
 */
function broadcastAuthUrl(): string {
  const base = (api.defaults.baseURL ?? '').replace(/\/v1\/?$/, '')
  return `${base}/broadcasting/auth`
}
