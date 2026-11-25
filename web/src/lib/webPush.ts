import { api } from '@/lib/api'
import { isNativeApp } from '@/native/platform'
import type { ApiSuccess } from '@/types'

/**
 * Web Push no navegador (Setembro 2026),notificações do KeruGás no
 * computador ou no telemóvel mesmo com o site fechado. Usa as chaves
 * VAPID do servidor (`php artisan kerugas:vapid`). No app Android não se
 * usa: lá as notificações chegam pelo Firebase (src/native/push.ts).
 */
export type WebPushStatus = 'unsupported' | 'unavailable' | 'denied' | 'enabled' | 'available'

export function isWebPushSupported(): boolean {
  return !isNativeApp
    && typeof window !== 'undefined'
    && 'serviceWorker' in navigator
    && 'PushManager' in window
    && 'Notification' in window
}

async function serverKey(): Promise<string | null> {
  const { data } = await api.get<ApiSuccess<{ enabled: boolean; public_key: string | null }>>('/web-push/public-key')
  return data.data.enabled ? data.data.public_key : null
}

async function registration(): Promise<ServiceWorkerRegistration> {
  return navigator.serviceWorker.register('/sw.js')
}

export async function getWebPushStatus(): Promise<WebPushStatus> {
  if (!isWebPushSupported()) return 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  if (!(await serverKey())) return 'unavailable'
  const existing = await (await registration()).pushManager.getSubscription()
  return existing && Notification.permission === 'granted' ? 'enabled' : 'available'
}

/** Pede autorização (tem de vir de um clique) e inscreve este navegador. */
export async function enableWebPush(): Promise<WebPushStatus> {
  if (!isWebPushSupported()) return 'unsupported'
  const key = await serverKey()
  if (!key) return 'unavailable'

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'available'

  await subscribe(key)
  return 'enabled'
}

export async function disableWebPush(): Promise<void> {
  if (!isWebPushSupported()) return
  const subscription = await (await registration()).pushManager.getSubscription()
  if (!subscription) return
  await api.delete('/web-push/subscriptions', { data: { endpoint: subscription.endpoint } }).catch(() => {})
  await subscription.unsubscribe()
}

/**
 * Depois de cada login, volta a ligar este navegador à sessão nova,sem
 * perguntar nada, e só se a pessoa já tinha autorizado antes. (Ao
 * terminar sessão, o servidor desliga-o dessa conta.)
 */
export async function resyncWebPush(): Promise<void> {
  if (!isWebPushSupported() || Notification.permission !== 'granted') return
  const key = await serverKey().catch(() => null)
  if (key) await subscribe(key)
}

/** Obtém (ou cria) a subscrição push deste navegador, sem a enviar ao servidor. */
async function browserSubscription(key: string): Promise<PushSubscription> {
  const reg = await registration()
  await navigator.serviceWorker.ready

  let subscription = await reg.pushManager.getSubscription()
  // Chave do servidor mudou (chaves VAPID novas) → a inscrição antiga já
  // não serve; é preciso uma nova.
  if (subscription && !sameKey(subscription.options.applicationServerKey, key)) {
    await subscription.unsubscribe()
    subscription = null
  }
  subscription ??= await reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: base64UrlToBytes(key),
  })
  return subscription
}

function subscriptionBody(subscription: PushSubscription) {
  const json = subscription.toJSON()
  const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings ?? []
  return {
    endpoint: json.endpoint,
    keys: json.keys,
    content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
  }
}

async function subscribe(key: string): Promise<void> {
  await api.post('/web-push/subscriptions', subscriptionBody(await browserSubscription(key)))
}

/* ------------------------------------------------------------------ */
/* Avisos de UM pedido para quem NÃO tem conta (Outubro 2026).         */
/* Prova de posse = referência + telefone do pedido, como no           */
/* acompanhamento. A subscrição vive no servidor ligada ao pedido e    */
/* apaga-se quando o pedido termina.                                   */
/* ------------------------------------------------------------------ */

const ORDER_PUSH_KEY = 'kerugas.orderPush'

function readOrderPushFlags(): string[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(ORDER_PUSH_KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed.filter((v): v is string => typeof v === 'string') : []
  } catch {
    return []
  }
}

function writeOrderPushFlags(flags: string[]): void {
  try {
    // Só as últimas 20 referências: nunca cresce sem limite.
    localStorage.setItem(ORDER_PUSH_KEY, JSON.stringify(flags.slice(-20)))
  } catch {
    /* modo privado: só não lembra que já ativou */
  }
}

export async function getOrderPushStatus(reference: string): Promise<WebPushStatus> {
  if (!isWebPushSupported()) return 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  if (!(await serverKey())) return 'unavailable'
  return Notification.permission === 'granted' && readOrderPushFlags().includes(reference) ? 'enabled' : 'available'
}

/** Pede autorização (tem de vir de um clique) e subscreve os avisos deste pedido. */
export async function enableOrderPush(reference: string, customerPhone: string): Promise<WebPushStatus> {
  if (!isWebPushSupported()) return 'unsupported'
  const key = await serverKey()
  if (!key) return 'unavailable'

  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'available'

  const subscription = await browserSubscription(key)
  await api.post(`/orders/reference/${encodeURIComponent(reference)}/push`, {
    customer_phone: customerPhone,
    ...subscriptionBody(subscription),
  })
  writeOrderPushFlags([...readOrderPushFlags().filter((r) => r !== reference), reference])
  return 'enabled'
}

export async function disableOrderPush(reference: string, customerPhone: string): Promise<void> {
  if (isWebPushSupported()) {
    const subscription = await (await registration()).pushManager.getSubscription()
    if (subscription) {
      await api
        .delete(`/orders/reference/${encodeURIComponent(reference)}/push`, {
          data: { customer_phone: customerPhone, endpoint: subscription.endpoint },
        })
        .catch(() => {})
    }
  }
  writeOrderPushFlags(readOrderPushFlags().filter((r) => r !== reference))
}

function base64UrlToBytes(value: string): Uint8Array<ArrayBuffer> {
  const padded = (value + '='.repeat((4 - (value.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return bytes
}

function sameKey(current: ArrayBuffer | null, key: string): boolean {
  if (!current) return false
  const a = new Uint8Array(current)
  const b = base64UrlToBytes(key)
  return a.length === b.length && a.every((byte, i) => byte === b[i])
}
