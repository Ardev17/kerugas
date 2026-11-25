import { api } from '@/lib/api'

export interface ClientErrorItem {
  id: number
  source: 'web' | 'android' | 'ios'
  name: string | null
  message: string
  stack: string | null
  url_path: string | null
  app_version: string | null
  user_agent: string | null
  occurrences: number
  first_seen_at: string
  last_seen_at: string
  resolved_at: string | null
}

/** Erros do site/app (Outubro 2026),Super Admin geral. */
export const clientErrorService = {
  async list(params: { status: 'open' | 'resolved' | 'all'; source?: string; page?: number }) {
    const { data } = await api.get<{ data: ClientErrorItem[]; meta: { current_page: number; last_page: number; total: number } }>('/admin/client-errors', { params })
    return data
  },
  async toggleResolved(id: number): Promise<void> {
    await api.patch(`/admin/client-errors/${id}/resolve`)
  },
}
