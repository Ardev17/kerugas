import { api } from '@/lib/api'
import { hasNativePlugin, nativePlatform } from '@/native/platform'

/**
 * Notificações push do app Android (Setembro 2026),Firebase Cloud
 * Messaging via @capacitor/push-notifications.
 *
 * Fluxo: pede autorização (obrigatória a partir do Android 13), cria os
 * canais, regista o telemóvel no Firebase e envia o token para a nossa
 * API (`POST /devices`). A partir daí, cada notificação que o backend
 * cria para esta pessoa chega também como push (AppNotificationObserver).
 *
 * Sem `google-services.json` no projeto Android, o registo falha em
 * silêncio e o app funciona normalmente,só sem push (ver ANDROID.md).
 */

export interface ForegroundPush {
  title: string
  body: string
  path: string
}

type ForegroundListener = (push: ForegroundPush) => void
const foregroundListeners = new Set<ForegroundListener>()

/** Push recebida com o app aberto (o Android não a mostra sozinho nesse caso). */
export function onForegroundPush(listener: ForegroundListener): () => void {
  foregroundListeners.add(listener)
  return () => foregroundListeners.delete(listener)
}

/**
 * Canais de notificação,o utilizador pode silenciar cada um em
 * separado nas definições do Android (ex: ficar com "Pedidos" e
 * silenciar "Alertas de gás"). Os IDs têm de coincidir com
 * App\Services\Push\PushMessage::CHANNEL_* no backend.
 */
const CHANNELS = [
  {
    id: 'orders',
    name: 'Pedidos e entregas',
    description: 'Pedidos atribuídos, confirmados, a caminho e entregues.',
    importance: 5 as const,
    // Privado no ecrã de bloqueio: estas notificações podem trazer nomes
    // e moradas de clientes.
    visibility: 0 as const,
  },
  {
    id: 'alerts',
    name: 'Alertas de gás',
    description: 'Quando um posto que segue volta a ter gás.',
    importance: 4 as const,
    visibility: 1 as const,
  },
  {
    id: 'reminders',
    name: 'Lembretes',
    description: 'O seu gás está a acabar, e lembretes da equipa (operadores e condutores).',
    importance: 4 as const,
    visibility: 1 as const,
  },
  {
    id: 'tips',
    name: 'Dicas de segurança',
    description: 'Uma dica útil por mês para usar o gás em segurança.',
    importance: 3 as const,
    visibility: 1 as const,
  },
  {
    id: 'promos',
    name: 'Novidades e promoções',
    description: 'Fim de semana, datas especiais e novidades do KeruGás.',
    importance: 3 as const,
    visibility: 1 as const,
  },
  {
    id: 'general',
    name: 'Geral',
    description: 'Avisos gerais do KeruGás.',
    importance: 3 as const,
    visibility: 1 as const,
  },
]

/**
 * Liga o push para a sessão atual. Devolve a função de limpeza (usada
 * pelo <NativeAppShell> quando a pessoa termina a sessão).
 */
export function initPushNotifications(onTap: (path: string) => void): () => void {
  if (!hasNativePlugin('PushNotifications')) {
    return () => {}
  }

  let disposed = false
  const handles: Array<{ remove: () => Promise<void> }> = []

  void (async () => {
    const { PushNotifications } = await import('@capacitor/push-notifications')
    if (disposed) return

    // Cada ouvinte é guardado assim que existe e, se a sessão terminou
    // entretanto (logout durante o arranque), removido de imediato —
    // senão, após um novo login, ficariam ouvintes em duplicado e cada
    // toque numa notificação navegaria duas vezes.
    const keep = async (pending: Promise<{ remove: () => Promise<void> }>) => {
      const handle = await pending
      if (disposed) {
        void handle.remove()
        throw new Error('push-disposed')
      }
      handles.push(handle)
    }

    await keep(PushNotifications.addListener('registration', ({ value }) => {
      void registerDevice(value)
    }))
    await keep(PushNotifications.addListener('registrationError', (error) => {
      console.warn('[KERUGAS] Registo de push falhou (google-services.json / GoogleService-Info.plist em falta?)', error)
    }))
    await keep(PushNotifications.addListener('pushNotificationReceived', (notification) => {
      const push: ForegroundPush = {
        title: notification.title ?? 'KeruGás',
        body: notification.body ?? '',
        path: String(notification.data?.path ?? '/'),
      }
      foregroundListeners.forEach((listener) => listener(push))
    }))
    await keep(PushNotifications.addListener('pushNotificationActionPerformed', ({ notification }) => {
      onTap(String(notification.data?.path ?? '/'))
    }))

    for (const channel of CHANNELS) {
      await PushNotifications.createChannel(channel).catch(() => {})
    }

    let permission = await PushNotifications.checkPermissions()
    if (permission.receive === 'prompt' || permission.receive === 'prompt-with-rationale') {
      permission = await PushNotifications.requestPermissions()
    }
    if (permission.receive !== 'granted' || disposed) return

    await PushNotifications.register()
  })().catch((error) => {
    if (error instanceof Error && error.message === 'push-disposed') return
    console.warn('[KERUGAS] Push indisponível', error)
  })

  return () => {
    disposed = true
    handles.forEach((handle) => void handle.remove())
  }
}

async function registerDevice(token: string): Promise<void> {
  let appVersion: string | undefined
  try {
    const { App } = await import('@capacitor/app')
    appVersion = (await App.getInfo()).version
  } catch {
    appVersion = undefined
  }

  try {
    await api.post('/devices', { token, platform: nativePlatform ?? 'android', app_version: appVersion })
  } catch (error) {
    // Nova tentativa acontece sozinha: o Firebase volta a emitir o token
    // no próximo arranque do app.
    console.warn('[KERUGAS] Não foi possível registar o telemóvel para push', error)
  }
}
