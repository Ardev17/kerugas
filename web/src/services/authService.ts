import { api } from '@/lib/api'
import type { ApiSuccess, AuthResponse, AuthUser, LoginPayload, RegisterPayload } from '@/types'

export const authService = {
  async register(payload: RegisterPayload): Promise<AuthResponse> {
    const { data } = await api.post<ApiSuccess<AuthResponse>>('/auth/register', payload)
    return data.data
  },

  async login(payload: LoginPayload): Promise<AuthResponse> {
    const { data } = await api.post<ApiSuccess<AuthResponse>>('/auth/login', payload)
    return data.data
  },

  async logout(): Promise<void> {
    await api.post('/auth/logout')
  },

  async me(): Promise<AuthUser> {
    const { data } = await api.get<ApiSuccess<AuthUser>>('/users/me')
    return data.data
  },

  /** `website`: honeypot anti-bot (ver HoneypotField),vazio para pessoas. */
  async forgotPassword(email: string, website?: string): Promise<void> {
    await api.post('/auth/forgot-password', website ? { email, website } : { email })
  },

  /** Link do email (`token`, site) ou código de 6 dígitos (`code`, app),Outubro 2026. */
  async resetPassword(payload: { token?: string; code?: string; email: string; password: string; password_confirmation: string }): Promise<void> {
    await api.post('/auth/reset-password', payload)
  },
}
