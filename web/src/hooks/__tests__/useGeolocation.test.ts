import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { useGeolocation } from '../useGeolocation'
import { GEO_MESSAGES } from '@/lib/geo'

type Success = (p: unknown) => void
type Failure = (e: { code: number; message: string }) => void

const position = (latitude: number, longitude: number, accuracy: number | undefined = 20) => ({ coords: { latitude, longitude, accuracy } })
const geoError = (code: number) => ({ code, message: `browser message ${code}` })

/** Instala um navigator.geolocation falso; `script` decide o que cada chamada faz. */
function mockGeolocation(...script: Array<(ok: Success, fail: Failure, options: PositionOptions) => void>) {
  const getCurrentPosition = vi.fn((ok: Success, fail: Failure, options: PositionOptions) => {
    const step = script[Math.min(getCurrentPosition.mock.calls.length - 1, script.length - 1)]
    step(ok, fail, options)
  })
  vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } })
  return getCurrentPosition
}

describe('useGeolocation (navegador)', () => {
  beforeEach(() => {
    vi.stubGlobal('isSecureContext', true)
  })
  afterEach(() => {
    vi.unstubAllGlobals()
    vi.useRealTimers()
  })

  it('começa parado: idle, sem coordenadas, sem erro', () => {
    const { result } = renderHook(() => useGeolocation())
    expect(result.current).toMatchObject({ status: 'idle', latitude: null, longitude: null, accuracy: null, error: null, reason: null })
  })

  it('passa por "requesting" e depois "granted" com latitude, longitude e precisão', async () => {
    let finish!: () => void
    mockGeolocation((ok) => {
      finish = () => ok(position(-14.9177, 13.4925, 15))
    })

    const { result } = renderHook(() => useGeolocation())
    act(() => result.current.request())
    expect(result.current.status).toBe('requesting')

    act(() => finish())
    await waitFor(() => expect(result.current.status).toBe('granted'))
    expect(result.current).toMatchObject({ latitude: -14.9177, longitude: 13.4925, accuracy: 15, error: null, reason: null })
  })

  it('pede primeiro posição precisa (GPS), sem usar posições guardadas', () => {
    const spy = mockGeolocation(() => {})
    const { result } = renderHook(() => useGeolocation())
    act(() => result.current.request())

    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy.mock.calls[0][2]).toMatchObject({ enableHighAccuracy: true, maximumAge: 0 })
    expect(spy.mock.calls[0][2].timeout).toBeGreaterThanOrEqual(10_000)
  })

  describe('falhas', () => {
    it('permissão recusada: "denied" + motivo + mensagem em português, e NÃO insiste', async () => {
      const spy = mockGeolocation((_ok, fail) => fail(geoError(1)))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.status).toBe('denied'))
      expect(result.current.reason).toBe('permission_denied')
      expect(result.current.error).toBe(GEO_MESSAGES.permission_denied)
      expect(result.current.error).not.toContain('browser message')
      expect(spy).toHaveBeenCalledTimes(1)
    })

    it('tempo esgotado no GPS: tenta posição aproximada e consegue', async () => {
      const spy = mockGeolocation(
        (_ok, fail) => fail(geoError(3)),
        (ok) => ok(position(-14.92, 13.49, 1500)),
      )
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.status).toBe('granted'))
      expect(spy).toHaveBeenCalledTimes(2)
      expect(spy.mock.calls[1][2]).toMatchObject({ enableHighAccuracy: false })
      expect(spy.mock.calls[1][2].maximumAge).toBeGreaterThan(0)
      expect(result.current.accuracy).toBe(1500)
    })

    it('posição indisponível no GPS: também tenta a aproximada', async () => {
      const spy = mockGeolocation((_ok, fail) => fail(geoError(2)), (ok) => ok(position(-8.84, 13.29)))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.status).toBe('granted'))
      expect(spy).toHaveBeenCalledTimes(2)
    })

    it('as duas tentativas esgotam o tempo: motivo "timeout"', async () => {
      mockGeolocation((_ok, fail) => fail(geoError(3)))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.status).toBe('denied'))
      expect(result.current.reason).toBe('timeout')
      expect(result.current.error).toBe(GEO_MESSAGES.timeout)
    })

    it('as duas tentativas sem posição: motivo "unavailable"', async () => {
      mockGeolocation((_ok, fail) => fail(geoError(2)))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.reason).toBe('unavailable'))
      expect(result.current.status).toBe('denied')
    })

    it('recusa na 2.ª tentativa (o cliente retirou a permissão a meio): "permission_denied"', async () => {
      mockGeolocation((_ok, fail) => fail(geoError(3)), (_ok, fail) => fail(geoError(1)))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.reason).toBe('permission_denied'))
    })

    it('navegador sem geolocation: "unsupported"', () => {
      vi.stubGlobal('navigator', {})
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      expect(result.current).toMatchObject({ status: 'unsupported', reason: 'unsupported', error: GEO_MESSAGES.unsupported })
    })

    it('geolocation nulo: "unsupported" (nunca rebenta)', () => {
      vi.stubGlobal('navigator', { geolocation: null })
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      expect(result.current.status).toBe('unsupported')
    })

    it('página sem https (contexto inseguro): explica o motivo e nem chama o navegador', () => {
      vi.stubGlobal('isSecureContext', false)
      const spy = mockGeolocation(() => {})
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      expect(result.current).toMatchObject({ status: 'unsupported', reason: 'insecure', error: GEO_MESSAGES.insecure })
      expect(spy).not.toHaveBeenCalled()
    })

    it('navegador que LANÇA em vez de chamar o erro: "unavailable", sem rebentar', () => {
      vi.stubGlobal('navigator', { geolocation: { getCurrentPosition: () => { throw new Error('Permissions-Policy') } } })
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      expect(result.current).toMatchObject({ status: 'denied', reason: 'unavailable' })
    })

    it('erro sem código (objeto estranho): tratado como "unavailable"', async () => {
      mockGeolocation((_ok, fail) => (fail as unknown as (e: unknown) => void)(undefined))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.reason).toBe('unavailable'))
    })
  })

  describe('coordenadas inválidas nunca chegam ao ecrã', () => {
    it.each([
      ['NaN', NaN, 13],
      ['latitude 95', 95, 13],
      ['longitude -200', -14, -200],
      ['undefined', undefined, undefined],
    ])('%s', async (_label, lat, lng) => {
      mockGeolocation((ok) => ok({ coords: { latitude: lat, longitude: lng, accuracy: 10 } }))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.status).toBe('denied'))
      expect(result.current).toMatchObject({ reason: 'invalid', latitude: null, longitude: null })
    })

    it('resposta sem "coords": não rebenta', async () => {
      mockGeolocation((ok) => ok({}))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.reason).toBe('invalid'))
    })
  })

  describe('precisão', () => {
    it.each([
      ['negativa', -5],
      ['NaN', NaN],
      ['infinita', Infinity],
      ['em falta', undefined],
    ])('%s vira null (o mapa não desenha um círculo absurdo)', async (_l, accuracy) => {
      // (direto, não via position(): o valor por omissão de `position` transformaria undefined em 20)
      mockGeolocation((ok) => ok({ coords: { latitude: -14.9, longitude: 13.4, accuracy } }))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.status).toBe('granted'))
      expect(result.current.accuracy).toBeNull()
    })

    it('zero é uma precisão válida', async () => {
      mockGeolocation((ok) => ok(position(-14.9, 13.4, 0)))
      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())

      await waitFor(() => expect(result.current.accuracy).toBe(0))
    })
  })

  describe('repetir e desmontar', () => {
    it('depois de uma recusa, carregar outra vez limpa o erro e pode ter sucesso', async () => {
      mockGeolocation((_ok, fail) => fail(geoError(1)), (ok) => ok(position(-14.9, 13.4)))
      const { result } = renderHook(() => useGeolocation())

      act(() => result.current.request())
      await waitFor(() => expect(result.current.status).toBe('denied'))

      act(() => result.current.request())
      expect(result.current.error).toBeNull()
      expect(result.current.reason).toBeNull()
      await waitFor(() => expect(result.current.status).toBe('granted'))
    })

    it('com dois pedidos seguidos, só o último conta (resposta atrasada do 1.º é ignorada)', async () => {
      const resolvers: Array<(p: unknown) => void> = []
      mockGeolocation((ok) => { resolvers.push(ok) })

      const { result } = renderHook(() => useGeolocation())
      act(() => result.current.request())
      act(() => result.current.request())
      expect(resolvers).toHaveLength(2)

      act(() => resolvers[1](position(-8.84, 13.29)))
      await waitFor(() => expect(result.current.latitude).toBe(-8.84))

      act(() => resolvers[0](position(-14.9, 13.4))) // chega tarde
      expect(result.current.latitude).toBe(-8.84)
    })

    it('uma resposta que chega depois de desmontar não atualiza nada nem dá erro', () => {
      let finish!: () => void
      mockGeolocation((ok) => { finish = () => ok(position(-14.9, 13.4)) })
      const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})

      const { result, unmount } = renderHook(() => useGeolocation())
      act(() => result.current.request())
      unmount()
      act(() => finish())

      expect(errorSpy).not.toHaveBeenCalled()
      errorSpy.mockRestore()
    })

    it('depois de conceder, um novo pedido mantém as coordenadas antigas até haver novas', async () => {
      const resolvers: Array<(p: unknown) => void> = []
      mockGeolocation((ok) => { resolvers.push(ok) })
      const { result } = renderHook(() => useGeolocation())

      act(() => result.current.request())
      act(() => resolvers[0](position(-14.9, 13.4)))
      await waitFor(() => expect(result.current.status).toBe('granted'))

      act(() => result.current.request())
      expect(result.current.status).toBe('requesting')
      expect(result.current.latitude).toBe(-14.9)

      act(() => resolvers[1](position(-15.0, 13.5)))
      await waitFor(() => expect(result.current.latitude).toBe(-15.0))
    })
  })
})
