import { useId } from 'react'

interface Props {
  size?: number
  className?: string
  /** false = chama apagada (cinzenta, parada),ex.: posto sem gás. */
  lit?: boolean
}

/**
 * Chama animada (Outubro 2026),três línguas de fogo com cores de fogo
 * real (vermelho por fora, laranja no corpo, amarelo-branco no núcleo) que
 * tremulam a ritmos diferentes. SVG + CSS: não pesa nada.
 */
export function GasFlame({ size = 96, className = '', lit = true }: Props) {
  // ids únicos: com ids repetidos, uma chama escondida (display:none)
  // "apagava" os gradientes de todas as outras.
  const id = useId().replace(/:/g, '')
  return (
    <span className={`fx-flame ${lit ? '' : 'is-out'} ${className}`} style={{ width: size, height: size * 1.25 }} aria-hidden="true">
      <span className="fx-flame-glow" />
      <svg viewBox="0 0 80 100" width={size} height={size * 1.25}>
        <defs>
          {/* Cores de fogo real: base amarela/branca, corpo laranja, pontas vermelhas. */}
          <linearGradient id={`foOuter${id}`} x1="0" y1="1" x2="0" y2="0">
            <stop offset="0%" stopColor="#FF7A00" />
            <stop offset="40%" stopColor="#F04A0C" />
            <stop offset="80%" stopColor="#C81E0A" />
            <stop offset="100%" stopColor="#8E0F05" stopOpacity="0.85" />
          </linearGradient>
          <linearGradient id={`foMid${id}`} x1="0" y1="1" x2="0" y2="0">
            <stop offset="0%" stopColor="#FFC93C" />
            <stop offset="60%" stopColor="#FF9A1F" />
            <stop offset="100%" stopColor="#FF6A0F" />
          </linearGradient>
          <radialGradient id={`foCore${id}`} cx="50%" cy="75%" r="50%">
            <stop offset="0%" stopColor="#FFFDF0" />
            <stop offset="45%" stopColor="#FFE680" />
            <stop offset="100%" stopColor="#FFC93C" stopOpacity="0" />
          </radialGradient>
        </defs>
        <path className="fx-flame-outer" fill={`url(#foOuter${id})`} d="M40 4C46 22 66 34 66 60c0 18-12 34-26 34S14 78 14 60c0-14 8-22 12-30 2 8 6 12 10 12C36 30 34 16 40 4Z" />
        <path className="fx-flame-mid" fill={`url(#foMid${id})`} d="M40 30c4 12 16 20 16 36 0 12-7 22-16 22s-16-10-16-22c0-8 5-13 8-18 1 5 4 8 7 8-1-8-1-17 1-26Z" />
        <ellipse className="fx-flame-core" cx="40" cy="74" rx="10" ry="14" fill={`url(#foCore${id})`} />
      </svg>
    </span>
  )
}
