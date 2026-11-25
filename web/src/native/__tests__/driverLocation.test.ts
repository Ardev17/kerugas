import { describe, expect, it, vi, beforeEach } from 'vitest'

const { post } = vi.hoisted(() => ({ post: vi.fn().mockResolvedValue({ data: { success: true } }) }))
vi.mock('@/lib/api', () => ({ api: { post, defaults: { baseURL: 'https://api.test/api/v1' } } }))

import { shouldSend, startDriverLocation, hasDriverLocationConsent, setDriverLocationConsent, MIN_SEND_INTERVAL_MS } from '@/native/driverLocation'

describe('driverLocation (Novembro 2026)', () => {
  beforeEach(() => {
    post.mockClear()
    localStorage.clear()
  })

  it('respeita o intervalo mínimo entre envios', () => {
    expect(shouldSend(null, 1000)).toBe(true)
    expect(shouldSend(1000, 1000 + MIN_SEND_INTERVAL_MS - 1)).toBe(false)
    expect(shouldSend(1000, 1000 + MIN_SEND_INTERVAL_MS)).toBe(true)
  })

  it('guarda o consentimento', () => {
    expect(hasDriverLocationConsent()).toBe(false)
    setDriverLocationConsent(true)
    expect(hasDriverLocationConsent()).toBe(true)
    setDriverLocationConsent(false)
    expect(hasDriverLocationConsent()).toBe(false)
  })

  it('no site envia a posição do watchPosition e pára ao terminar', () => {
    let success: PositionCallback = () => {}
    const clearWatch = vi.fn()
    Object.defineProperty(navigator, 'geolocation', {
      configurable: true,
      value: { watchPosition: (cb: PositionCallback) => { success = cb; return 7 }, clearWatch },
    })
    const states: string[] = []
    const stop = startDriverLocation((s) => states.push(s))

    success({ coords: { latitude: -12.36, longitude: 13.54, accuracy: 10 }, timestamp: 1_700_000_000_000 } as GeolocationPosition)
    // Segunda posição logo a seguir: não envia (intervalo mínimo).
    success({ coords: { latitude: -12.37, longitude: 13.55, accuracy: 10 }, timestamp: 1_700_000_001_000 } as GeolocationPosition)

    expect(post).toHaveBeenCalledTimes(1)
    expect(post.mock.calls[0][0]).toBe('/driver/location')
    expect(post.mock.calls[0][1]).toMatchObject({ latitude: -12.36, longitude: 13.54, accuracy: 10, recorded_at: 1_700_000_000_000 })

    stop()
    expect(clearWatch).toHaveBeenCalledWith(7)
    expect(states).toEqual(['active', 'active', 'off'])
  })
})
