import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { getEcho } from '@/lib/echo'

/**
 * Subscreve o canal PRIVADO 'admin.orders' (autorização em
 * routes/channels.php,só ADMIN/SUPER_ADMIN). Usado uma única vez no
 * AdminLayout em vez de repetido em cada página admin, para não abrir
 * múltiplas subscrições ao mesmo canal ao navegar entre secções.
 */
export function useAdminRealtime() {
  const queryClient = useQueryClient()

  useEffect(() => {
    const echo = getEcho()
    if (!echo) {
      return
    }

    const channel = echo.private('admin.orders')

    channel.listen('.gas_order.created', () => {
      queryClient.invalidateQueries({ queryKey: ['admin-orders'] })
      queryClient.invalidateQueries({ queryKey: ['admin-dashboard'] })
    })

    return () => {
      echo.leaveChannel('private-admin.orders')
    }
  }, [queryClient])
}
