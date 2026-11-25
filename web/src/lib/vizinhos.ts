import type { VzBadge, VzLocation, VzLocationWindow, VzMessage } from '@/types/vizinhos'

/** "agora", "há 5 min", "há 3 h", "ontem", "há 4 dias" ou a data. */
export function timeAgo(iso: string, now: Date = new Date()): string {
  const then = new Date(iso)
  const seconds = Math.max(0, Math.round((now.getTime() - then.getTime()) / 1000))
  if (seconds < 60) return 'agora mesmo'
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `há ${minutes} min`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `há ${hours} h`
  const days = Math.floor(hours / 24)
  if (days === 1) return 'ontem'
  if (days < 7) return `há ${days} dias`
  return then.toLocaleDateString('pt-PT', { day: '2-digit', month: 'short' })
}

export function clockTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' })
}

/** "Hoje", "Ontem" ou a data,para separar os dias na conversa. */
export function dayLabel(iso: string, now: Date = new Date()): string {
  const d = new Date(iso)
  const start = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diff = Math.round((start(now) - start(d)) / 86_400_000)
  if (diff === 0) return 'Hoje'
  if (diff === 1) return 'Ontem'
  return d.toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' })
}

export const BADGE_LABEL: Record<Exclude<VzBadge, null>, string> = {
  team: 'Equipa KeruGás',
  moderator: 'Moderação',
  driver: 'Condutor',
  station: 'Posto',
}

export type BodyPart = { type: 'text'; text: string } | { type: 'mention'; id: number; name: string }

/** Parte o texto em pedaços de texto e menções (`@[12]` → nome). */
export function splitBody(body: string, mentions: Record<string, string>): BodyPart[] {
  const parts: BodyPart[] = []
  const re = /@\[(\d+)\]/g
  let last = 0
  for (let m = re.exec(body); m; m = re.exec(body)) {
    if (m.index > last) parts.push({ type: 'text', text: body.slice(last, m.index) })
    parts.push({ type: 'mention', id: Number(m[1]), name: mentions[m[1]] ?? 'vizinho' })
    last = m.index + m[0].length
  }
  if (last < body.length) parts.push({ type: 'text', text: body.slice(last) })
  return parts
}

/** Troca `@Nome` (escolhido na lista) por `@[id]` antes de enviar. */
export function encodeMentions(text: string, picked: Array<{ id: number; name: string }>): string {
  let out = text
  for (const p of [...picked].sort((a, b) => b.name.length - a.name.length)) {
    const escaped = p.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    out = out.replace(new RegExp(`@${escaped}(?![\\p{L}\\p{N}])`, 'gu'), `@[${p.id}]`)
  }
  return out
}

/** A palavra a seguir ao último `@` antes do cursor (para as sugestões). */
export function mentionQuery(text: string, caret: number): { query: string; start: number } | null {
  const before = text.slice(0, caret)
  const m = /(?:^|\s)@([^\s@]{0,30})$/u.exec(before)
  if (!m) return null
  return { query: m[1], start: before.length - m[1].length - 1 }
}

// ── Partilha de localização (Outubro 2026) ─────────────────────────────

/** A partilha ainda está a decorrer agora (o servidor diz que sim e a hora ainda não passou). */
export function locationLive(loc: VzLocation | null | undefined, now: Date = new Date()): boolean {
  return !!loc && loc.active && loc.latitude !== null && loc.longitude !== null
    && !!loc.expires_at && new Date(loc.expires_at).getTime() > now.getTime()
}

/** "30 min", "1 h", "1 h 30 min". */
export function durationLabel(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  if (h === 0) return `${m} min`
  return m === 0 ? `${h} h` : `${h} h ${m} min`
}

/** "faltam 42 min" / "falta 1 min" / "faltam 1 h 05 min". */
export function remainingLabel(expiresAt: string, now: Date = new Date()): string {
  const total = Math.max(0, Math.ceil((new Date(expiresAt).getTime() - now.getTime()) / 60_000))
  if (total <= 1) return 'falta menos de 1 min'
  const h = Math.floor(total / 60)
  const m = total % 60
  return h === 0 ? `faltam ${m} min` : `faltam ${h} h ${String(m).padStart(2, '0')} min`
}

/**
 * Durações que a pessoa pode escolher agora. Perto das 18h, uma duração maior
 * do que o que falta acaba às 18h,o botão diz "até às 18:00".
 */
export function durationOptions(win: VzLocationWindow | undefined, now: Date = new Date()): Array<{ minutes: number; label: string; capped: boolean }> {
  if (!win?.open) return []
  const closes = win.closes_at_iso ? new Date(win.closes_at_iso).getTime() : Infinity
  const left = Math.floor((closes - now.getTime()) / 60_000)
  const out: Array<{ minutes: number; label: string; capped: boolean }> = []
  for (const minutes of win.durations) {
    const capped = minutes > left
    out.push({ minutes, label: capped ? `até às ${win.closes_at}` : durationLabel(minutes), capped })
    if (capped) break // as seguintes acabariam também às 18h
  }
  return out
}

/** O posto mais perto, se a pessoa estiver mesmo nele (≤ 150 m). */
export function stationHere<T extends { id: number; distance_km?: number }>(stations: T[], maxKm = 0.15): T | null {
  const near = stations.filter((s) => typeof s.distance_km === 'number' && (s.distance_km as number) <= maxKm)
  near.sort((a, b) => (a.distance_km as number) - (b.distance_km as number))
  return near[0] ?? null
}

/** A mesma mensagem com a partilha terminada (sem coordenadas),para atualizar o ecrã sem pedir à API. */
export function withLocationEnded(m: VzMessage): VzMessage {
  if (!m.location) return m
  return {
    ...m,
    location: { ...m.location, active: false, can_stop: false, latitude: null, longitude: null, accuracy: null, station: null, ended_at: m.location.ended_at ?? new Date().toISOString() },
  }
}
