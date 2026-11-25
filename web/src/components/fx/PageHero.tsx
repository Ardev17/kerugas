import type { ReactNode } from 'react'
import { GasFlame } from './GasFlame'

interface Props {
  /** Texto normal do título. */
  title: string
  /** Parte final do título, em gradiente de fogo (opcional). */
  highlight?: string
  subtitle?: ReactNode
  /** Mostra a chama ao lado do título. */
  flame?: boolean
  className?: string
}

/**
 * Cabeçalho das páginas de produto (Outubro 2026): luz a flutuar por trás,
 * título que entra a desfocar e uma parte em gradiente vivo. O texto do
 * <h1> continua igual ao de antes (leitores de ecrã e testes não mudam).
 */
export function PageHero({ title, highlight, subtitle, flame = true, className = '' }: Props) {
  return (
    <header className={`relative isolate ${className}`}>
      <div className="fx-hero-glow" aria-hidden="true" />
      <div className="fx-stagger flex items-center gap-3">
        {flame && (
          <span className="shrink-0">
            <GasFlame size={30} />
          </span>
        )}
        <h1 className="text-3xl font-extrabold tracking-tight text-dark sm:text-4xl">
          {title}
          {highlight && (
            <>
              {' '}
              <span className="fx-gradient-text">{highlight}</span>
            </>
          )}
        </h1>
      </div>
      {subtitle && (
        <div className="fx-stagger" style={{ ['--stagger-base' as string]: '0.12s' }}>
          <p className="mt-2 text-sm text-gray sm:text-base">{subtitle}</p>
        </div>
      )}
    </header>
  )
}
