import { describe, expect, it, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'

const get = vi.fn()
vi.mock('@/lib/api', () => ({ api: { get: (...a: unknown[]) => get(...a) } }))

import { useAndroidStore } from '../useAndroidStore'

let client: QueryClient
function wrapper({ children }: { children: ReactNode }) {
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>
}

describe('useAndroidStore', () => {
  beforeEach(() => {
    get.mockReset()
    client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  })

  it('publicado + https = link ativo', async () => {
    get.mockResolvedValue({ data: { data: { android: { published: true, store_url: 'https://play.google.com/x' } } } })
    const { result } = renderHook(() => useAndroidStore(), { wrapper })
    await waitFor(() => expect(result.current.published).toBe(true))
    expect(result.current.storeUrl).toBe('https://play.google.com/x')
  })

  it('não publicado ou link estranho = sem link', async () => {
    get.mockResolvedValue({ data: { data: { android: { published: true, store_url: 'javascript:alert(1)' } } } })
    const { result } = renderHook(() => useAndroidStore(), { wrapper })
    await waitFor(() => expect(get).toHaveBeenCalled())
    expect(result.current).toEqual({ published: false, storeUrl: null })
  })

  it('falha de rede = sem link', async () => {
    get.mockRejectedValue(new Error('rede'))
    const { result } = renderHook(() => useAndroidStore(), { wrapper })
    await waitFor(() => expect(client.getQueryState(['app-config', 'android'])?.status).toBe('error'))
    expect(result.current.published).toBe(false)
  })
})
