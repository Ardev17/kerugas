import { api } from '@/lib/api'
import type {
  ApiSuccess, PaginatedMeta, SmsSubscriptionStatus, Subscription, SubscriptionKind,
  SubscriptionSettings, SubscriptionSummary,
} from '@/types'

export interface SubscriptionList {
  data: Subscription[]
  meta: PaginatedMeta & { summary: SubscriptionSummary }
}

export interface ActivateSubscriptionPayload {
  type: SubscriptionKind
  phone?: string
  station_id?: number
  months: number
  /** Pedidos do município (Novembro 2026): número de semanas. */
  weeks?: number
  amount_kz?: number | null
  payment_reference?: string | null
  notes?: string | null
  send_confirmation_sms?: boolean
}

export interface ActivateResult {
  subscription: Subscription
  meta: { staff_notified?: boolean; operator_notified?: boolean; confirmation_sms?: 'sent' | 'failed' | 'not_sent' }
}

/** Subscrições mensais,só Super Admin. A ativação é sempre manual. */
export const subscriptionService = {
  async list(params: { type?: string; state?: string; search?: string; page?: number } = {}): Promise<SubscriptionList> {
    const { data } = await api.get<SubscriptionList & { success: true }>('/admin/subscriptions', { params })
    return data
  },

  async activate(payload: ActivateSubscriptionPayload): Promise<ActivateResult> {
    const { data } = await api.post<ApiSuccess<Subscription> & { meta: ActivateResult['meta'] }>('/admin/subscriptions', payload)
    return { subscription: data.data, meta: data.meta ?? {} }
  },

  async cancel(id: number): Promise<Subscription> {
    const { data } = await api.post<ApiSuccess<Subscription>>(`/admin/subscriptions/${id}/cancel`)
    return data.data
  },

  async lookup(phone: string): Promise<{ phone: string; status: SmsSubscriptionStatus; history: Subscription[] }> {
    const { data } = await api.get<ApiSuccess<{ phone: string; status: SmsSubscriptionStatus; history: Subscription[] }>>(
      '/admin/subscriptions/lookup', { params: { phone } },
    )
    return data.data
  },

  async settings(): Promise<SubscriptionSettings> {
    const { data } = await api.get<ApiSuccess<SubscriptionSettings>>('/admin/subscriptions/settings')
    return data.data
  },

  async updateSettings(payload: Partial<SubscriptionSettings>): Promise<SubscriptionSettings> {
    const { data } = await api.put<ApiSuccess<SubscriptionSettings>>('/admin/subscriptions/settings', payload)
    return data.data
  },
}
