import { api } from '@/lib/api'
import type { ApiSuccess } from '@/types'

/** Eliminação da conta (Outubro 2026). */
export const accountService = {
  async deletionStatus(): Promise<{ blockers: string[]; grace_days: number }> {
    const { data } = await api.get<ApiSuccess<{ blockers: string[]; grace_days: number }>>('/users/me/deletion')
    return data.data
  },
  async requestDeletion(payload: { current_password: string; confirmation: string; reason?: string }): Promise<{ scheduled_for: string; grace_days: number }> {
    const { data } = await api.post<ApiSuccess<{ scheduled_for: string; grace_days: number }>>('/users/me/deletion', payload)
    return data.data
  },
}
