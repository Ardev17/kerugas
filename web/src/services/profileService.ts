import { api } from '@/lib/api'
import type { ApiSuccess, AuthUser } from '@/types'

/** "Meu Perfil" (Outubro 2026),sempre a conta da própria sessão. */
export interface ProfilePayload {
  name?: string
  email?: string | null
  phone?: string
  province_id?: number | null
  municipality_id?: number | null
  neighborhood_id?: number | null
  latitude?: number | null
  longitude?: number | null
  /** Obrigatória só para mudar telemóvel ou email. */
  current_password?: string
}

export interface ProfileSession {
  id: number
  name: string
  current: boolean
  created_at: string | null
  last_used_at: string | null
}

export const profileService = {
  async update(payload: ProfilePayload): Promise<AuthUser> {
    const { data } = await api.put<ApiSuccess<AuthUser>>('/users/me', payload)
    return data.data
  },
  async changePassword(payload: { current_password: string; password: string; password_confirmation: string }): Promise<{ other_sessions_ended: number }> {
    const { data } = await api.put<ApiSuccess<{ other_sessions_ended: number }>>('/users/me/password', payload)
    return data.data
  },
  async sessions(): Promise<ProfileSession[]> {
    const { data } = await api.get<ApiSuccess<ProfileSession[]>>('/users/me/sessions')
    return data.data
  },
  async endOtherSessions(): Promise<{ other_sessions_ended: number }> {
    const { data } = await api.delete<ApiSuccess<{ other_sessions_ended: number }>>('/users/me/sessions/others')
    return data.data
  },
}

/** Erros por campo de uma resposta 422 do Laravel. */
export function fieldErrors(error: unknown): Record<string, string> {
  const errors = (error as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors
  if (!errors) return {}
  return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, v[0]]))
}
