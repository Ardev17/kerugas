/** Vizinhos KeruGás (Outubro 2026),tipos da comunidade de cada município. */

export type VzBadge = 'team' | 'moderator' | 'driver' | 'station' | null
export type VzStatus = 'visible' | 'hidden' | 'removed' | 'deleted'

export interface VzAuthor {
  id: number
  name: string
  avatar_url: string | null
  badge: VzBadge
  neighborhood: string | null
  /** Chat da Equipa: papel, tipo ("Admin Geral" / "Admin Provincial" / "Admin"), província e município. */
  role?: string
  type_label?: string
  province?: string | null
  municipality?: string | null
}

export interface VzReply {
  id: number
  author_name: string | null
  excerpt: string | null
  available: boolean
}

/**
 * Localização partilhada numa mensagem (Outubro 2026). As coordenadas só vêm
 * enquanto a partilha está ativa; depois o servidor apaga-as.
 */
export interface VzLocation {
  kind: 'point' | 'station'
  /** Nome do posto (fica depois de a partilha acabar). */
  label: string | null
  active: boolean
  expires_at: string | null
  ended_at: string | null
  can_stop: boolean
  latitude: number | null
  longitude: number | null
  accuracy: number | null
  station: { id: number; name: string; gas_available: boolean | null; queue_level: string | null } | null
}

export interface VzLocationWindow {
  open: boolean
  opens_at: string
  closes_at: string
  durations: number[]
  closes_at_iso: string | null
  opens_at_iso: string
}

export interface VzReactor {
  user_id: number
  name: string
  avatar_url: string | null
  emoji: string
  me: boolean
}

export interface VzMessage {
  id: number
  municipality_id: number
  status: VzStatus
  topic: string | null
  neighborhood: { id: number; name: string } | null
  official: boolean
  pinned: boolean
  edited: boolean
  created_at: string
  author: VzAuthor | null
  /** Texto com as menções no formato `@[id]` (nunca o telemóvel). */
  body: string | null
  mentions: Record<string, string>
  image_url: string | null
  has_image: boolean
  reply_to: VzReply | null
  reactions: Record<string, number>
  my_reaction: string | null
  /** Quem reagiu (Outubro 2026),visível a todos os participantes. */
  reactors?: VzReactor[]
  mine: boolean
  mentions_me: boolean
  can_edit: boolean
  can_delete: boolean
  can_report: boolean
  can_moderate: boolean
  removal_reason: string | null
  location?: VzLocation | null
  report_count?: number
  can_hard_delete?: boolean
}

export interface VzModerationMessage extends VzMessage {
  municipality: string | null
  province: string | null
  province_id: number | null
  open_reports: number
}

export interface VzOverview {
  municipality: { id: number; name: string; province: string | null; enabled: boolean }
  neighborhoods: Array<{ id: number; name: string }>
  topics: Array<{ key: string; label: string }>
  report_reasons: Array<{ key: string; label: string }>
  emojis: string[]
  me: {
    accepted_rules: boolean
    neighborhood_id: number | null
    can_write: boolean
    writes_as_team: boolean
    muted: boolean
    muted_until: string | null
    is_staff: boolean
    can_administer: boolean
    is_super: boolean
    max_mute_hours: number | null
  }
  limits: { max_body: number; edit_minutes: number; max_mentions: number }
  switch_municipalities: Array<{ id: number; name: string; province: string | null; province_id: number }>
  presence: { online: number; active_today: number }
  location_share?: VzLocationWindow
}

export interface VzWindow {
  data: VzMessage[]
  pinned?: VzMessage[]
  meta: { has_older: boolean; has_newer: boolean; oldest_id: number | null; newest_id: number | null; municipality_id: number }
}

export interface VzFilters {
  neighborhood_id?: number
  topic?: string
  mine?: boolean
  mentions_me?: boolean
  staff_only?: boolean
  q?: string
}

export interface VzPerson {
  id: number
  name: string
  avatar_url: string | null
  badge: VzBadge
  neighborhood: string | null
}

export interface VzPresencePerson extends VzPerson {
  online: boolean
  last_seen_at: string
}

export interface VzPresence {
  online: number
  active_today: number
  people: VzPresencePerson[]
}
