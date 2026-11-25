import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { SESSION_CLEARED_EVENT } from '@/lib/sessionCleanup'
import { useNotificationPopup } from '@/stores/notificationPopupStore'
import { useAppUpdateStore } from '@/stores/appUpdateStore'

/**
 * Ao terminar sessão (ou ao entrar outra conta), esvazia a cache de pedidos
 * à API e fecha o popup de notificação,a pessoa seguinte começa do zero.
 * Ver lib/sessionCleanup.ts.
 */
export function SessionCacheReset() {
  const queryClient = useQueryClient()

  useEffect(() => {
    const onCleared = () => {
      void queryClient.cancelQueries()
      queryClient.clear()
      useNotificationPopup.getState().close()
      useAppUpdateStore.setState({ dismissed: false })
    }
    window.addEventListener(SESSION_CLEARED_EVENT, onCleared)
    return () => window.removeEventListener(SESSION_CLEARED_EVENT, onCleared)
  }, [queryClient])

  return null
}
