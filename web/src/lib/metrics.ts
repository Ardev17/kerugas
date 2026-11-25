/** Formatação do painel de métricas do Super Admin (Outubro 2026). */

/** 95 → "1 h 35 min"; 8 → "8 min"; null → "—". */
export function formatMinutes(minutes: number | null): string {
  if (minutes === null) return '—'
  const total = Math.round(minutes)
  if (total < 60) return `${total} min`
  const hours = Math.floor(total / 60)
  const rest = total % 60
  return rest === 0 ? `${hours} h` : `${hours} h ${rest} min`
}

export function formatKz(value: number): string {
  return `${value.toLocaleString('pt-AO', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} Kz`
}

export function formatPercent(value: number | null): string {
  return value === null ? '—' : `${value.toLocaleString('pt-PT', { maximumFractionDigits: 1 })}%`
}

export interface Trend {
  /** Variação em % face ao período anterior (arredondada), ou null quando não há base de comparação. */
  percent: number | null
  direction: 'up' | 'down' | 'flat' | 'none'
}

/**
 * Variação face ao período anterior. Sem período anterior (0) não existe
 * percentagem: "subiu 500%" a partir de zero engana,mostra-se "novo".
 */
export function trend(current: number, previous: number): Trend {
  if (previous === 0) return { percent: null, direction: current > 0 ? 'up' : 'none' }
  const percent = Math.round(((current - previous) / previous) * 100)
  return { percent, direction: percent > 0 ? 'up' : percent < 0 ? 'down' : 'flat' }
}
