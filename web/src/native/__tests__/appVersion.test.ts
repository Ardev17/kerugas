import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.hoisted(() => vi.fn())
const use = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', () => ({ api: { get, defaults: { headers: { common: {} as Record<string, string> } }, interceptors: { response: { use } } } }))
vi.mock('@/native/platform', () => ({ isNativeApp: true, nativePlatform: 'android', hasNativePlugin: () => true }))
vi.mock('@capacitor/app', () => ({ App: { getInfo: async () => ({ version: '1.1.0', build: '3' }) } }))

import { api } from '@/lib/api'
import { checkForAppUpdate, initAppVersion } from '../appVersion'
import { useAppUpdateStore } from '@/stores/appUpdateStore'

const STORE = 'https://play.google.com/store/apps/details?id=ao.kerugas.app'

describe('versão do app', () => {
  beforeEach(() => useAppUpdateStore.setState({ required: false, available: false, dismissed: false, storeUrl: null }))

  it('envia a versão em cada pedido e reage ao 426', async () => {
    await initAppVersion()
    expect(api.defaults.headers.common['X-App-Version']).toBe('1.1.0')
    expect(api.defaults.headers.common['X-App-Platform']).toBe('android')

    const onError = use.mock.calls[0][1] as (e: unknown) => Promise<unknown>
    await onError({ isAxiosError: true, response: { status: 426, data: { data: { store_url: STORE } } } }).catch(() => {})
    expect(useAppUpdateStore.getState().required).toBe(true)
  })

  it('compara com a configuração do servidor', async () => {
    get.mockResolvedValue({ data: { data: { android: { min_version: '1.0.0', latest_version: '1.2.0', store_url: STORE } } } })
    await checkForAppUpdate()
    expect(useAppUpdateStore.getState()).toMatchObject({ required: false, available: true })

    get.mockResolvedValue({ data: { data: { android: { min_version: '1.2.0', latest_version: '1.2.0', store_url: STORE } } } })
    await checkForAppUpdate()
    expect(useAppUpdateStore.getState().required).toBe(true)
  })
})
