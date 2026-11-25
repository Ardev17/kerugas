import { api } from '@/lib/api'
import type { AppNotificationItem } from '@/types'

interface NotificationsResponse {
  success: true
  data: AppNotificationItem[]
  meta: { unread_count: number; current_page: number; last_page: number }
}

export const notificationService = {
  async list(): Promise<NotificationsResponse> {
    const { data } = await api.get<NotificationsResponse>('/notifications')
    return data
  },

  /** Uma notificação completa (popup). Abrir conta como lida. */
  async get(id: number): Promise<AppNotificationItem> {
    const { data } = await api.get<{ success: true; data: AppNotificationItem }>(`/notifications/${id}`)
    return data.data
  },

  async markRead(id: number): Promise<void> {
    await api.patch(`/notifications/${id}/read`)
  },

  async markAllRead(): Promise<void> {
    await api.post('/notifications/read-all')
  },
}
