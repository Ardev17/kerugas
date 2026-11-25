import { beforeEach, describe, expect, it, vi } from 'vitest'
import { act, renderHook, waitFor } from '@testing-library/react'

/**
 * Diagnóstico da localização (Novembro 2026): o caso típico do iPhone,a
 * app está em modo nativo mas o plugin nativo @capacitor/geolocation NÃO
 * está registado no build, por isso cairia para a localização do navegador,
 * que o WKWebView do iOS não serve.
 */
vi.mock('@/native/platform', () => ({ isNativeApp: true, nativePlatform: 'ios', hasNativePlugin: () => false }))

const plugin = { checkPermissions: vi.fn(), requestPermissions: vi.fn(), getCurrentPosition: vi.fn() }
vi.mock('@capacitor/geolocation', () => ({ Geolocation: plugin }))

describe('useGeolocation,diagnóstico (plugin nativo em falta no iPhone)', () => {
  beforeEach(() => Object.values(plugin).forEach((fn) => fn.mockReset()))

  it('assinala no diagnóstico que o plugin nativo não está disponível', async () => {
    // Simula o que acontece quando não há plugin nativo: a implementação web
    // não consegue ler a posição dentro do WKWebView.
    plugin.checkPermissions.mockRejectedValue(new Error('location services disabled'))

    const { useGeolocation } = await import('../useGeolocation')
    const { result } = renderHook(() => useGeolocation())
    act(() => result.current.request())

    await waitFor(() => expect(result.current.status).not.toBe('requesting'))

    expect(result.current.diagnostics).toMatchObject({
      platform: 'native-ios',
      nativePluginAvailable: false,
    })
    expect(result.current.diagnostics?.steps.join(' ')).toContain('NÃO disponível')
    expect(typeof result.current.diagnostics?.tookMs).toBe('number')
  })
})
