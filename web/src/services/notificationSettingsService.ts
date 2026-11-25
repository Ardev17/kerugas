import { api } from '@/lib/api'
import type { ApiSuccess } from '@/types'

export interface NotificationPreferences {
  reminders: boolean
  alerts: boolean
  tips: boolean
  promos: boolean
  sms: boolean
  /** Vizinhos KeruGás: menções e respostas. */
  community: boolean
}

export const notificationSettingsService = {
  async get(): Promise<NotificationPreferences> {
    const { data } = await api.get<ApiSuccess<NotificationPreferences>>('/users/me/notification-preferences')
    return data.data
  },

  async update(changes: Partial<NotificationPreferences>): Promise<NotificationPreferences> {
    const { data } = await api.put<ApiSuccess<NotificationPreferences>>('/users/me/notification-preferences', changes)
    return data.data
  },
}
