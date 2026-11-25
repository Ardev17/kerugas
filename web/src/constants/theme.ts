/**
 * Espelha os design tokens do tailwind.config.ts para uso em JS/TS
 * (ex: cores passadas a bibliotecas de mapa/gráficos que não leem Tailwind).
 */
export const theme = {
  primary: '#FC7201',
  secondary: '#FFB020',
  success: '#16A34A',
  warning: '#F59E0B',
  danger: '#DC2626',
  dark: '#0F172A',
  gray: '#64748B',
  light: '#F8FAFC',
} as const

export const queueLevelColor: Record<string, string> = {
  LOW: theme.success,
  MEDIUM: theme.warning,
  HIGH: theme.danger,
}

/**
 * Tradução em português dos valores de `queue_level` que vêm da API
 * (LOW/MEDIUM/HIGH, em inglês por serem os valores do enum no
 * backend). Fonte única para todo o frontend,nunca mostrar o valor
 * em bruto da API a um utilizador, seja no cartão do posto, no mapa
 * ou nos detalhes do posto.
 */
export const queueLevelLabel: Record<string, string> = {
  LOW: 'Fila baixa',
  MEDIUM: 'Fila média',
  HIGH: 'Fila alta',
}

/**
 * Estado de um posto no mapa,UMA fonte para a cor do ponto, o texto do
 * popup e a legenda, para nunca discordarem (Outubro 2026: o ponto ficava
 * vermelho por "fila alta" enquanto o popup dizia só "Gás disponível").
 *
 * Sem gás é sempre cinzento; com gás, a cor é a da fila.
 */
export type MarkerStatusKey = 'NO_GAS' | 'LOW' | 'MEDIUM' | 'HIGH' | 'UNKNOWN'

export const markerStatuses: Record<MarkerStatusKey, { color: string; label: string }> = {
  LOW: { color: theme.success, label: 'Gás disponível · fila baixa' },
  MEDIUM: { color: theme.warning, label: 'Gás disponível · fila média' },
  HIGH: { color: theme.danger, label: 'Gás disponível · fila alta' },
  UNKNOWN: { color: theme.primary, label: 'Gás disponível · fila desconhecida' },
  NO_GAS: { color: theme.gray, label: 'Gás indisponível' },
}

export function markerStatusKey(station: { gas_available: boolean; queue_level: string | null }): MarkerStatusKey {
  if (!station.gas_available) return 'NO_GAS'
  return station.queue_level === 'LOW' || station.queue_level === 'MEDIUM' || station.queue_level === 'HIGH'
    ? station.queue_level
    : 'UNKNOWN'
}
