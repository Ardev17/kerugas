import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'
import { GEO_MESSAGES } from '@/lib/geo'

/**
 * App Android (Capacitor): `isNativeApp` é uma constante calculada ao
 * importar, por isso este ficheiro simula a plataforma nativa para todos
 * os testes e o plugin @capacitor/geolocation.
 */
vi.mock('@/native/platform', () => ({ isNativeApp: true, nativePlatform: 'ios', hasNativePlugin: () => true }))

const plugin = {
  checkPermissions: vi.fn(),
  requestPermissions: vi.fn(),
  getCurrentPosition: vi.fn(),
}
vi.mock('@capacitor/geolocation', () => ({ Geolocation: plugin }))

const granted = { location: 'granted', coarseLocation: 'granted' }
const denied = { location: 'denied', coarseLocation: 'denied' }
const fix = (latitude: number, longitude: number, accuracy = 8) => ({ coords: { latitude, longitude, accuracy } })

async function run() {
  const { useGeolocation } = await import('../useGeolocation')
  const hook = renderHook(() => useGeolocation())
  act(() => hook.result.current.request())
  return hook
}

describe('useGeolocation (app Android)', () => {
  beforeEach(() => {
    Object.values(plugin).forEach((fn) => fn.mockReset())
  })

  it('com a permissão já concedida: lê a posição sem voltar a perguntar', async () => {
    plugin.checkPermissions.mockResolvedValue(granted)
    plugin.getCurrentPosition.mockResolvedValue(fix(-14.9177, 13.4925))

    const { result } = await run()

    await waitFor(() => expect(result.current.status).toBe('granted'))
    expect(result.current).toMatchObject({ latitude: -14.9177, longitude: 13.4925, accuracy: 8, error: null })
    expect(plugin.requestPermissions).not.toHaveBeenCalled()
  })

  it('pede a permissão (fina e aproximada) quando ainda não existe, e continua se o cliente aceitar', async () => {
    plugin.checkPermissions.mockResolvedValue({ location: 'prompt', coarseLocation: 'prompt' })
    plugin.requestPermissions.mockResolvedValue(granted)
    plugin.getCurrentPosition.mockResolvedValue(fix(-8.84, 13.29))

    const { result } = await run()

    await waitFor(() => expect(result.current.status).toBe('granted'))
    expect(plugin.requestPermissions).toHaveBeenCalledWith({ permissions: ['location', 'coarseLocation'] })
  })

  it('só a localização aproximada concedida (Android 12+) também serve', async () => {
    plugin.checkPermissions.mockResolvedValue({ location: 'denied', coarseLocation: 'granted' })
    plugin.getCurrentPosition.mockResolvedValue(fix(-14.9, 13.4, 1800))

    const { result } = await run()

    await waitFor(() => expect(result.current.status).toBe('granted'))
    expect(result.current.accuracy).toBe(1800)
  })

  it('cliente recusa o diálogo do Android: "permission_denied", sem tentar ler a posição', async () => {
    plugin.checkPermissions.mockResolvedValue({ location: 'prompt', coarseLocation: 'prompt' })
    plugin.requestPermissions.mockResolvedValue(denied)

    const { result } = await run()

    await waitFor(() => expect(result.current.status).toBe('denied'))
    expect(result.current).toMatchObject({ reason: 'permission_denied', error: GEO_MESSAGES.permission_denied })
    expect(plugin.getCurrentPosition).not.toHaveBeenCalled()
  })

  it('GPS do telemóvel desligado (checkPermissions lança): "gps_off"', async () => {
    plugin.checkPermissions.mockRejectedValue(new Error('Location services are not enabled'))

    const { result } = await run()

    await waitFor(() => expect(result.current.reason).toBe('gps_off'))
    expect(result.current.error).toBe(GEO_MESSAGES.gps_off)
  })

  it('GPS desligado detetado só ao ler a posição: "gps_off" (e não "vá para um sítio aberto")', async () => {
    plugin.checkPermissions.mockResolvedValue(granted)
    plugin.getCurrentPosition.mockRejectedValue(new Error('Location services are not enabled'))

    const { result } = await run()

    await waitFor(() => expect(result.current.reason).toBe('gps_off'))
    expect(plugin.getCurrentPosition).toHaveBeenCalledTimes(1)
  })

  it('sem fix de GPS a tempo: repete com posição aproximada e consegue', async () => {
    plugin.checkPermissions.mockResolvedValue(granted)
    plugin.getCurrentPosition
      .mockRejectedValueOnce(new Error('Timeout expired'))
      .mockResolvedValueOnce(fix(-14.92, 13.49, 900))

    const { result } = await run()

    await waitFor(() => expect(result.current.status).toBe('granted'))
    expect(plugin.getCurrentPosition).toHaveBeenCalledTimes(2)
    expect(plugin.getCurrentPosition.mock.calls[0][0]).toMatchObject({ enableHighAccuracy: true })
    expect(plugin.getCurrentPosition.mock.calls[1][0]).toMatchObject({ enableHighAccuracy: false })
  })

  it('as duas tentativas falham: "timeout" com a sugestão certa', async () => {
    plugin.checkPermissions.mockResolvedValue(granted)
    plugin.getCurrentPosition.mockRejectedValue(new Error('Timeout expired'))

    const { result } = await run()

    await waitFor(() => expect(result.current.reason).toBe('timeout'))
    expect(result.current.status).toBe('denied')
    expect(result.current.error).toBe(GEO_MESSAGES.timeout)
  })

  it('posição com coordenadas inválidas do plugin é rejeitada', async () => {
    plugin.checkPermissions.mockResolvedValue(granted)
    plugin.getCurrentPosition.mockResolvedValue(fix(NaN, 13))

    const { result } = await run()

    await waitFor(() => expect(result.current.reason).toBe('invalid'))
    expect(result.current.latitude).toBeNull()
  })

  it('passa por "requesting" enquanto espera o plugin', async () => {
    plugin.checkPermissions.mockReturnValue(new Promise(() => {})) // nunca resolve

    const { result } = await run()

    expect(result.current.status).toBe('requesting')
  })
})
