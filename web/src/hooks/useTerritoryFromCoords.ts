import { useCallback, useEffect, useRef, useState } from 'react'
import { territoryService, type TerritoryGuess } from '@/services/territoryService'

export type TerritoryGuessStatus = 'idle' | 'loading' | 'done' | 'unknown'

interface Coords {
  lat: number
  lng: number
}

/**
 * Deduz província e município a partir das coordenadas obtidas no botão
 * "obter a minha localização" (registo e pedido de gás) e entrega o
 * resultado a `onResult`, que preenche os selects.
 *
 * - Volta a deduzir quando o pino do mapa é arrastado,mas só enquanto o
 *   utilizador não mexeu ele próprio nos selects (`markTouched`): uma
 *   escolha manual nunca é sobrescrita. Voltar a carregar no botão de
 *   localização (`reset`) devolve o controlo à dedução automática.
 * - Nunca bloqueia: se o servidor não souber, `status` fica "unknown" e o
 *   utilizador escolhe à mão, como antes.
 */
export function useTerritoryFromCoords(
  coords: Coords | null,
  ready: boolean,
  onResult: (guess: TerritoryGuess) => void
) {
  const [status, setStatus] = useState<TerritoryGuessStatus>('idle')
  const touched = useRef(false)
  const lastKey = useRef('')
  const onResultRef = useRef(onResult)
  onResultRef.current = onResult

  useEffect(() => {
    if (!coords || !ready || touched.current) return
    const key = `${coords.lat.toFixed(4)},${coords.lng.toFixed(4)}`
    if (key === lastKey.current) return

    let cancelled = false
    // Pequena espera: arrastar o pino gera muitas posições seguidas.
    const timer = setTimeout(() => {
      lastKey.current = key
      setStatus('loading')
      territoryService.locate(coords.lat, coords.lng).then(
        (guess) => {
          if (cancelled || touched.current) return
          if (guess.province_id) {
            onResultRef.current(guess)
            setStatus('done')
          } else {
            setStatus('unknown')
          }
        },
        () => {
          if (!cancelled) setStatus('unknown')
        }
      )
    }, 500)

    return () => {
      cancelled = true
      clearTimeout(timer)
    }
  }, [coords, ready])

  const markTouched = useCallback(() => {
    touched.current = true
  }, [])

  const reset = useCallback(() => {
    touched.current = false
    lastKey.current = ''
    setStatus('idle')
  }, [])

  return { status, markTouched, reset }
}

/** Frase de apoio por baixo do botão de localização. */
export function territoryGuessMessage(status: TerritoryGuessStatus): string | null {
  switch (status) {
    case 'loading':
      return 'A identificar a sua província e município...'
    case 'done':
      return 'Província e município preenchidos pela sua localização. Pode alterá-los se não estiverem certos.'
    case 'unknown':
      return 'Não conseguimos identificar o município. Escolha a província e o município abaixo.'
    default:
      return null
  }
}
