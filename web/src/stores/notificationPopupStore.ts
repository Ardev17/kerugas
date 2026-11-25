import { create } from 'zustand'

export interface PopupNotification {
  title: string
  body: string
  created_at?: string | null
}

interface NotificationPopupState {
  current: PopupNotification | null
  show: (n: PopupNotification) => void
  close: () => void
}

/** Popup com a mensagem completa de uma notificação sem ecrã próprio (Outubro 2026). */
export const useNotificationPopup = create<NotificationPopupState>((set) => ({
  current: null,
  show: (n) => set({ current: n }),
  close: () => set({ current: null }),
}))
