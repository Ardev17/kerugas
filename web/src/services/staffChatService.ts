import { api } from '@/lib/api'
import type { ApiSuccess } from '@/types'
import type { VzMessage, VzWindow } from '@/types/vizinhos'
import type { StaffOverview, StaffPresence, StaffFilters, StaffPerson } from '@/types/staffChat'

export interface StaffCursor {
  before_id?: number
  after_id?: number
  around_id?: number
}

/** Chat da Equipa (Outubro 2026),sala única dos admins de todo o país. */
export const staffChatService = {
  async overview(): Promise<StaffOverview> {
    const { data } = await api.get<ApiSuccess<StaffOverview>>('/staff-chat/overview')
    return data.data
  },
  async messages(filters: StaffFilters, cursor: StaffCursor = {}): Promise<VzWindow> {
    const params: Record<string, string | number | undefined> = { ...cursor }
    if (filters.type) params.type = filters.type
    if (filters.province_id) params.province_id = filters.province_id
    if (filters.mine) params.mine = 1
    if (filters.mentions_me) params.mentions_me = 1
    if (filters.q?.trim()) params.q = filters.q.trim()
    const { data } = await api.get<VzWindow>('/staff-chat/messages', { params })
    return data
  },
  async send(payload: { body: string; reply_to_id?: number | null; image?: File | null }): Promise<VzMessage> {
    const form = new FormData()
    form.append('body', payload.body)
    if (payload.reply_to_id) form.append('reply_to_id', String(payload.reply_to_id))
    if (payload.image) form.append('image', payload.image)
    const { data } = await api.post<ApiSuccess<VzMessage>>('/staff-chat/messages', form, { timeout: 120_000 })
    return data.data
  },
  async edit(id: number, body: string): Promise<VzMessage> {
    const { data } = await api.put<ApiSuccess<VzMessage>>(`/staff-chat/messages/${id}`, { body })
    return data.data
  },
  async remove(id: number): Promise<void> {
    await api.delete(`/staff-chat/messages/${id}`)
  },
  async react(id: number, emoji: string | null): Promise<VzMessage> {
    const { data } = await api.put<ApiSuccess<VzMessage>>(`/staff-chat/messages/${id}/reaction`, { emoji })
    return data.data
  },
  async mentionable(q: string): Promise<StaffPerson[]> {
    const { data } = await api.get<ApiSuccess<StaffPerson[]>>('/staff-chat/mentionable', { params: { q } })
    return data.data
  },
  async presence(): Promise<StaffPresence> {
    const { data } = await api.get<ApiSuccess<StaffPresence>>('/staff-chat/presence')
    return data.data
  },

  // ── Só o Admin Geral ───────────────────────────────────────────────────
  async pin(id: number, pinned: boolean): Promise<void> {
    if (pinned) await api.post(`/staff-chat/messages/${id}/pin`)
    else await api.delete(`/staff-chat/messages/${id}/pin`)
  },
  async modRemove(id: number, reason: string): Promise<void> {
    await api.post(`/staff-chat/messages/${id}/remove`, { reason })
  },
  async modRestore(id: number): Promise<void> {
    await api.post(`/staff-chat/messages/${id}/restore`)
  },
  async purge(id: number): Promise<void> {
    await api.delete(`/staff-chat/messages/${id}/purge`)
  },
  async mute(userId: number, hours: number | null, reason?: string): Promise<void> {
    await api.post(`/staff-chat/mutes/${userId}`, { hours, reason: reason || undefined })
  },
  async unmute(userId: number): Promise<void> {
    await api.delete(`/staff-chat/mutes/${userId}`)
  },
}
