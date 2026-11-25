/** Chat da Equipa (Outubro 2026),as mensagens têm o mesmo formato dos Vizinhos (VzMessage). */
import type { VzAuthor } from '@/types/vizinhos'

export type StaffRole = 'SUPER_ADMIN' | 'PROVINCE_SUPER_ADMIN' | 'ADMIN'

/** Pessoa da equipa: nome, tipo, província e município. */
export interface StaffPerson extends VzAuthor {
  role: StaffRole
  type_label: string
  province: string | null
  municipality: string | null
}

export interface StaffPresencePerson extends StaffPerson {
  online: boolean
  last_seen_at: string
}

export interface StaffPresence {
  online: number
  active_today: number
  people: StaffPresencePerson[]
}

export interface StaffOverview {
  me: { type: StaffRole; type_label: string; can_write: boolean; muted: boolean; muted_until: string | null; is_super: boolean }
  emojis: string[]
  types: Array<{ key: StaffRole; label: string }>
  provinces: Array<{ id: number; name: string }>
  limits: { max_body: number; edit_minutes: number; max_mentions: number }
  members: number
  presence: { online: number; active_today: number }
}

export interface StaffFilters {
  type?: StaffRole
  province_id?: number
  mine?: boolean
  mentions_me?: boolean
  q?: string
}
