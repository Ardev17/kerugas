import { api } from '@/lib/api'
import type { ApiSuccess, PaginatedMeta } from '@/types'

export interface CampaignTemplate {
  title: string
  body: string
  sms?: string | null
  occasion?: string | null
}

export interface Campaign {
  key: string
  name: string
  description: string
  trigger: 'scheduled' | 'event'
  schedule: string
  audience: string
  category: string
  marketing: boolean
  enabled: boolean
  sms_allowed: boolean
  sms_enabled: boolean
  variables: string[]
  templates: CampaignTemplate[]
  can_run_now: boolean
  stats: {
    sent_30d: number
    converted_30d: number
    conversion_rate: number | null
    sms_sent_30d: number
    sms_cost_30d_kz: number
    variants: Array<{ variant: number; sent: number; converted: number }>
  }
}

export type BroadcastAudience = 'users' | 'drivers' | 'operators' | 'admins' | 'all'

export interface BroadcastPayload {
  audience: BroadcastAudience
  province_id?: number | null
  municipality_id?: number | null
  kind: 'announcement' | 'promo'
  title: string
  body: string
  path?: string | null
  send_sms: boolean
  sms_text?: string | null
  scheduled_at?: string | null
}

export interface BroadcastPreview {
  recipients: number
  sms_recipients: number
  sms: { text: string; encoding: string; length: number; segments: number } | null
  sms_cost_estimate_kz: number
  quiet_hours_now: boolean
}

export interface BroadcastItem {
  id: number
  audience: BroadcastAudience
  audience_label: string
  province: string | null
  municipality: string | null
  kind: 'announcement' | 'promo'
  title: string
  body: string
  send_sms: boolean
  status: 'SCHEDULED' | 'SENDING' | 'SENT' | 'CANCELLED' | 'FAILED'
  scheduled_at: string | null
  finished_at: string | null
  recipients_count: number
  sms_count: number
  created_by: string | null
  created_at: string
}

const BASE = '/admin/notifications-center'

/** Painel "Notificações" do Super Admin (Setembro 2026). */
export const notificationCenterService = {
  async campaigns(): Promise<Campaign[]> {
    const { data } = await api.get<ApiSuccess<Campaign[]>>(`${BASE}/campaigns`)
    return data.data
  },

  async updateCampaign(key: string, changes: Partial<Pick<Campaign, 'enabled' | 'sms_enabled' | 'templates'>>): Promise<void> {
    await api.patch(`${BASE}/campaigns/${key}`, changes)
  },

  async testCampaign(key: string): Promise<string> {
    const { data } = await api.post<{ message: string }>(`${BASE}/campaigns/${key}/test`)
    return data.message
  },

  async runCampaign(key: string): Promise<string> {
    const { data } = await api.post<{ message: string }>(`${BASE}/campaigns/${key}/run`)
    return data.message
  },

  async broadcasts(page = 1): Promise<{ data: BroadcastItem[]; meta: PaginatedMeta }> {
    const { data } = await api.get<{ data: BroadcastItem[]; meta: PaginatedMeta }>(`${BASE}/broadcasts`, { params: { page } })
    return data
  },

  async preview(payload: Partial<BroadcastPayload>): Promise<BroadcastPreview> {
    const { data } = await api.post<ApiSuccess<BroadcastPreview>>(`${BASE}/broadcasts/preview`, payload)
    return data.data
  },

  async send(payload: BroadcastPayload): Promise<string> {
    const { data } = await api.post<{ message: string }>(`${BASE}/broadcasts`, payload)
    return data.message
  },

  async cancel(id: number): Promise<void> {
    await api.post(`${BASE}/broadcasts/${id}/cancel`)
  },
}
