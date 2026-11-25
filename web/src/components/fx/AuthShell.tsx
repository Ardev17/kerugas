import type { ReactNode } from 'react'
import { EmberField } from './EmberField'
import { GasFlame } from './GasFlame'

interface Props {
  title: string
  subtitle: string
  children: ReactNode
}

/**
 * Moldura dos ecrãs de conta (Outubro 2026): fundo vivo (aurora +
 * brasas), cartão de vidro que entra a desfocar, chama a arder por cima
 * do título e campos que surgem em cascata.
 */
export function AuthShell({ title, subtitle, children }: Props) {
  return (
    <div className="relative isolate overflow-hidden px-4 py-12 sm:py-16">
      <div className="fx-aurora" aria-hidden="true"><i /></div>
      <div className="fx-grid" aria-hidden="true" />
      <EmberField density={0.45} />
      <div className="fx-auth-card relative mx-auto max-w-md rounded-3xl p-6 sm:p-8">
        <div className="flex flex-col items-center text-center">
          <GasFlame size={44} />
          <h1 className="mt-3 text-3xl font-extrabold tracking-tight text-dark">
            <span className="fx-gradient-text">{title}</span>
          </h1>
          <p className="mt-1 text-sm text-gray">{subtitle}</p>
        </div>
        {children}
      </div>
    </div>
  )
}
