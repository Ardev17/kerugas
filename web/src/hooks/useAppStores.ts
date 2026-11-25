import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export interface StoreInfo {
  /** A app já está na loja? Enquanto não, o botão não tem link. */
  published: boolean
  storeUrl: string | null
  /**
   * Versão de TESTE (só Android, Outubro 2026): link direto para o APK,
   * enquanto o app ainda não está na Play Store. Só existe quando o servidor
   * tem APP_ANDROID_APK_URL (https).
   */
  apkUrl?: string
  apkVersion?: string
}

export type Store = 'android' | 'ios'

export interface AppStores {
  android: StoreInfo
  ios: StoreInfo
}

interface RawStore {
  published?: boolean
  store_url?: string | null
  apk_url?: string | null
  apk_version?: string | null
}

function toInfo(raw: RawStore | undefined, prefix: string): StoreInfo {
  const url = raw?.store_url
  const published = raw?.published === true && typeof url === 'string' && url.startsWith(prefix)
  return { published, storeUrl: published ? (url as string) : null }
}

/** Android: além da loja, o APK de teste (só https, só enquanto não há loja). */
function toAndroidInfo(raw: RawStore | undefined): StoreInfo {
  const info = toInfo(raw, 'https://')
  const apk = raw?.apk_url
  if (!info.published && typeof apk === 'string' && apk.startsWith('https://')) {
    info.apkUrl = apk
    if (typeof raw?.apk_version === 'string' && raw.apk_version.trim()) info.apkVersion = raw.apk_version.trim()
  }
  return info
}

/** Há alguma forma de instalar no Android: loja publicada ou APK de teste. */
export function hasAndroidDownload(info: StoreInfo): boolean {
  return info.published || !!info.apkUrl
}

/**
 * Estado das lojas (Google Play e App Store), vindo de /app/config (público).
 * Quem decide é o servidor (`APP_ANDROID_PUBLISHED`, `APP_IOS_PUBLISHED`): no
 * dia da publicação basta ligar a variável,o site não precisa de ser
 * reconstruído. Falha de rede = "ainda não publicado" (nunca um link morto).
 */
export function useAppStores(): AppStores {
  const query = useQuery({
    queryKey: ['app-config', 'stores'],
    queryFn: async () => {
      const { data } = await api.get<{ data: { android?: RawStore; ios?: RawStore } }>('/app/config')
      return data.data
    },
    staleTime: 10 * 60 * 1000,
    retry: false,
  })
  return {
    android: toAndroidInfo(query.data?.android),
    ios: toInfo(query.data?.ios, 'https://apps.apple.com/'),
  }
}

/**
 * Loja certa para quem está a ver: iPhone → App Store, Android → Google
 * Play; no computador, a que já estiver publicada (Google Play primeiro).
 */
export function pickStore(stores: AppStores, device: { ios: boolean; android: boolean }): Store | null {
  if (device.ios) return stores.ios.published ? 'ios' : null
  // Android: a loja, ou,antes dela,a versão de teste (APK).
  if (device.android) return hasAndroidDownload(stores.android) ? 'android' : null
  if (stores.android.published) return 'android'
  if (stores.ios.published) return 'ios'
  return null
}
