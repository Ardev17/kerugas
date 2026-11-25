/**
 * Conversas por sala (Outubro 2026),a conversa dos condutores (por
 * município) e a "Conversa com a Central" (utilizador ↔ direção). As
 * mensagens têm o mesmo formato dos Vizinhos (VzMessage).
 */
import type { VzAuthor } from '@/types/vizinhos'

export type RoomType = 'drivers' | 'central' | 'investor'

export interface RoomPersonCard {
  id: number
  name: string
  phone?: string | null
  avatar_url: string | null
  type_label: string
  province: string | null
  municipality: string | null
}

export interface RoomOverview {
  room: { type: RoomType; id: number; title: string; person: RoomPersonCard | null }
  me: { can_write: boolean; is_staff: boolean; moderates: boolean }
  emojis: string[]
  limits: { max_body: number; edit_minutes: number; max_mentions: number; location_minutes: number[] }
}

export type RoomPerson = VzAuthor

export interface CentralMe {
  available: boolean
  room_id?: number
  unread: number
}

export interface CentralThread {
  user: RoomPersonCard
  last_message: { excerpt: string; from_user: boolean; author: string | null; created_at: string | null } | null
  total: number
  unread: number
}
