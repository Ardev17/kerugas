import { api } from '@/lib/api'
import type { ApiSuccess, StationManagerOverview } from '@/types'

/**
 * Painel do Admin de Posto / gerente (Novembro 2026). O servidor usa sempre
 * o posto ligado à conta,nenhum pedido daqui escolhe outro posto.
 */
export const stationManagerService = {
  async overview(): Promise<StationManagerOverview> {
    const { data } = await api.get<ApiSuccess<StationManagerOverview>>('/station-manager/station')
    return data.data
  },

  async updateStation(payload: { name?: string; address?: string | null; reference_point?: string | null; phone?: string | null; opening_hours?: string | null; latitude?: number; longitude?: number }): Promise<StationManagerOverview> {
    const { data } = await api.put<ApiSuccess<StationManagerOverview>>('/station-manager/station', payload)
    return data.data
  },

  async addOperator(phone: string): Promise<StationManagerOverview> {
    const { data } = await api.post<ApiSuccess<StationManagerOverview>>('/station-manager/operators', { phone })
    return data.data
  },

  async removeOperator(userId: number): Promise<StationManagerOverview> {
    const { data } = await api.delete<ApiSuccess<StationManagerOverview>>(`/station-manager/operators/${userId}`)
    return data.data
  },

  async createDriver(payload: { name: string; phone: string; password: string }): Promise<StationManagerOverview> {
    const { data } = await api.post<ApiSuccess<StationManagerOverview>>('/station-manager/drivers', payload)
    return data.data
  },

  async updateDriver(id: number, payload: { name?: string; status?: 'ACTIVE' | 'SUSPENDED'; password?: string }): Promise<StationManagerOverview> {
    const { data } = await api.put<ApiSuccess<StationManagerOverview>>(`/station-manager/drivers/${id}`, payload)
    return data.data
  },

  async removeDriver(id: number): Promise<StationManagerOverview> {
    const { data } = await api.delete<ApiSuccess<StationManagerOverview>>(`/station-manager/drivers/${id}`)
    return data.data
  },
}
