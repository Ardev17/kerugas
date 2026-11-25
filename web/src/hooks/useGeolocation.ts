import { useCallback, useEffect, useRef, useState } from 'react'
import { hasNativePlugin, isNativeApp, nativePlatform } from '@/native/platform'
import { GEO_MESSAGES, isValidCoordinate, type GeoFailureReason } from '@/lib/geo'

/**
 * Diagnóstico da última tentativa de localização (Novembro 2026). Serve
 * para perceber, sobretudo no iPhone, PORQUÊ é que a localização não
 * devolve coordenadas: por que caminho foi (plugin nativo ou navegador),
 * se o plugin nativo está mesmo registado neste build, o erro em bruto do
 * sistema, a precisão e o tempo que demorou. O ecrã mostra isto num painel
 * "Detalhes técnicos".
 */
export interface GeoDiagnostics {
  /** 'native-ios' | 'native-android' | 'web'. */
  platform: string
  /**
   * Só no app: o plugin nativo @capacitor/geolocation está registado neste
   * build? Se for `false` no iPhone, é essa a causa,a app cai para a
   * localização do navegador, que o WKWebView do iOS não serve. `null` no site.
   */
  nativePluginAvailable: boolean | null
  /** Passos por que a tentativa passou, para leitura humana. */
  steps: string[]
  /** Mensagem de erro em bruto do sistema/plugin (null se correu bem). */
  rawError: string | null
  /** Precisão devolvida, em metros (null se não houve posição). */
  accuracyM: number | null
  /** Tempo total da tentativa, em ms. */
  tookMs: number | null
}

export interface GeoState {
  latitude: number | null
  longitude: number | null
  accuracy: number | null
  status: 'idle' | 'requesting' | 'granted' | 'denied' | 'unsupported'
  error: string | null
  /** Porquê falhou (para o ecrã decidir o que sugerir); `null` se não falhou. */
  reason: GeoFailureReason | null
  /** Diagnóstico técnico da última tentativa. */
  diagnostics: GeoDiagnostics | null
}

const INITIAL: GeoState = {
  latitude: null, longitude: null, accuracy: null, status: 'idle', error: null, reason: null, diagnostics: null,
}

// 1.ª tentativa: GPS preciso. Se não houver sinal a tempo (típico num
// computador, ou dentro de casa), a 2.ª aceita uma posição aproximada
// (Wi-Fi/antenas) e até uma guardada há poucos minutos,melhor uma
// posição aproximada, que o cliente ajusta no mapa, do que nenhuma.
//
// No iPhone o primeiro fix de GPS "frio" costuma demorar mais, por isso o
// tempo da 1.ª tentativa é mais folgado no app nativo do que no site.
const PRECISE = { enableHighAccuracy: true, timeout: isNativeApp ? 20_000 : 12_000, maximumAge: 0 }
const APPROXIMATE = { enableHighAccuracy: false, timeout: 20_000, maximumAge: 5 * 60_000 }

const failure = (reason: GeoFailureReason, status: GeoState['status'] = 'denied'): GeoState => ({
  ...INITIAL,
  status,
  reason,
  error: GEO_MESSAGES[reason],
})

function success(lat: number, lng: number, accuracy: unknown): GeoState {
  return {
    latitude: lat,
    longitude: lng,
    accuracy: typeof accuracy === 'number' && Number.isFinite(accuracy) && accuracy >= 0 ? accuracy : null,
    status: 'granted',
    error: null,
    reason: null,
    diagnostics: null,
  }
}

/** Códigos da API do navegador: 1 recusada, 2 indisponível, 3 tempo esgotado. */
function reasonFromCode(code: number | undefined): GeoFailureReason {
  if (code === 1) return 'permission_denied'
  if (code === 3) return 'timeout'
  return 'unavailable'
}

/**
 * Encapsula a geolocalização (navegador ou GPS nativo do app) com o fluxo
 * de consentimento descrito no Master Build Prompt (secções 14, 173, 199):
 * nunca falha em silêncio,expõe sempre `status`, `reason`, uma mensagem
 * em português e um `diagnostics` técnico, para o ecrã oferecer o plano B
 * (escolher a zona à mão) e, no iPhone, perceber a causa.
 *
 * - Pede primeiro posição precisa e, se esgotar o tempo ou não houver
 *   sinal, tenta uma aproximada. Recusa do cliente nunca é repetida.
 * - Em páginas sem https (exceto localhost) o navegador bloqueia a
 *   localização: avisa com clareza em vez de um "negado" confuso.
 * - Coordenadas inválidas (NaN, fora dos limites) são rejeitadas.
 * - Pedidos repetidos: só o último conta. Depois de desmontar, nada
 *   atualiza o estado.
 *
 * App (Android/iOS): usa @capacitor/geolocation,diálogo de permissão do
 * próprio sistema e mensagens específicas (GPS desligado vs. recusada).
 */
export function useGeolocation() {
  const [state, setState] = useState<GeoState>(INITIAL)
  const attempt = useRef(0)
  const mounted = useRef(true)

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const request = useCallback(() => {
    const id = ++attempt.current
    const startedAt = Date.now()

    const diag: GeoDiagnostics = {
      platform: isNativeApp ? `native-${nativePlatform}` : 'web',
      nativePluginAvailable: isNativeApp ? hasNativePlugin('Geolocation') : null,
      steps: [],
      rawError: null,
      accuracyM: null,
      tookMs: null,
    }
    const step = (s: string) => diag.steps.push(s)

    // Só o pedido mais recente atualiza o ecrã; nada depois de desmontar.
    // Cada atualização leva uma cópia fresca do diagnóstico.
    const apply = (next: GeoState | ((s: GeoState) => GeoState)) => {
      if (!mounted.current || id !== attempt.current) return
      diag.tookMs = Date.now() - startedAt
      const snapshot: GeoDiagnostics = { ...diag, steps: [...diag.steps] }
      setState((prev) => {
        const resolved = typeof next === 'function' ? next(prev) : next
        return { ...resolved, diagnostics: snapshot }
      })
    }

    apply((s) => ({ ...s, status: 'requesting', error: null, reason: null }))

    if (isNativeApp) {
      step(`app ${nativePlatform}`)
      if (diag.nativePluginAvailable === false) {
        // Causa típica no iPhone: o plugin nativo não está ligado a este
        // build, por isso a localização cairia para a API do navegador, que
        // o WKWebView do iOS não serve. Avisamos em vez de falhar calado.
        step('plugin nativo @capacitor/geolocation NÃO disponível neste build')
      }
      void requestNative(step, (m) => { diag.rawError = m }, (a) => { diag.accuracyM = a }).then(
        (result) => apply(result),
        (err: unknown) => apply(failure(nativeReason(err))),
      )
      return
    }

    step('web (navegador)')

    if (typeof navigator === 'undefined' || !('geolocation' in navigator) || !navigator.geolocation) {
      step('navigator.geolocation ausente')
      apply(failure('unsupported', 'unsupported'))
      return
    }

    // `isSecureContext === false` (http:// num endereço que não é localhost):
    // o navegador recusa sempre. `undefined` (ambientes antigos/testes) não bloqueia.
    if (typeof window !== 'undefined' && window.isSecureContext === false) {
      step('contexto inseguro (sem https)')
      apply(failure('insecure', 'unsupported'))
      return
    }

    const read = (position: GeolocationPosition) => {
      const { latitude, longitude, accuracy } = position?.coords ?? ({} as GeolocationCoordinates)
      diag.accuracyM = typeof accuracy === 'number' && Number.isFinite(accuracy) ? Math.round(accuracy) : null
      step(`posição recebida (precisão ${diag.accuracyM ?? '?'} m)`)
      apply(isValidCoordinate(latitude, longitude) ? success(latitude, longitude, accuracy) : failure('invalid'))
    }

    const getPosition = (options: PositionOptions, label: string, onError: (err: GeolocationPositionError) => void) => {
      step(label)
      try {
        navigator.geolocation.getCurrentPosition(read, onError, options)
      } catch {
        // Alguns navegadores lançam em vez de chamar o erro (ex.: política de permissões).
        step('getCurrentPosition lançou exceção')
        apply(failure('unavailable'))
      }
    }

    getPosition(PRECISE, 'tentativa precisa', (err) => {
      const reason = reasonFromCode(err?.code)
      diag.rawError = err?.message || `code ${err?.code}`
      // Recusa: não insistir. Tempo esgotado / indisponível: tenta aproximada.
      if (reason === 'permission_denied') return apply(failure(reason))
      getPosition(APPROXIMATE, 'tentativa aproximada', (err2) => {
        diag.rawError = err2?.message || `code ${err2?.code}`
        apply(failure(reasonFromCode(err2?.code)))
      })
    })
  }, [])

  return { ...state, request }
}

function nativeReason(err: unknown): GeoFailureReason {
  const code = (err as { reason?: GeoFailureReason } | undefined)?.reason
  return code ?? 'unavailable'
}

class NativeGeoError extends Error {
  constructor(public readonly reason: GeoFailureReason) {
    super(GEO_MESSAGES[reason])
  }
}

async function requestNative(
  step: (s: string) => void,
  setRaw: (m: string) => void,
  setAccuracy: (a: number) => void,
): Promise<GeoState> {
  const { Geolocation } = await import('@capacitor/geolocation')

  let permission
  try {
    permission = await Geolocation.checkPermissions()
    step(`permissão: ${permission.location}/${permission.coarseLocation}`)
    if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') {
      step('a pedir permissão')
      permission = await Geolocation.requestPermissions({ permissions: ['location', 'coarseLocation'] })
      step(`permissão após pedido: ${permission.location}/${permission.coarseLocation}`)
    }
  } catch (err) {
    // checkPermissions lança quando os serviços de localização do
    // telemóvel estão desligados por completo.
    setRaw(String((err as Error)?.message ?? err))
    step('checkPermissions/requestPermissions lançou (GPS desligado?)')
    throw new NativeGeoError('gps_off')
  }

  if (permission.location !== 'granted' && permission.coarseLocation !== 'granted') {
    step('permissão recusada')
    throw new NativeGeoError('permission_denied')
  }

  const attempt = async (options: PositionOptions) => Geolocation.getCurrentPosition(options)

  let position
  try {
    step('tentativa precisa')
    position = await attempt(PRECISE)
  } catch (first) {
    const msg = String((first as Error)?.message ?? first)
    setRaw(msg)
    step(`tentativa precisa falhou: ${msg}`)
    if (/not enabled|disabled|off/i.test(msg)) throw new NativeGeoError('gps_off')
    try {
      // Sem fix de GPS a tempo: aceita posição aproximada (rede).
      step('tentativa aproximada')
      position = await attempt(APPROXIMATE)
    } catch (second) {
      const msg2 = String((second as Error)?.message ?? second)
      setRaw(msg2)
      step(`tentativa aproximada falhou: ${msg2}`)
      throw new NativeGeoError(/not enabled|disabled|off/i.test(msg2) ? 'gps_off' : 'timeout')
    }
  }

  const { latitude, longitude, accuracy } = position.coords
  if (typeof accuracy === 'number' && Number.isFinite(accuracy)) setAccuracy(Math.round(accuracy))
  step(`posição recebida (precisão ${typeof accuracy === 'number' ? Math.round(accuracy) : '?'} m)`)
  if (!isValidCoordinate(latitude, longitude)) throw new NativeGeoError('invalid')
  return success(latitude, longitude, accuracy)
}
