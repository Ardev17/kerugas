import { useEffect, useRef, type ReactNode } from 'react'
import { motionLevel } from '@/lib/motion'

interface Props {
  children: ReactNode
  className?: string
  /** Inclinação máxima em graus. */
  max?: number
}

/**
 * Cartão que se inclina em 3D na direção do rato/dedo, com um reflexo de
 * luz a acompanhar (Outubro 2026). Só no nível `full`; nos outros fica
 * parado (mas com o mesmo aspeto).
 */
export function TiltCard({ children, className = '', max = 8 }: Props) {
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const el = ref.current
    if (!el || motionLevel() !== 'full') return
    let raf = 0
    const onMove = (e: PointerEvent) => {
      const rect = el.getBoundingClientRect()
      const px = (e.clientX - rect.left) / rect.width
      const py = (e.clientY - rect.top) / rect.height
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => {
        el.style.setProperty('--rx', `${(0.5 - py) * max}deg`)
        el.style.setProperty('--ry', `${(px - 0.5) * max}deg`)
        el.style.setProperty('--gx', `${px * 100}%`)
        el.style.setProperty('--gy', `${py * 100}%`)
        el.classList.add('is-tilting')
      })
    }
    const onLeave = () => {
      cancelAnimationFrame(raf)
      el.style.setProperty('--rx', '0deg')
      el.style.setProperty('--ry', '0deg')
      el.classList.remove('is-tilting')
    }
    el.addEventListener('pointermove', onMove)
    el.addEventListener('pointerleave', onLeave)
    return () => {
      cancelAnimationFrame(raf)
      el.removeEventListener('pointermove', onMove)
      el.removeEventListener('pointerleave', onLeave)
    }
  }, [max])

  return (
    <div ref={ref} className={`fx-tilt ${className}`}>
      {children}
      <span className="fx-tilt-shine" aria-hidden="true" />
    </div>
  )
}
