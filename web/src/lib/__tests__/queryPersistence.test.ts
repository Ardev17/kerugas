import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { QueryClient } from '@tanstack/react-query'
import { persistQueries, QUERY_CACHE_KEY, restorePersistedQueries } from '@/lib/queryPersistence'

describe('cache local de dados públicos', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.useFakeTimers()
  })
  afterEach(() => vi.useRealTimers())

  it('guarda só as consultas públicas da lista e repõe-nas como antigas', () => {
    const client = new QueryClient()
    const stop = persistQueries(client)
    client.setQueryData(['provinces'], [{ id: 1, name: 'Huíla' }])
    client.setQueryData(['notifications'], [{ id: 9, title: 'privado' }])
    client.setQueryData(['my-orders', 'all'], [{ id: 3 }])
    vi.advanceTimersByTime(1500)
    stop()

    const saved = JSON.parse(localStorage.getItem(QUERY_CACHE_KEY) ?? '[]') as { key: unknown[] }[]
    expect(saved.map((e) => e.key[0])).toEqual(['provinces'])

    const fresh = new QueryClient()
    restorePersistedQueries(fresh)
    expect(fresh.getQueryData(['provinces'])).toEqual([{ id: 1, name: 'Huíla' }])
    expect(fresh.getQueryData(['notifications'])).toBeUndefined()
  })

  it('ignora o que já passou do prazo e dados corrompidos', () => {
    const old = Date.now() - 2 * 24 * 60 * 60 * 1000
    localStorage.setItem(QUERY_CACHE_KEY, JSON.stringify([
      { key: ['map-stations'], data: [1], updatedAt: old },
      { key: ['municipalities', 2], data: [2], updatedAt: old },
      { key: ['admin-orders'], data: [3], updatedAt: Date.now() },
    ]))
    const client = new QueryClient()
    restorePersistedQueries(client)
    expect(client.getQueryData(['map-stations'])).toBeUndefined()
    expect(client.getQueryData(['municipalities', 2])).toEqual([2])
    expect(client.getQueryData(['admin-orders'])).toBeUndefined()

    localStorage.setItem(QUERY_CACHE_KEY, '{oops')
    expect(() => restorePersistedQueries(new QueryClient())).not.toThrow()
  })

  it('apaga a cache da versão anterior em vez de a repor (formato antigo do mapa)', () => {
    localStorage.setItem('kerugas_query_cache_v1', JSON.stringify([{ key: ['map-stations'], data: [{ id: 1 }], updatedAt: Date.now() }]))
    const client = new QueryClient()

    restorePersistedQueries(client)

    expect(localStorage.getItem('kerugas_query_cache_v1')).toBeNull()
    expect(client.getQueryData(['map-stations'])).toBeUndefined()
  })
})
