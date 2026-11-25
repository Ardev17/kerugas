import { api } from '@/lib/api'
import type { ApiSuccess, Alert } from '@/types'

export const alertService = {
  async list(): Promise<Alert[]> {
    const { data } = await api.get<ApiSuccess<Alert[]>>('/alerts')
    return data.data
  },

  async create(payload: { station_id?: number; municipality_id?: number; max_distance_km?: number; channels: string[] }): Promise<Alert> {
    const { data } = await api.post<ApiSuccess<Alert>>('/alerts', payload)
    return data.data
  },

  async remove(id: number): Promise<void> {
    await api.delete(`/alerts/${id}`)
  },
}
