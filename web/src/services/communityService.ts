import { api } from '@/lib/api'
import type { ApiSuccess, StationComment, StationReview } from '@/types'

export const communityService = {
  async listReviews(stationId: number): Promise<StationReview[]> {
    const { data } = await api.get<ApiSuccess<StationReview[]>>(`/stations/${stationId}/reviews`)
    return data.data
  },

  async createReview(stationId: number, payload: { rating: number; comment?: string }): Promise<StationReview> {
    const { data } = await api.post<ApiSuccess<StationReview>>(`/stations/${stationId}/reviews`, payload)
    return data.data
  },

  async listComments(stationId: number): Promise<StationComment[]> {
    const { data } = await api.get<ApiSuccess<StationComment[]>>(`/stations/${stationId}/comments`)
    return data.data
  },

  async createComment(stationId: number, content: string): Promise<StationComment> {
    const { data } = await api.post<ApiSuccess<StationComment>>(`/stations/${stationId}/comments`, { content })
    return data.data
  },

  async createReport(stationId: number, payload: { reason: string; description?: string }): Promise<void> {
    await api.post(`/stations/${stationId}/reports`, payload)
  },

  async createConfirmation(stationId: number, payload: { gas_available: boolean; queue_level?: string }): Promise<{ confidence_score: number }> {
    const { data } = await api.post<ApiSuccess<{ confidence_score: number }>>(`/stations/${stationId}/confirmations`, payload)
    return data.data
  },
}
