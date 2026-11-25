import { useEffect, useRef, useState } from 'react'
import { RefreshCw } from 'lucide-react'
import { isNativeApp } from '@/native/platform'
import { tapFeedback } from '@/native/haptics'
import { canStartPull, PULL_MAX, PULL_RESISTANCE, PULL_THRESHOLD } from '@/native/pullToRefresh'

interface Props {
  /** Recarrega o conteúdo. O indicador roda até isto acabar. */
  onRefresh: () => Promise<void> | void
}

/**
 * Arrastar para baixo, no topo da página, para recarregar,como no
 * WhatsApp, Gmail ou Instagram (Outubro 2026). Só no app: no navegador do
 * telemóvel já existe o do próprio navegador. O WebView do Android não traz
 * este gesto e o app desliga o "elástico" (overscroll-behavior), por isso
 * é feito aqui, com toques.
 */
export function PullToRefresh({ onRefresh }: Props) {
  const [pull, setPull] = useState(0)
  const [refreshing, setRefreshing] = useState(false)
  const start = useRef<{ x: number; y: number } | null>(null)
  const pulling = useRef(false)
  const armed = useRef(false)
  const busy = useRef(false)
  const refresh = useRef(onRefresh)
  refresh.current = onRefresh

  useEffect(() => {
    if (!isNativeApp) return

    const reset = () => {
      start.current = null
      pulling.current = false
      armed.current = false
      setPull(0)
    }

    const onStart = (e: TouchEvent) => {
      if (busy.current || e.touches.length !== 1 || !canStartPull(e.target)) {
        start.current = null
        return
      }
      start.current = { x: e.touches[0].clientX, y: e.touches[0].clientY }
      pulling.current = false
      armed.current = false
    }

    const onMove = (e: TouchEvent) => {
      const s = start.current
      if (!s || busy.current) return
      const dy = e.touches[0].clientY - s.y
      const dx = e.touches[0].clientX - s.x
      // Para cima, ou mais para o lado do que para baixo: não é para recarregar.
      if (dy <= 0 || (!pulling.current && Math.abs(dx) > Math.abs(dy))) {
        if (pulling.current) reset()
        else if (dy < -6) start.current = null
        return
      }
      // A página começou a rolar (o dedo apanhou um sítio que não é o topo): desiste.
      if ((document.scrollingElement ?? document.documentElement).scrollTop > 0) {
        reset()
        return
      }
      if (dy > 8) pulling.current = true
      if (!pulling.current) return
      const distance = Math.min(PULL_MAX, dy * PULL_RESISTANCE)
      if (!armed.current && distance >= PULL_THRESHOLD * PULL_RESISTANCE + 8) {
        armed.current = true
        void tapFeedback()
      } else if (armed.current && distance < PULL_THRESHOLD * PULL_RESISTANCE) {
        armed.current = false
      }
      setPull(distance)
    }

    const onEnd = () => {
      const shouldRefresh = pulling.current && armed.current && !busy.current
      start.current = null
      pulling.current = false
      armed.current = false
      if (!shouldRefresh) {
        setPull(0)
        return
      }
      busy.current = true
      setRefreshing(true)
      setPull(PULL_THRESHOLD * PULL_RESISTANCE + 8)
      const minimum = new Promise((resolve) => setTimeout(resolve, 700))
      void Promise.allSettled([Promise.resolve(refresh.current()), minimum]).finally(() => {
        busy.current = false
        setRefreshing(false)
        setPull(0)
      })
    }

    document.addEventListener('touchstart', onStart, { passive: true })
    document.addEventListener('touchmove', onMove, { passive: true })
    document.addEventListener('touchend', onEnd, { passive: true })
    document.addEventListener('touchcancel', reset, { passive: true })
    return () => {
      document.removeEventListener('touchstart', onStart)
      document.removeEventListener('touchmove', onMove)
      document.removeEventListener('touchend', onEnd)
      document.removeEventListener('touchcancel', reset)
    }
  }, [])

  if (!isNativeApp || (pull === 0 && !refreshing)) return null

  const progress = Math.min(1, pull / (PULL_THRESHOLD * PULL_RESISTANCE + 8))
  return (
    <div
      role="status"
      aria-live="polite"
      aria-label={refreshing ? 'A recarregar' : 'Arraste para recarregar'}
      className="pointer-events-none fixed left-0 right-0 z-[3000] flex justify-center"
      style={{ top: 'calc(env(safe-area-inset-top, 0px) + 8px)', transform: `translateY(${pull - 24}px)`, transition: pulling.current ? 'none' : 'transform 0.2s ease' }}
    >
      <span
        className={`flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-lg ring-1 ring-gray/15 ${progress >= 1 || refreshing ? 'text-primary' : 'text-gray'}`}
        style={{ opacity: Math.max(0.35, progress) }}
      >
        <RefreshCw size={20} className={refreshing ? 'animate-spin' : ''} style={refreshing ? undefined : { transform: `rotate(${progress * 270}deg)` }} aria-hidden="true" />
      </span>
    </div>
  )
}
