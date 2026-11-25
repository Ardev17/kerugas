/** Dados das ilustrações do condutor (Outubro 2026). */
export type DayPart = 'morning' | 'afternoon' | 'evening' | 'night'

export function dayPart(hour: number): DayPart {
  if (hour >= 5 && hour < 11) return 'morning'
  if (hour >= 11 && hour < 17) return 'afternoon'
  if (hour >= 17 && hour < 19) return 'evening'
  return 'night'
}

export const SKY: Record<DayPart, { top: string; bottom: string; label: string }> = {
  morning: { top: '#4A6DB0', bottom: '#FDBA74', label: 'Bom dia' },
  afternoon: { top: '#0EA5E9', bottom: '#BAE6FD', label: 'Boa tarde' },
  evening: { top: '#FC7201', bottom: '#FB923C', label: 'Boa tarde' },
  night: { top: '#020617', bottom: '#11264B', label: 'Boa noite' },
}

export function greetingFor(hour: number): string {
  return SKY[dayPart(hour)].label
}

export interface Achievement {
  key: string
  label: string
  detail: string
  unlocked: boolean
  tone: 'bronze' | 'silver' | 'gold' | 'fire' | 'star'
}

/** Conquistas a partir das estatísticas que o condutor já vê. */
export function driverAchievements(delivered: number, rating: number | null, ratings: number): Achievement[] {
  return [
    { key: 'd10', label: '10 entregas', detail: 'Primeiros passos', unlocked: delivered >= 10, tone: 'bronze' },
    { key: 'd50', label: '50 entregas', detail: 'Condutor de confiança', unlocked: delivered >= 50, tone: 'silver' },
    { key: 'd100', label: '100 entregas', detail: 'Centena!', unlocked: delivered >= 100, tone: 'gold' },
    { key: 'd500', label: '500 entregas', detail: 'Lenda da estrada', unlocked: delivered >= 500, tone: 'fire' },
    { key: 'star', label: 'Cinco estrelas', detail: 'Média 4,8+ com 10 avaliações', unlocked: (rating ?? 0) >= 4.8 && ratings >= 10, tone: 'star' },
  ]
}

