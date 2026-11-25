import { describe, it, expect, beforeEach, vi } from 'vitest'
import { enqueuePickup, flushQueue, readQueue, newClientUuid, isRetryable } from '../pickupQueue'

const item = (uuid: string) => ({
  client_uuid: uuid, station_id: 1, station_name: 'Posto', quantity: 5, amount: 6000,
  registered_at: '2026-10-05T08:00:00Z', queued_at: '2026-10-05T08:00:00Z',
})

describe('fila offline de carregamentos', () => {
  beforeEach(() => { localStorage.clear() })

  it('guarda sem duplicar o mesmo uuid', () => {
    enqueuePickup(item('a'))
    enqueuePickup(item('a'))
    enqueuePickup(item('b'))
    expect(readQueue().map((i) => i.client_uuid)).toEqual(['a', 'b'])
  })

  it('envia tudo e esvazia a fila, marcado como offline', async () => {
    enqueuePickup(item('a'))
    enqueuePickup(item('b'))
    const send = vi.fn().mockResolvedValue({})
    const r = await flushQueue(send)
    expect(r).toMatchObject({ sent: 2, remaining: 0 })
    expect(send).toHaveBeenCalledWith(expect.objectContaining({ client_uuid: 'a', offline: true }))
    expect(send.mock.calls[0][0]).not.toHaveProperty('station_name')
    expect(readQueue()).toEqual([])
  })

  it('sem rede pára e guarda o resto', async () => {
    enqueuePickup(item('a'))
    enqueuePickup(item('b'))
    const send = vi.fn().mockRejectedValue(new Error('Network Error'))
    const r = await flushQueue(send)
    expect(r).toMatchObject({ sent: 0, remaining: 2 })
    expect(send).toHaveBeenCalledTimes(1)
  })

  it('um erro de validação sai da fila e é devolvido como recusado', async () => {
    enqueuePickup(item('a'))
    enqueuePickup(item('b'))
    const send = vi.fn()
      .mockRejectedValueOnce({ response: { status: 422 } })
      .mockResolvedValueOnce({})
    const r = await flushQueue(send)
    expect(r.rejected.map((i) => i.client_uuid)).toEqual(['a'])
    expect(r).toMatchObject({ sent: 1, remaining: 0 })
  })

  it('gera uuids v4 e classifica erros', () => {
    expect(newClientUuid()).toMatch(/^[0-9a-f-]{36}$/)
    expect(isRetryable({ response: { status: 503 } })).toBe(true)
    expect(isRetryable({ response: { status: 403 } })).toBe(false)
  })
})
