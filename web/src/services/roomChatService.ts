import { api } from '@/lib/api'
import type { ApiSuccess } from '@/types'
import type { CentralMe, CentralThread, RoomOverview, RoomPerson, RoomType } from '@/types/roomChat'
import type { VzMessage, VzWindow } from '@/types/vizinhos'

export interface RoomCursor {
  before_id?: number
  after_id?: number
  around_id?: number
}

export interface RoomSendPayload {
  body?: string
  reply_to_id?: number | null
  image?: File | null
  location?: { latitude: number; longitude: number; accuracy: number | null; minutes: number } | null
}

/** Conversa dos condutores e Conversa com a Central (Outubro 2026). */
export const roomChatService = {
  async overview(type: RoomType, roomId: number): Promise<RoomOverview> {
    const { data } = await api.get<ApiSuccess<RoomOverview>>(`/rooms/${type}/${roomId}/overview`)
    return data.data
  },
  async messages(type: RoomType, roomId: number, cursor: RoomCursor = {}): Promise<VzWindow> {
    const { data } = await api.get<VzWindow>(`/rooms/${type}/${roomId}/messages`, { params: cursor })
    return data
  },
  async send(type: RoomType, roomId: number, payload: RoomSendPayload): Promise<VzMessage> {
    const form = new FormData()
    form.append('body', payload.body ?? '')
    if (payload.reply_to_id) form.append('reply_to_id', String(payload.reply_to_id))
    if (payload.image) form.append('image', payload.image)
    if (payload.location) {
      form.append('latitude', String(payload.location.latitude))
      form.append('longitude', String(payload.location.longitude))
      if (payload.location.accuracy !== null) form.append('accuracy', String(Math.round(payload.location.accuracy)))
      form.append('minutes', String(payload.location.minutes))
    }
    const { data } = await api.post<ApiSuccess<VzMessage>>(`/rooms/${type}/${roomId}/messages`, form, { timeout: 120_000 })
    return data.data
  },
  async edit(id: number, body: string): Promise<VzMessage> {
    const { data } = await api.put<ApiSuccess<VzMessage>>(`/rooms/messages/${id}`, { body })
    return data.data
  },
  async remove(id: number, reason?: string): Promise<void> {
    await api.delete(`/rooms/messages/${id}`, { data: reason ? { reason } : {} })
  },
  async react(id: number, emoji: string | null): Promise<VzMessage> {
    const { data } = await api.put<ApiSuccess<VzMessage>>(`/rooms/messages/${id}/reaction`, { emoji })
    return data.data
  },
  async stopLocation(id: number): Promise<VzMessage> {
    const { data } = await api.post<ApiSuccess<VzMessage>>(`/rooms/messages/${id}/location/stop`)
    return data.data
  },
  async mentionable(type: RoomType, roomId: number, q: string): Promise<RoomPerson[]> {
    const { data } = await api.get<ApiSuccess<RoomPerson[]>>(`/rooms/${type}/${roomId}/mentionable`, { params: { q } })
    return data.data
  },

  // ── Conversa com a Central ─────────────────────────────────────────────
  async centralMe(): Promise<CentralMe> {
    const { data } = await api.get<ApiSuccess<CentralMe>>('/central-chat/me')
    return data.data
  },
  async centralThreads(): Promise<CentralThread[]> {
    const { data } = await api.get<ApiSuccess<CentralThread[]>>('/admin/central-threads')
    return data.data
  },
}
