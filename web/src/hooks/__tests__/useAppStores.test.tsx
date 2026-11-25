import { describe, expect, it, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'

const get = vi.fn()
vi.mock('@/lib/api', () => ({ api: { get: (...a: unknown[]) => get(...a) } }))

import { pickStore, useAppStores } from '../useAppStores'

let client: QueryClient
function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

const off = { published: false, storeUrl: null }
const play = { published: true, storeUrl: 'https://play.google.com/x' }
const apple = { published: true, storeUrl: 'https://apps.apple.com/ao/app/x' }

describe('useAppStores', () => {
  beforeEach(() => {
    get.mockReset()
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  it('lê as duas lojas; só conta como publicada com link certo', async () => {
    get.mockResolvedValue({ data: { data: {
      android: { published: true, store_url: 'https://play.google.com/x' },
      ios: { published: true, store_url: 'https://exemplo.com/falso' },
    } } })
    const { result } = renderHook(() => useAppStores(), { wrapper })
    await waitFor(() => expect(result.current.android.published).toBe(true))
    expect(result.current.ios).toEqual(off)
  })

  it('falha de rede = nenhuma loja', async () => {
    get.mockRejectedValue(new Error('rede'))
    const { result } = renderHook(() => useAppStores(), { wrapper })
    await waitFor(() => expect(client.getQueryState(['app-config', 'stores'])?.status).toBe('error'))
    expect(result.current).toEqual({ android: off, ios: off })
  })
})

describe('pickStore', () => {
  it('iPhone só vê a App Store; Android só o Google Play', () => {
    expect(pickStore({ android: play, ios: off }, { ios: true, android: false })).toBeNull()
    expect(pickStore({ android: play, ios: apple }, { ios: true, android: false })).toBe('ios')
    expect(pickStore({ android: off, ios: apple }, { ios: false, android: true })).toBeNull()
  })
  it('no computador: a que estiver publicada, Google Play primeiro', () => {
    expect(pickStore({ android: play, ios: apple }, { ios: false, android: false })).toBe('android')
    expect(pickStore({ android: off, ios: apple }, { ios: false, android: false })).toBe('ios')
    expect(pickStore({ android: off, ios: off }, { ios: false, android: false })).toBeNull()
  })
})

describe('versão de teste (APK) antes da Play Store', () => {
  beforeEach(() => {
    get.mockReset()
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  it('lê o APK (só https) enquanto a loja não está publicada', async () => {
    get.mockResolvedValue({ data: { data: { android: { published: false, store_url: 'https://play.google.com/x', apk_url: 'https://github.com/a/b/releases/latest/download/kerugas.apk', apk_version: '1.0.3-teste' } } } })
    const { result } = renderHook(() => useAppStores(), { wrapper })
    await waitFor(() => expect(result.current.android.apkUrl).toBe('https://github.com/a/b/releases/latest/download/kerugas.apk'))
    expect(result.current.android.published).toBe(false)
    expect(result.current.android.apkVersion).toBe('1.0.3-teste')
  })

  it('ignora um APK sem https', async () => {
    get.mockResolvedValue({ data: { data: { android: { published: false, apk_url: 'http://x.ao/a.apk' } } } })
    const { result } = renderHook(() => useAppStores(), { wrapper })
    await waitFor(() => expect(client.getQueryState(['app-config', 'stores'])?.status).toBe('success'))
    expect(result.current.android).toEqual(off)
  })

  it('no Android, o APK de teste conta como forma de instalar; no iPhone não', () => {
    const apk = { published: false, storeUrl: null, apkUrl: 'https://github.com/x.apk' }
    expect(pickStore({ android: apk, ios: off }, { ios: false, android: true })).toBe('android')
    expect(pickStore({ android: apk, ios: off }, { ios: true, android: false })).toBeNull()
    // no computador, o cabeçalho não oferece um APK (não se instala no PC)
    expect(pickStore({ android: apk, ios: off }, { ios: false, android: false })).toBeNull()
  })
})
