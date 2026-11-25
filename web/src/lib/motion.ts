/**
 * Nível de animação do aparelho (Outubro 2026),"intensa mas adaptável".
 *
 * - `off` ,a pessoa pediu "reduzir movimento" no sistema: só o essencial.
 * - `lite`,telemóvel modesto (≤ 4 núcleos ou ≤ 3 GB RAM) ou "poupança de
 *            dados" ligada: animações em CSS, poucas partículas, sem 3D.
 * - `full`,tudo: partículas, inclinação 3D, brilhos que seguem o dedo/rato.
 *
 * O nível fica em `<html data-motion="…">`; o CSS (globals.css, secção FX)
 * e os componentes em components/fx decidem a partir dele.
 */
export type MotionLevel = 'off' | 'lite' | 'full'

interface NavigatorHints {
  hardwareConcurrency?: number
  deviceMemory?: number
  connection?: { saveData?: boolean; effectiveType?: string }
}

export function detectMotionLevel(win: Pick<Window, 'matchMedia' | 'navigator'> = window): MotionLevel {
  try {
    if (win.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return 'off'
  } catch {
    // matchMedia indisponível: segue para as outras pistas
  }
  const nav = win.navigator as Navigator & NavigatorHints
  const cores = nav.hardwareConcurrency ?? 8
  const memory = nav.deviceMemory ?? 8
  const conn = nav.connection
  const slowNet = conn?.effectiveType === 'slow-2g' || conn?.effectiveType === '2g'
  if (conn?.saveData || slowNet || cores <= 4 || memory <= 3) return 'lite'
  return 'full'
}

let current: MotionLevel | null = null

export function motionLevel(): MotionLevel {
  // Nos testes automáticos tudo aparece já, sem animação (resultados estáveis).
  if (import.meta.env.MODE === 'test') return 'off'
  if (current === null) current = typeof window === 'undefined' ? 'off' : detectMotionLevel()
  return current
}

/** Chamado uma vez no arranque (main.tsx). Acompanha mudanças do "reduzir movimento". */
export function installMotionLevel(): void {
  const apply = () => {
    current = detectMotionLevel()
    document.documentElement.dataset.motion = current
  }
  apply()
  try {
    window.matchMedia('(prefers-reduced-motion: reduce)').addEventListener('change', apply)
  } catch {
    // browsers antigos: fica o valor inicial
  }
}
