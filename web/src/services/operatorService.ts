import { api } from '@/lib/api'
import type { AdminStation, ApiSuccess, BottlePickup, StationCounter, OperatorAnalytics, OperatorStationHistoryItem, OperatorSubscriptionInfo } from '@/types'
import { triggerBlobDownload, filenameFromContentDisposition } from '@/lib/download'

export interface OperatorPickups {
  station: { id: number; name: string }
  counter: StationCounter
  pickups: BottlePickup[]
}

export const operatorService = {
  // Garrafas levadas pela KeruGás (Outubro 2026)
  async pickups(): Promise<OperatorPickups> {
    const { data } = await api.get<ApiSuccess<OperatorPickups>>('/operator/pickups')
    return data.data
  },
  async confirmPickup(id: number): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>(`/operator/pickups/${id}/confirm`)
    return data.data
  },
  async disputePickup(id: number, note: string): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>(`/operator/pickups/${id}/dispute`, { note })
    return data.data
  },

  async myStation(): Promise<AdminStation> {
    const { data } = await api.get<ApiSuccess<AdminStation>>('/operator/station')
    return data.data
  },

  async history(): Promise<OperatorStationHistoryItem[]> {
    const { data } = await api.get<ApiSuccess<OperatorStationHistoryItem[]>>('/operator/station/history')
    return data.data
  },

  async analytics(): Promise<OperatorAnalytics> {
    const { data } = await api.get<ApiSuccess<OperatorAnalytics>>('/operator/station/analytics')
    return data.data
  },

  async subscription(): Promise<OperatorSubscriptionInfo> {
    const { data } = await api.get<ApiSuccess<OperatorSubscriptionInfo>>('/operator/station/subscription')
    return data.data
  },

  async updateStatus(payload: { gas_available: boolean; queue_level: string; cylinder_types?: string }): Promise<AdminStation> {
    const { data } = await api.post<ApiSuccess<AdminStation>>('/operator/station/status', payload)
    return data.data
  },

  /**
   * Exporta o histórico do PRÓPRIO posto (Excel ou PDF). O backend
   * ignora qualquer filtro de posto/município/província enviado por
   * esta rota,o operador nunca escolhe âmbito, só o período.
   */
  async exportStationStatus(params: { format: 'csv' | 'pdf'; period: 'day' | 'week' | 'month'; date?: string }): Promise<void> {
    const response = await api.get<Blob>('/operator/station/export', { params, responseType: 'blob' })
    const filename = filenameFromContentDisposition(response.headers['content-disposition'], `kerugas-historico.${params.format}`)
    await triggerBlobDownload(response.data, filename)
  },
}
