import { useRef, type ReactNode, type PointerEvent } from 'react'

/**
 * Cartão com um foco de luz que segue o rato/dedo e um contorno que
 * acende (Outubro 2026). Puro CSS com duas variáveis,barato.
 */
export function Spotlight({ children, className = '' }: { children: ReactNode; className?: string }) {
  const ref = useRef<HTMLDivElement>(null)
  const onMove = (e: PointerEvent<HTMLDivElement>) => {
    const el = ref.current
    if (!el) return
    const r = el.getBoundingClientRect()
    el.style.setProperty('--sx', `${e.clientX - r.left}px`)
    el.style.setProperty('--sy', `${e.clientY - r.top}px`)
  }
  return (
    <div ref={ref} onPointerMove={onMove} className={`fx-spot ${className}`}>
      {children}
    </div>
  )
}
