import { api } from '@/lib/api'
import type { ApiSuccess, GasOrder, StationOrdersPage, StationOrdersSubscriptionInfo } from '@/types'

/**
 * Pedidos do município para postos subscritos (Novembro 2026),gerente e
 * operadores. O servidor usa sempre o posto da conta.
 */
export const stationOrderService = {
  async list(scope: 'open' | 'mine', page = 1): Promise<StationOrdersPage> {
    const { data } = await api.get<StationOrdersPage>('/station/orders', { params: { scope, page } })
    return data
  },

  async subscription(): Promise<StationOrdersSubscriptionInfo> {
    const { data } = await api.get<ApiSuccess<StationOrdersSubscriptionInfo>>('/station/subscription')
    return data.data
  },

  async accept(id: number, payload: { delivery_price: number; cylinder_types_confirmed?: string }): Promise<GasOrder> {
    const { data } = await api.post<ApiSuccess<GasOrder>>(`/station/orders/${id}/accept`, payload)
    return data.data
  },

  async assign(id: number, driverId: number): Promise<GasOrder> {
    const { data } = await api.post<ApiSuccess<GasOrder>>(`/station/orders/${id}/assign`, { driver_id: driverId })
    return data.data
  },

  async release(id: number, reason?: string): Promise<GasOrder> {
    const { data } = await api.post<ApiSuccess<GasOrder>>(`/station/orders/${id}/release`, { reason })
    return data.data
  },
}
