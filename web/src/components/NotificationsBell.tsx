import { useState } from 'react'
import { Bell, X } from 'lucide-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { safeInternalPath } from '@/native/deepLinks'
import { notificationService } from '@/services/notificationService'
import { useNotificationPopup } from '@/stores/notificationPopupStore'
import type { AppNotificationItem } from '@/types'

/**
 * Sino de notificações in-app (secção 23). Faz polling leve em vez de
 * WebSockets,a arquitetura de tempo real (Laravel Reverb) fica
 * preparada para a Etapa 15, mas o polling já dá uma experiência
 * funcional sem essa dependência extra.
 */
export function NotificationsBell() {
  const [open, setOpen] = useState(false)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['notifications'],
    queryFn: notificationService.list,
    refetchInterval: 30_000,
  })

  const unreadCount = query.data?.meta.unread_count ?? 0

  const handleMarkAllRead = async () => {
    await notificationService.markAllRead()
    queryClient.invalidateQueries({ queryKey: ['notifications'] })
  }

  const navigate = useNavigate()
  const showPopup = useNotificationPopup((s) => s.show)

  // Setembro 2026: as notificações das campanhas trazem o ecrã a abrir
  // (`data.path`, ex: "/pedir-gas?repetir=1"),tocar leva lá diretamente.
  // Outubro 2026: sem ecrã próprio, abre um popup com a mensagem completa.
  const handleItemClick = async (n: AppNotificationItem) => {
    const path = typeof n.data?.path === 'string' ? safeInternalPath(n.data.path) : null
    setOpen(false)
    if (path) {
      navigate(path)
    } else {
      showPopup({ title: n.title, body: n.body, created_at: n.created_at })
    }
    if (!n.read) {
      await notificationService.markRead(n.id).catch(() => {})
      void queryClient.invalidateQueries({ queryKey: ['notifications'] })
    }
  }

  return (
    <div className="relative">
      <button
        onClick={() => setOpen((v) => !v)}
        aria-label={`Notificações${unreadCount > 0 ? ` (${unreadCount} não lidas)` : ''}`}
        className="relative flex h-9 w-9 items-center justify-center rounded-lg hover:bg-light"
      >
        <Bell size={18} />
        {unreadCount > 0 && (
          <span className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-danger px-1 text-[10px] font-bold text-white">
            {unreadCount > 9 ? '9+' : unreadCount}
          </span>
        )}
      </button>

      {open && (
        <div className="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-gray/15 bg-white shadow-lg">
          <div className="flex items-center justify-between border-b border-gray/10 px-4 py-3">
            <p className="text-sm font-semibold text-dark">Notificações</p>
            <div className="flex items-center gap-2">
              {unreadCount > 0 && (
                <button onClick={handleMarkAllRead} className="text-xs font-medium text-primary">
                  Marcar todas como lidas
                </button>
              )}
              <button onClick={() => setOpen(false)} aria-label="Fechar"><X size={16} /></button>
            </div>
          </div>

          <div className="max-h-80 overflow-y-auto">
            {(query.data?.data.length ?? 0) === 0 && (
              <p className="px-4 py-6 text-center text-sm text-gray">Sem notificações por agora.</p>
            )}
            {query.data?.data.map((n) => (
              <button
                key={n.id}
                onClick={() => void handleItemClick(n)}
                className={`block w-full border-b border-gray/5 px-4 py-3 text-left text-sm last:border-0 hover:bg-light ${!n.read ? 'bg-primary/5' : ''}`}
              >
                <p className="font-medium text-dark">{n.title}</p>
                <p className="mt-0.5 line-clamp-2 text-gray">{n.body}</p>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
