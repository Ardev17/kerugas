import { api } from '@/lib/api'
import type { ApiSuccess, AuthUser, BottlePickup, DriverRatingsPage, DriverStats, DriverStock, GasOrder, PaginatedMeta } from '@/types'

interface Paginated<T> {
  success: true
  data: T[]
  meta: PaginatedMeta
}

/**
 * Dashboard do Condutor (Setembro 2026),só os pedidos atribuídos ao
 * próprio condutor (o backend já garante isto,
 * `DriverOrderController::index` filtra por `assigned_to === auth()->id()`).
 * Fluxo direto: ASSIGNED -> Feita/Cancelado, sem passo intermédio.
 */
export const driverService = {
  async listOrders(params: { status?: string } = {}): Promise<Paginated<GasOrder>> {
    const { data } = await api.get<Paginated<GasOrder>>('/driver/orders', { params })
    return data
  },
  async completeOrder(id: number): Promise<GasOrder> {
    const { data } = await api.post<ApiSuccess<GasOrder>>(`/driver/orders/${id}/complete`)
    return data.data
  },
  async cancelOrder(id: number, reason?: string): Promise<GasOrder> {
    const { data } = await api.post<ApiSuccess<GasOrder>>(`/driver/orders/${id}/cancel`, { reason })
    return data.data
  },
  /**
   * Alternância manual de disponibilidade,soma-se à alternância
   * automática já feita pelo backend ao atribuir/concluir/cancelar um
   * pedido (GasOrderService::assign/complete/cancel).
   */
  async toggleAvailability(): Promise<AuthUser> {
    const { data } = await api.post<ApiSuccess<AuthUser>>('/driver/availability')
    return data.data
  },
  /**
   * Estatísticas/ranking simples do próprio condutor (Setembro 2026) —
   * mesmo cálculo que o Admin/Super Admin veem em `adminService.getDriverStats`.
   */
  async getStats(): Promise<DriverStats> {
    const { data } = await api.get<ApiSuccess<DriverStats>>('/driver/stats')
    return data.data
  },
  /** As avaliações que recebeu, mais recentes primeiro; `stars` filtra (Outubro 2026). */
  async getRatings(page = 1, stars?: number): Promise<DriverRatingsPage> {
    const { data } = await api.get<DriverRatingsPage & { success: true }>('/driver/ratings', { params: { page, stars } })
    return { data: data.data, meta: data.meta, summary: data.summary }
  },
  /** "Garrafas disponíveis" + últimos carregamentos (Outubro 2026). */
  async getStock(): Promise<DriverStockOverview> {
    const { data } = await api.get<ApiSuccess<DriverStockOverview>>('/driver/stock')
    return data.data
  },
  async searchStations(search: string): Promise<{ id: number; code?: string; name: string; municipality: string | null }[]> {
    const { data } = await api.get<ApiSuccess<{ id: number; code?: string; name: string; municipality: string | null }[]>>('/driver/stations', { params: { search: search || undefined } })
    return data.data
  },
  /** O condutor confirma / contesta um carregamento que a central registou por ele. */
  async confirmCentralPickup(id: number): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>(`/driver/pickups/${id}/confirm`)
    return data.data
  },
  async disputeCentralPickup(id: number, note: string): Promise<BottlePickup> {
    const { data } = await api.post<ApiSuccess<BottlePickup>>(`/driver/pickups/${id}/dispute`, { note })
    return data.data
  },
  /** PIN pessoal (4 a 6 números) para registar carregamentos por SMS. */
  async setSmsPin(password: string, pin: string): Promise<void> {
    await api.put('/driver/sms-pin', { password, pin })
  },
  /** Idempotente por `client_uuid`,a fila offline pode reenviar sem duplicar. */
  async registerPickup(payload: PickupPayload): Promise<{ pickup: BottlePickup; stock: DriverStock }> {
    const { data } = await api.post<ApiSuccess<{ pickup: BottlePickup; stock: DriverStock }>>('/driver/pickups', payload)
    return data.data
  },
}

export interface DriverStockOverview {
  stock: DriverStock
  pickups: BottlePickup[]
  central_pickup?: { active: boolean; expires_at: string | null }
  sms_pin_set?: boolean
  sms_pin_temporary?: boolean
}

export interface PickupPayload {
  client_uuid: string
  station_id: number
  quantity: number
  amount: number
  registered_at?: string
  offline?: boolean
}
