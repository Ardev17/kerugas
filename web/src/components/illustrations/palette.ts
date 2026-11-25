import { useId } from 'react'

/** Paleta e auxiliares das ilustrações (Outubro 2026). */
export const C = {
  blue: '#FC7201',
  cyan: '#FFB020',
  navy: '#0F172A',
  slate: '#334155',
  fire: '#FF6B1A',
  amber: '#FFB020',
  red: '#DC2626',
  green: '#16A34A',
  sky: '#E0F2FE',
}

/** Ids únicos para gradientes (várias ilustrações na mesma página). */
export function useSvgIds<T extends string>(...names: T[]): Record<T, string> {
  const base = useId().replace(/:/g, '')
  return Object.fromEntries(names.map((n) => [n, `${n}-${base}`])) as Record<T, string>
}

