import { api } from '@/lib/api'
import type { ApiSuccess, DriverRatingStatus, GasOrder, GasOrderPayload, PaginatedMeta } from '@/types'

/** Filtros de "Os meus pedidos": em curso, entregues, cancelados (inclui falhados). */
export type MyOrdersFilter = 'active' | 'delivered' | 'cancelled'

export const gasOrderService = {
  async create(payload: GasOrderPayload): Promise<GasOrder> {
    const { data } = await api.post<ApiSuccess<GasOrder>>('/orders', payload)
    return data.data
  },

  /** Acompanhamento do pedido (Setembro 2026),só o dono ou um Admin. */
  async getByReference(reference: string): Promise<GasOrder> {
    const { data } = await api.get<ApiSuccess<GasOrder>>(`/orders/reference/${encodeURIComponent(reference)}`)
    return data.data
  },

  /**
   * Acompanhamento SEM conta (Outubro 2026),referência + telefone do
   * pedido, como na avaliação do condutor. Devolve só os campos seguros.
   */
  async trackByPhone(reference: string, customerPhone: string): Promise<GasOrder> {
    const { data } = await api.get<ApiSuccess<GasOrder>>(`/orders/reference/${encodeURIComponent(reference)}/track`, {
      params: { customer_phone: customerPhone },
    })
    return data.data
  },

  /** "Os meus pedidos" (Setembro 2026),pedidos feitos com esta conta. */
  async listMine(page = 1, filter?: MyOrdersFilter): Promise<{ data: GasOrder[]; meta: PaginatedMeta }> {
    const { data } = await api.get<{ data: GasOrder[]; meta: PaginatedMeta }>('/orders', { params: { page, filter } })
    return data
  },

  /**
   * Avaliação do condutor (Setembro 2026),rota pública, sem login:
   * a referência do pedido + o telefone do cliente servem de prova de
   * posse (ver DriverRatingController). `getRatingStatus` é chamado
   * antes de mostrar o formulário, para saber se já pode avaliar.
   */
  async getRatingStatus(reference: string, customerPhone: string): Promise<DriverRatingStatus> {
    const { data } = await api.get<ApiSuccess<DriverRatingStatus>>(`/orders/reference/${reference}/rating`, {
      params: { customer_phone: customerPhone },
    })
    return data.data
  },

  async submitRating(
    reference: string,
    payload: { customer_phone: string; rating: number; comment?: string },
  ): Promise<{ rating: number; comment: string | null }> {
    const { data } = await api.post<ApiSuccess<{ rating: number; comment: string | null }>>(
      `/orders/reference/${reference}/rating`,
      payload,
    )
    return data.data
  },
}
