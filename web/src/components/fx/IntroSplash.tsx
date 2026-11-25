import { useEffect, useState } from 'react'
import { motionLevel } from '@/lib/motion'
import { GasFlame } from './GasFlame'

const KEY = 'kerugas_intro_seen'
const LETTERS = 'KeruGás'.split('')

function alreadySeen(): boolean {
  try {
    return sessionStorage.getItem(KEY) === '1'
  } catch {
    return false
  }
}

/**
 * Abertura (Outubro 2026): a chama acende-se, solta uma onda de luz, o
 * nome cai letra a letra e a cortina sobe para revelar a app. ~1,6 s,
 * uma vez por sessão, e um toque salta-a. Não aparece com "reduzir
 * movimento".
 */
export function IntroSplash() {
  const [phase, setPhase] = useState<'show' | 'leave' | 'gone'>(() =>
    motionLevel() === 'off' || alreadySeen() ? 'gone' : 'show',
  )

  useEffect(() => {
    if (phase === 'gone') return
    try {
      sessionStorage.setItem(KEY, '1')
    } catch {
      // modo privado: mostra de novo na próxima vez, sem problema
    }
    const t1 = window.setTimeout(() => setPhase('leave'), motionLevel() === 'full' ? 1500 : 1100)
    const t2 = window.setTimeout(() => setPhase('gone'), motionLevel() === 'full' ? 2150 : 1650)
    return () => {
      window.clearTimeout(t1)
      window.clearTimeout(t2)
    }
    // só no arranque
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (phase === 'gone') return null

  return (
    <div
      className={`fx-intro ${phase === 'leave' ? 'is-leaving' : ''}`}
      role="presentation"
      onClick={() => setPhase('gone')}
      data-testid="intro-splash"
    >
      <div className="fx-intro-aurora" />
      <div className="fx-intro-stage">
        <span className="fx-intro-ring" />
        <span className="fx-intro-ring fx-intro-ring-2" />
        <GasFlame size={88} className="fx-intro-flame" />
        <p className="fx-intro-word" aria-label="KeruGás">
          {LETTERS.map((ch, i) => (
            <span key={i} style={{ animationDelay: `${420 + i * 55}ms` }} aria-hidden="true">
              {ch}
            </span>
          ))}
        </p>
        <p className="fx-intro-tag">Gás perto de si, em tempo real</p>
      </div>
    </div>
  )
}
