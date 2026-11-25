import { useEffect, useRef, useState } from 'react'
import { motionLevel } from '@/lib/motion'

interface Props {
  value: number
  /** Duração em ms. */
  duration?: number
  /** Casas decimais a mostrar. */
  decimals?: number
  suffix?: string
}

/**
 * Número que "conta" até ao valor (Outubro 2026). Quando o valor muda
 * (dados em tempo real), conta a partir do anterior. Sem animação no
 * nível `off`,mostra logo o valor final.
 */
export function CountUp({ value, duration = 1100, decimals = 0, suffix = '' }: Props) {
  const animate = motionLevel() !== 'off'
  const [shown, setShown] = useState(animate ? 0 : value)
  const from = useRef(animate ? 0 : value)

  useEffect(() => {
    if (!animate || !Number.isFinite(value)) {
      setShown(value)
      return
    }
    const start = performance.now()
    const a = from.current
    let raf = 0
    const tick = (now: number) => {
      const t = Math.min(1, (now - start) / duration)
      const eased = 1 - Math.pow(1 - t, 3)
      setShown(a + (value - a) * eased)
      if (t < 1) raf = requestAnimationFrame(tick)
      else from.current = value
    }
    raf = requestAnimationFrame(tick)
    return () => {
      cancelAnimationFrame(raf)
      from.current = value
    }
  }, [value, duration, animate])

  const text = shown.toLocaleString('pt-PT', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })
  return (
    <span className="tabular-nums">
      {text}
      {suffix}
    </span>
  )
}
