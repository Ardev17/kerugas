/** Investidores (Outubro 2026): períodos rápidos do painel de faturação. */
export type Preset = 'month' | 'last_month' | 'days30' | 'year' | 'custom'

export const PRESETS: Array<{ key: Preset; label: string }> = [
  { key: 'month', label: 'Este mês' },
  { key: 'last_month', label: 'Mês passado' },
  { key: 'days30', label: 'Últimos 30 dias' },
  { key: 'year', label: 'Este ano' },
  { key: 'custom', label: 'Escolher datas' },
]

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

export function presetRange(preset: Preset, today = new Date()): { from: string; to: string } | null {
  const y = today.getFullYear()
  const m = today.getMonth()
  switch (preset) {
    case 'month': return { from: ymd(new Date(y, m, 1)), to: ymd(today) }
    case 'last_month': return { from: ymd(new Date(y, m - 1, 1)), to: ymd(new Date(y, m, 0)) }
    case 'days30': return { from: ymd(new Date(y, m, today.getDate() - 29)), to: ymd(today) }
    case 'year': return { from: ymd(new Date(y, 0, 1)), to: ymd(today) }
    default: return null
  }
}

