import { CapacitorHttp, registerPlugin } from '@capacitor/core'
import type { BackgroundGeolocationPlugin, Location } from '@capacitor-community/background-geolocation'
import { api } from '@/lib/api'
import { getToken } from '@/lib/tokenStorage'
import { hasNativePlugin, isNativeApp } from '@/native/platform'

/**
 * Localização do condutor (Novembro 2026).
 *
 * - App (Android/iPhone): partilha também com a app em segundo plano, com
 *   uma notificação fixa "KeruGás está a partilhar a sua localização". Se a
 *   app for fechada à força (deslizar para fora da lista de apps) o sistema
 *   pára o envio,o admin passa a ver "sem localização".
 * - Site: só enquanto a página estiver aberta.
 * - Só a última posição fica guardada; só o Super Admin geral e o Provincial
 *   a veem, para escolher o condutor mais perto do cliente. Nunca há
 *   atribuição automática.
 * - Sem internet o envio falha em silêncio; passados 15 minutos sem posição
 *   o condutor aparece "sem localização".
 */

export const DRIVER_LOCATION_CONSENT_KEY = 'kerugas_driver_location_consent'

/** Intervalo mínimo entre envios (ms),poupa bateria e dados. */
export const MIN_SEND_INTERVAL_MS = 30_000
const WEB_SEND_INTERVAL_MS = 60_000

export type DriverLocationState = 'off' | 'active' | 'denied' | 'error'

export function hasDriverLocationConsent(): boolean {
  try {
    return localStorage.getItem(DRIVER_LOCATION_CONSENT_KEY) === '1'
  } catch {
    return false
  }
}

export function setDriverLocationConsent(value: boolean): void {
  try {
    if (value) localStorage.setItem(DRIVER_LOCATION_CONSENT_KEY, '1')
    else localStorage.removeItem(DRIVER_LOCATION_CONSENT_KEY)
  } catch {
    // sem armazenamento: volta a perguntar da próxima vez
  }
}

interface Fix {
  latitude: number
  longitude: number
  accuracy?: number | null
  time?: number | null
}

function payload(fix: Fix) {
  return {
    latitude: fix.latitude,
    longitude: fix.longitude,
    accuracy: fix.accuracy ?? null,
    recorded_at: Math.round(fix.time ?? Date.now()),
  }
}

/**
 * Envio nativo: em segundo plano o WebView abranda os pedidos HTTP ao fim de
 * alguns minutos, por isso no app o pedido sai pelo HTTP nativo.
 */
async function sendNative(fix: Fix): Promise<void> {
  const token = getToken()
  if (!token) return
  const base = String(api.defaults.baseURL ?? '').replace(/\/$/, '')
  await CapacitorHttp.post({
    url: `${base}/driver/location`,
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    data: payload(fix),
  })
}

async function sendWeb(fix: Fix): Promise<void> {
  await api.post('/driver/location', payload(fix))
}

/** Decide se uma posição nova deve ser enviada (intervalo mínimo). */
export function shouldSend(lastSentAt: number | null, now: number, minInterval = MIN_SEND_INTERVAL_MS): boolean {
  return lastSentAt === null || now - lastSentAt >= minInterval
}

/**
 * Começa a partilhar. Devolve a função que pára. `onState` informa o ecrã
 * (ativo, permissão recusada, erro).
 */
export function startDriverLocation(onState: (s: DriverLocationState) => void = () => {}): () => void {
  let stopped = false
  let lastSentAt: number | null = null

  const handle = (fix: Fix, send: (f: Fix) => Promise<void>, minInterval: number) => {
    const now = Date.now()
    if (!shouldSend(lastSentAt, now, minInterval)) return
    lastSentAt = now
    send(fix).catch(() => {
      // Sem internet: tenta outra vez na próxima posição.
      lastSentAt = null
    })
  }

  if (isNativeApp && hasNativePlugin('BackgroundGeolocation')) {
    const plugin = registerPlugin<BackgroundGeolocationPlugin>('BackgroundGeolocation')
    let watcherId: string | null = null

    plugin
      .addWatcher(
        {
          backgroundTitle: 'KeruGás,condutor',
          backgroundMessage: 'A partilhar a sua localização para receber entregas perto de si.',
          requestPermissions: true,
          stale: false,
          distanceFilter: 50,
        },
        (position?: Location, error?: { code?: string }) => {
          if (error) {
            onState(error.code === 'NOT_AUTHORIZED' ? 'denied' : 'error')
            return
          }
          if (!position) return
          onState('active')
          handle(position, sendNative, MIN_SEND_INTERVAL_MS)
        },
      )
      .then((id) => {
        if (stopped) void plugin.removeWatcher({ id })
        else watcherId = id
      })
      .catch(() => onState('error'))

    return () => {
      stopped = true
      if (watcherId) void plugin.removeWatcher({ id: watcherId })
      onState('off')
    }
  }

  if (typeof navigator === 'undefined' || !navigator.geolocation) {
    onState('error')
    return () => {}
  }

  const id = navigator.geolocation.watchPosition(
    (pos) => {
      onState('active')
      handle(
        { latitude: pos.coords.latitude, longitude: pos.coords.longitude, accuracy: pos.coords.accuracy, time: pos.timestamp },
        sendWeb,
        WEB_SEND_INTERVAL_MS,
      )
    },
    (err) => onState(err.code === err.PERMISSION_DENIED ? 'denied' : 'error'),
    { enableHighAccuracy: true, maximumAge: 30_000, timeout: 60_000 },
  )

  return () => {
    stopped = true
    navigator.geolocation.clearWatch(id)
    onState('off')
  }
}

/** Abre as definições do telemóvel (permissão recusada). */
export async function openLocationSettings(): Promise<void> {
  if (!isNativeApp || !hasNativePlugin('BackgroundGeolocation')) return
  await registerPlugin<BackgroundGeolocationPlugin>('BackgroundGeolocation').openSettings()
}
