import { useEffect, useRef, type CSSProperties, type ElementType, type ReactNode } from 'react'

interface Props {
  children: ReactNode
  as?: ElementType
  className?: string
  /** Atraso em ms (para entrar em cascata). */
  delay?: number
  /** Estilo da entrada. */
  effect?: 'up' | 'zoom' | 'left' | 'right' | 'blur'
}

/**
 * Faz o conteúdo "nascer" quando entra no ecrã (Outubro 2026). Só CSS +
 * IntersectionObserver,nada pesado. Com `data-motion="off"` (reduzir
 * movimento) o CSS mostra tudo de imediato.
 */
export function Reveal({ children, as: Tag = 'div', className = '', delay = 0, effect = 'up' }: Props) {
  const ref = useRef<HTMLElement>(null)

  useEffect(() => {
    const el = ref.current
    if (!el) return
    if (typeof IntersectionObserver === 'undefined') {
      el.classList.add('is-in')
      return
    }
    const io = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-in')
            io.unobserve(entry.target)
          }
        }
      },
      { rootMargin: '0px 0px -8% 0px', threshold: 0.12 },
    )
    io.observe(el)
    return () => io.disconnect()
  }, [])

  const style = { '--reveal-delay': `${delay}ms` } as CSSProperties
  return (
    <Tag ref={ref} className={`fx-reveal fx-reveal-${effect} ${className}`} style={style}>
      {children}
    </Tag>
  )
}
