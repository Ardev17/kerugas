import { useEffect, useRef, type ReactNode } from 'react'
import { motionLevel } from '@/lib/motion'

/**
 * O botão "puxa" ligeiramente na direção do rato quando ele se aproxima
 * (Outubro 2026). Só em computador (rato fino) e no nível `full`.
 */
export function Magnetic({ children, className = '', strength = 0.25 }: { children: ReactNode; className?: string; strength?: number }) {
  const ref = useRef<HTMLSpanElement>(null)
  useEffect(() => {
    const el = ref.current
    if (!el || motionLevel() !== 'full') return
    if (!window.matchMedia?.('(pointer: fine)').matches) return
    const onMove = (e: PointerEvent) => {
      const r = el.getBoundingClientRect()
      const x = (e.clientX - (r.left + r.width / 2)) * strength
      const y = (e.clientY - (r.top + r.height / 2)) * strength
      el.style.transform = `translate3d(${x}px, ${y}px, 0)`
    }
    const onLeave = () => {
      el.style.transform = ''
    }
    el.addEventListener('pointermove', onMove)
    el.addEventListener('pointerleave', onLeave)
    return () => {
      el.removeEventListener('pointermove', onMove)
      el.removeEventListener('pointerleave', onLeave)
    }
  }, [strength])
  return (
    <span ref={ref} className={`fx-magnetic inline-flex ${className}`}>
      {children}
    </span>
  )
}
