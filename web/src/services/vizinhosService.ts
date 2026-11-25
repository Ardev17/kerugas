import { api } from '@/lib/api'
import type { ApiSuccess } from '@/types'
import type {
  VzFilters, VzMessage, VzModerationMessage, VzOverview, VzPerson, VzPresence, VzWindow,
} from '@/types/vizinhos'

export interface VzCursor {
  before_id?: number
  after_id?: number
  around_id?: number
}

interface Paginated<T> {
  success: true
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

/** Vizinhos KeruGás (Outubro 2026). O servidor decide sempre o que cada pessoa vê. */
export const vizinhosService = {
  async overview(municipalityId?: number): Promise<VzOverview> {
    const { data } = await api.get<ApiSuccess<VzOverview>>('/community/overview', { params: { municipality_id: municipalityId } })
    return data.data
  },
  async acceptRules(): Promise<void> {
    await api.post('/community/accept-rules')
  },
  async messages(municipalityId: number | undefined, filters: VzFilters, cursor: VzCursor = {}): Promise<VzWindow> {
    const params: Record<string, string | number | undefined> = { municipality_id: municipalityId, ...cursor }
    if (filters.neighborhood_id) params.neighborhood_id = filters.neighborhood_id
    if (filters.topic) params.topic = filters.topic
    if (filters.mine) params.mine = 1
    if (filters.mentions_me) params.mentions_me = 1
    if (filters.staff_only) params.staff_only = 1
    if (filters.q?.trim()) params.q = filters.q.trim()
    const { data } = await api.get<VzWindow>('/community/messages', { params })
    return data
  },
  async send(
    municipalityId: number,
    payload: { body: string; topic?: string | null; neighborhood_id?: number | null; reply_to_id?: number | null; image?: File | null },
  ): Promise<VzMessage> {
    const form = new FormData()
    form.append('body', payload.body)
    if (payload.topic) form.append('topic', payload.topic)
    if (payload.neighborhood_id) form.append('neighborhood_id', String(payload.neighborhood_id))
    if (payload.reply_to_id) form.append('reply_to_id', String(payload.reply_to_id))
    if (payload.image) form.append('image', payload.image)
    const { data } = await api.post<ApiSuccess<VzMessage>>('/community/messages', form, { params: { municipality_id: municipalityId }, timeout: 120_000 })
    return data.data
  },
  /** Partilhar a localização (um ponto ou um posto),sem texto. */
  async shareLocation(
    municipalityId: number,
    payload: { kind: 'point' | 'station'; latitude: number; longitude: number; accuracy?: number | null; station_id?: number | null; minutes: number },
  ): Promise<VzMessage> {
    const { data } = await api.post<ApiSuccess<VzMessage>>('/community/location', payload, { params: { municipality_id: municipalityId } })
    return data.data
  },
  async stopLocation(id: number): Promise<VzMessage> {
    const { data } = await api.post<ApiSuccess<VzMessage>>(`/community/messages/${id}/location/stop`)
    return data.data
  },
  /** Ids das partilhas ainda ativas no município. */
  async activeLocations(municipalityId: number): Promise<number[]> {
    const { data } = await api.get<ApiSuccess<number[]>>('/community/locations/active', { params: { municipality_id: municipalityId } })
    return data.data
  },
  async edit(id: number, body: string): Promise<VzMessage> {
    const { data } = await api.put<ApiSuccess<VzMessage>>(`/community/messages/${id}`, { body })
    return data.data
  },
  async remove(id: number): Promise<void> {
    await api.delete(`/community/messages/${id}`)
  },
  async react(id: number, emoji: string | null): Promise<VzMessage> {
    const { data } = await api.put<ApiSuccess<VzMessage>>(`/community/messages/${id}/reaction`, { emoji })
    return data.data
  },
  async report(id: number, reason: string, details?: string): Promise<void> {
    await api.post(`/community/messages/${id}/report`, { reason, details: details || undefined })
  },
  async mentionable(municipalityId: number, q: string): Promise<VzPerson[]> {
    const { data } = await api.get<ApiSuccess<VzPerson[]>>('/community/mentionable', { params: { municipality_id: municipalityId, q } })
    return data.data
  },
  async presence(municipalityId: number): Promise<VzPresence> {
    const { data } = await api.get<ApiSuccess<VzPresence>>('/community/presence', { params: { municipality_id: municipalityId } })
    return data.data
  },
  async block(userId: number): Promise<void> {
    await api.post(`/community/blocks/${userId}`)
  },
  async unblock(userId: number): Promise<void> {
    await api.delete(`/community/blocks/${userId}`)
  },
  async blocks(): Promise<Array<{ id: number; name: string; avatar_url: string | null }>> {
    const { data } = await api.get<ApiSuccess<Array<{ id: number; name: string; avatar_url: string | null }>>>('/community/blocks')
    return data.data
  },
  async neighborhoods(municipalityId: number): Promise<Array<{ id: number; name: string }>> {
    const { data } = await api.get<ApiSuccess<Array<{ id: number; name: string }>>>('/neighborhoods', { params: { municipality_id: municipalityId } })
    return data.data
  },

  // ── Moderação ──────────────────────────────────────────────────────────
  async moderationList(tab: string, page: number, municipalityId?: number): Promise<Paginated<VzModerationMessage>> {
    const { data } = await api.get<Paginated<VzModerationMessage>>('/community/moderation/messages', { params: { tab, page, municipality_id: municipalityId } })
    return data
  },
  async modReports(id: number): Promise<Array<{ id: number; reason: string; details: string | null; reporter: string | null; resolved: boolean }>> {
    const { data } = await api.get<ApiSuccess<Array<{ id: number; reason: string; details: string | null; reporter: string | null; resolved: boolean }>>>(`/community/moderation/messages/${id}/reports`)
    return data.data
  },
  async modRemove(id: number, reason: string): Promise<void> {
    await api.post(`/community/moderation/messages/${id}/remove`, { reason })
  },
  async modRestore(id: number): Promise<void> {
    await api.post(`/community/moderation/messages/${id}/restore`)
  },
  async modDismiss(id: number): Promise<void> {
    await api.post(`/community/moderation/messages/${id}/dismiss`)
  },
  async modHardDelete(id: number): Promise<void> {
    await api.delete(`/community/moderation/messages/${id}`)
  },
  async modPin(id: number, pinned: boolean): Promise<void> {
    if (pinned) await api.post(`/community/moderation/messages/${id}/pin`)
    else await api.delete(`/community/moderation/messages/${id}/pin`)
  },
  async modMute(userId: number, hours: number | null, reason?: string): Promise<void> {
    await api.post(`/community/moderation/mutes/${userId}`, { hours, reason: reason || undefined })
  },
  async modUnmute(userId: number): Promise<void> {
    await api.delete(`/community/moderation/mutes/${userId}`)
  },
  async modMutes(): Promise<Array<{ user_id: number; name: string | null; reason: string | null; expires_at: string | null; permanent: boolean }>> {
    const { data } = await api.get<ApiSuccess<Array<{ user_id: number; name: string | null; reason: string | null; expires_at: string | null; permanent: boolean }>>>('/community/moderation/mutes')
    return data.data
  },
  async modStats(): Promise<VzStats> {
    const { data } = await api.get<ApiSuccess<VzStats>>('/community/moderation/stats')
    return data.data
  },
  async bannedWords(): Promise<Array<{ id: number; word: string }>> {
    const { data } = await api.get<ApiSuccess<Array<{ id: number; word: string }>>>('/community/moderation/banned-words')
    return data.data
  },
  async addBannedWord(word: string): Promise<void> {
    await api.post('/community/moderation/banned-words', { word })
  },
  async removeBannedWord(id: number): Promise<void> {
    await api.delete(`/community/moderation/banned-words/${id}`)
  },
  async municipalitiesToggle(): Promise<Array<{ id: number; name: string; province: string | null; enabled: boolean }>> {
    const { data } = await api.get<ApiSuccess<Array<{ id: number; name: string; province: string | null; enabled: boolean }>>>('/community/moderation/municipalities')
    return data.data
  },
  async setMunicipalityEnabled(id: number, enabled: boolean): Promise<void> {
    await api.put(`/community/moderation/municipalities/${id}`, { enabled })
  },
  async createNeighborhood(municipalityId: number, name: string): Promise<void> {
    await api.post('/community/neighborhoods', { municipality_id: municipalityId, name })
  },
  async renameNeighborhood(id: number, name: string): Promise<void> {
    await api.put(`/community/neighborhoods/${id}`, { name })
  },
  async deleteNeighborhood(id: number): Promise<void> {
    await api.delete(`/community/neighborhoods/${id}`)
  },
}

export interface VzStats {
  total: number
  last_7_days: number
  open_reports: number
  removed: number
  per_day: Array<{ day: string; count: number }>
  top_municipalities: Array<{ name: string; count: number }>
}
