import { api } from '@/lib/api'
import { filenameFromContentDisposition, triggerBlobDownload } from '@/lib/download'
import type { ApiSuccess } from '@/types'

/** Investidores por município (Outubro 2026). */
export interface InvestorMunicipality {
  id: number
  name: string | null
  province: string | null
  percentage: number
  since: string
}

export interface InvestorOverview {
  municipalities: InvestorMunicipality[]
  selected_municipality_id: number | null
  range: { from: string; to: string }
  summary: { orders: number; bottles: number; collected_total: number; commission_total: number; share_total: number; pending_orders: number }
  by_municipality: Array<{ municipality_id: number; name: string | null; orders: number; collected_total: number; commission_total: number; share_total: number }>
  daily: Array<{ date: string; orders: number; collected: number; commission: number; share: number }>
  periods: Array<{ municipality_id: number; percentage: number; starts_at: string; ends_at: string | null }>
  rule: string
}

export interface InvestorFilters {
  municipality_id?: number
  from?: string
  to?: string
}

export interface AdminInvestor {
  id: number
  name: string
  phone: string
  email: string | null
  avatar_url: string | null
  status: string
  /** Desativado (contrato desfeito): sem acesso à faturação; municípios libertados. */
  active: boolean
  deactivated_at: string | null
  paused_municipalities: Array<{ id: number; name: string | null; percentage: number }>
  municipalities: InvestorMunicipality[]
  chat_unread: number
}

export type InvestorExportFormat = 'csv' | 'pdf'

async function download(url: string, params: InvestorFilters & { format: InvestorExportFormat }): Promise<void> {
  const response = await api.get<Blob>(url, { params, responseType: 'blob', timeout: 120_000 })
  const filename = filenameFromContentDisposition(response.headers['content-disposition'], `kerugas-investidor.${params.format}`)
  await triggerBlobDownload(response.data, filename)
}

export interface AdminInvestorsData {
  investors: AdminInvestor[]
  taken: Array<{ municipality_id: number; investor_id: number; investor_name: string | null }>
}

export const investorService = {
  /** O próprio investidor: só os seus municípios. */
  async overview(filters: InvestorFilters = {}): Promise<InvestorOverview> {
    const { data } = await api.get<ApiSuccess<InvestorOverview>>('/investor/overview', { params: filters })
    return data.data
  },

  /** Exportar a sua faturação (Excel/PDF),os mesmos números do painel. */
  async exportMine(format: InvestorExportFormat, filters: InvestorFilters = {}): Promise<void> {
    await download('/investor/export', { ...filters, format })
  },

  // ── Só o Super Admin geral ─────────────────────────────────────────────
  async exportOf(investorId: number, format: InvestorExportFormat, filters: InvestorFilters = {}): Promise<void> {
    await download(`/admin/investors/${investorId}/export`, { ...filters, format })
  },
  async deactivate(investorId: number, reason?: string): Promise<{ freed_municipalities: string[] }> {
    const { data } = await api.post<ApiSuccess<{ freed_municipalities: string[] }>>(`/admin/investors/${investorId}/deactivate`, reason ? { reason } : {})
    return data.data
  },
  async reactivate(investorId: number): Promise<{ restored: string[]; skipped: string[] }> {
    const { data } = await api.post<ApiSuccess<{ restored: string[]; skipped: string[] }>>(`/admin/investors/${investorId}/reactivate`)
    return data.data
  },
  async list(): Promise<AdminInvestorsData> {
    const { data } = await api.get<ApiSuccess<Partial<AdminInvestorsData>>>('/admin/investors')
    // Defensivo: uma API mais antiga (sem ativar/desativar) não envia `active`
    // nem `paused_municipalities`,a página não pode rebentar por isso.
    const raw = data.data ?? {}
    return {
      investors: (Array.isArray(raw.investors) ? raw.investors : []).map((i) => ({
        ...i,
        active: i.active ?? !i.deactivated_at,
        deactivated_at: i.deactivated_at ?? null,
        paused_municipalities: Array.isArray(i.paused_municipalities) ? i.paused_municipalities : [],
        municipalities: Array.isArray(i.municipalities) ? i.municipalities : [],
        chat_unread: i.chat_unread ?? 0,
      })),
      taken: Array.isArray(raw.taken) ? raw.taken : [],
    }
  },
  async assign(payload: { user_id: number; municipality_id: number; percentage: number; starts_on?: string }): Promise<void> {
    await api.post('/admin/investors', payload)
  },
  async changePercentage(investorId: number, municipalityId: number, percentage: number): Promise<void> {
    await api.put(`/admin/investors/${investorId}/municipalities/${municipalityId}`, { percentage })
  },
  async remove(investorId: number, municipalityId: number): Promise<void> {
    await api.delete(`/admin/investors/${investorId}/municipalities/${municipalityId}`)
  },
  /** O mesmo que o investidor vê (pré-visualização do Super Admin). */
  async overviewOf(investorId: number, filters: InvestorFilters = {}): Promise<InvestorOverview> {
    const { data } = await api.get<ApiSuccess<InvestorOverview>>(`/admin/investors/${investorId}/overview`, { params: filters })
    return data.data
  },
}
