/**
 * Há quanto tempo foi a última atualização (Outubro 2026),tempo exato
 * em vez de rótulos vagos como "muito recente" ou "desatualizada".
 *
 * Mostra a unidade maior e, se houver, a seguinte: "há 6 dias e 3 horas",
 * "há 5 horas e 9 minutos", "há 9 minutos". Menos de 1 minuto: "há menos
 * de 1 minuto".
 */
const MINUTE = 60_000
const HOUR = 60 * MINUTE
const DAY = 24 * HOUR

function unit(value: number, singular: string, plural: string): string {
  return `${value} ${value === 1 ? singular : plural}`
}

export function elapsedLabel(from: Date | string, now: Date = new Date()): string {
  const start = typeof from === 'string' ? new Date(from) : from
  const diff = now.getTime() - start.getTime()
  if (Number.isNaN(diff)) return ''
  // Relógio do aparelho ligeiramente atrasado em relação ao servidor.
  if (diff < MINUTE) return 'há menos de 1 minuto'

  const days = Math.floor(diff / DAY)
  const hours = Math.floor((diff % DAY) / HOUR)
  const minutes = Math.floor((diff % HOUR) / MINUTE)

  const parts: string[] = []
  if (days > 0) {
    parts.push(unit(days, 'dia', 'dias'))
    if (hours > 0) parts.push(unit(hours, 'hora', 'horas'))
  } else if (hours > 0) {
    parts.push(unit(hours, 'hora', 'horas'))
    if (minutes > 0) parts.push(unit(minutes, 'minuto', 'minutos'))
  } else {
    parts.push(unit(minutes, 'minuto', 'minutos'))
  }
  return `há ${parts.join(' e ')}`
}

export function lastUpdateLabel(updatedAt: string | null | undefined, now: Date = new Date()): string {
  if (!updatedAt) return 'Sem atualização registada'
  const elapsed = elapsedLabel(updatedAt, now)
  return elapsed ? `Última atualização ${elapsed}` : 'Sem atualização registada'
}
