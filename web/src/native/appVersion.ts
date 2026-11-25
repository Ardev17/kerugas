import axios from 'axios'
import { api } from '@/lib/api'
import { compareVersions } from '@/lib/version'
import { isNativeApp, hasNativePlugin, nativePlatform } from '@/native/platform'
import { useAppUpdateStore } from '@/stores/appUpdateStore'

/**
 * Versão do app (Android e iOS) e atualização obrigatória (Outubro 2026).
 *
 * - Cada pedido à API leva `X-App-Platform: android|ios` e `X-App-Version`; se o
 *   servidor responder 426, o app mostra o ecrã "Atualize a KeruGás".
 * - Ao abrir e ao voltar ao primeiro plano, compara com /app/config.
 * No site não faz nada.
 */
let appVersion: string | null = null

export function getAppVersion(): string | null {
  return appVersion
}

export async function initAppVersion(): Promise<void> {
  if (!isNativeApp) return

  api.interceptors.response.use(undefined, (error: unknown) => {
    if (axios.isAxiosError(error) && error.response?.status === 426) {
      const data = error.response.data as { data?: { store_url?: string; update_url?: string } } | undefined
      useAppUpdateStore.getState().setRequired(data?.data?.update_url ?? data?.data?.store_url ?? null)
    }
    return Promise.reject(error)
  })

  if (!hasNativePlugin('App')) return
  try {
    const { App } = await import('@capacitor/app')
    appVersion = (await App.getInfo()).version
    api.defaults.headers.common['X-App-Platform'] = nativePlatform ?? 'android'
    api.defaults.headers.common['X-App-Version'] = appVersion
  } catch {
    appVersion = null
  }
}

interface PlatformConfig {
  min_version?: string
  latest_version?: string
  store_url?: string | null
  /** Para onde o aviso "Atualize" leva: loja ou, antes dela, o APK de teste (Outubro 2026). */
  update_url?: string | null
  published?: boolean
}

/** Pergunta ao servidor qual a versão mínima/mais recente. Falhas de rede são ignoradas. */
export async function checkForAppUpdate(): Promise<void> {
  if (!isNativeApp || !appVersion) return
  try {
    const { data } = await api.get<{ data: { android?: PlatformConfig; ios?: PlatformConfig } }>('/app/config')
    const cfg = data.data[nativePlatform ?? 'android']
    if (!cfg?.min_version || !cfg.latest_version) return
    const store = useAppUpdateStore.getState()
    if (compareVersions(appVersion, cfg.min_version) === -1) {
      store.setRequired(cfg.update_url ?? cfg.store_url ?? null)
    } else {
      store.setAvailable(compareVersions(appVersion, cfg.latest_version) === -1, cfg.update_url ?? cfg.store_url ?? null)
    }
  } catch {
    // sem rede: tenta-se de novo quando o app voltar ao primeiro plano
  }
}
