import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
const use = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', () => ({ api: { get, defaults: { headers: { common: {} as Record<string, string> } }, interceptors: { response: { use } } } }))
vi.mock('@/native/platform', () => ({ isNativeApp: true, nativePlatform: 'ios', hasNativePlugin: () => true }))
vi.mock('@capacitor/app', () => ({ App: { getInfo: async () => ({ version: '1.1.0', build: '3' }) } }))

import { api } from '@/lib/api'
import { checkForAppUpdate, initAppVersion } from '../appVersion'
import { useAppUpdateStore } from '@/stores/appUpdateStore'

const APPLE = 'https://apps.apple.com/ao/app/kerugas/id0000000000'
const PLAY = 'https://play.google.com/store/apps/details?id=ao.kerugas.app'

describe('versão do app,iPhone', () => {
  beforeEach(() => useAppUpdateStore.setState({ required: false, available: false, dismissed: false, storeUrl: null }))

  it('identifica-se como ios em cada pedido', async () => {
    await initAppVersion()
    expect(api.defaults.headers.common['X-App-Platform']).toBe('ios')
    expect(api.defaults.headers.common['X-App-Version']).toBe('1.1.0')
  })

  it('compara com a configuração do iPhone (não com a do Android) e abre a App Store', async () => {
    await initAppVersion()
    get.mockResolvedValue({
      data: { data: {
        android: { min_version: '9.0.0', latest_version: '9.0.0', store_url: PLAY },
        ios: { min_version: '1.0.0', latest_version: '1.2.0', store_url: APPLE, published: true },
      } },
    })
    await checkForAppUpdate()
    expect(useAppUpdateStore.getState()).toMatchObject({ required: false, available: true, storeUrl: APPLE })

    get.mockResolvedValue({ data: { data: { ios: { min_version: '1.2.0', latest_version: '1.2.0', store_url: APPLE } } } })
    await checkForAppUpdate()
    expect(useAppUpdateStore.getState()).toMatchObject({ required: true, storeUrl: APPLE })
  })

  it('sem versões do iPhone no servidor não bloqueia nem avisa', async () => {
    await initAppVersion()
    get.mockResolvedValue({ data: { data: { ios: { published: false, store_url: null } } } })
    await checkForAppUpdate()
    expect(useAppUpdateStore.getState()).toMatchObject({ required: false, available: false })
  })

  it('ignora um link de loja que não seja da Apple nem da Google', async () => {
    await initAppVersion()
    get.mockResolvedValue({ data: { data: { ios: { min_version: '2.0.0', latest_version: '2.0.0', store_url: 'https://evil.example/app' } } } })
    await checkForAppUpdate()
    expect(useAppUpdateStore.getState().storeUrl).toBe(PLAY) // rede de segurança por omissão
  })
})
