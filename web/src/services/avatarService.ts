import { api } from '@/lib/api'
import type { ApiSuccess, AuthUser } from '@/types'

/** Foto de perfil (Outubro 2026). */
export const avatarService = {
  /** Envia a foto já recortada (o servidor valida e re-codifica). */
  async upload(blob: Blob): Promise<AuthUser> {
    const form = new FormData()
    form.append('avatar', blob, blob.type === 'image/webp' ? 'avatar.webp' : blob.type === 'image/png' ? 'avatar.png' : 'avatar.jpg')
    const { data } = await api.post<ApiSuccess<AuthUser>>('/users/me/avatar', form, { timeout: 120_000 })
    return data.data
  },
  async remove(): Promise<AuthUser> {
    const { data } = await api.delete<ApiSuccess<AuthUser>>('/users/me/avatar')
    return data.data
  },
  /** Moderação: Admin, Moderador, Super Admin Provincial e Super Admin geral. */
  async removeForUser(userId: number): Promise<void> {
    await api.delete(`/admin/users/${userId}/avatar`)
  },
}
