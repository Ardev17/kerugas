import { useEffect } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Bell, X } from 'lucide-react'
import { useNotificationPopup } from '@/stores/notificationPopupStore'
import { notificationService } from '@/services/notificationService'
import { useAuth } from '@/hooks/useAuth'

/**
 * Popup das notificações (Outubro 2026): quem toca numa notificação que não
 * leva a nenhum ecrã vê a mensagem COMPLETA aqui,no sino, ao tocar na
 * push do telemóvel ou do navegador (que abrem `/?notificacao=ID`).
 */
export function NotificationPopupHost() {
  const { current, show, close } = useNotificationPopup()
  const { isAuthenticated } = useAuth()
  const location = useLocation()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  useEffect(() => {
    const params = new URLSearchParams(location.search)
    const raw = params.get('notificacao')
    if (!raw || !isAuthenticated) return
    const id = Number(raw)

    params.delete('notificacao')
    const search = params.toString()
    navigate({ pathname: location.pathname, search: search ? `?${search}` : '' }, { replace: true })

    if (!Number.isInteger(id) || id <= 0) return
    notificationService.get(id)
      .then((n) => {
        show({ title: n.title, body: n.body, created_at: n.created_at })
        void queryClient.invalidateQueries({ queryKey: ['notifications'] })
      })
      .catch(() => {})
  }, [location.search, location.pathname, isAuthenticated, navigate, show, queryClient])

  useEffect(() => {
    if (!current) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') close() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [current, close])

  if (!current) return null

  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/40 p-4" onClick={close}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="notification-popup-title"
        className="max-h-[85vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between gap-3">
          <div className="flex items-start gap-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"><Bell size={18} /></span>
            <div>
              <h3 id="notification-popup-title" className="font-semibold text-dark">{current.title}</h3>
              {current.created_at && <p className="text-xs text-gray">{new Date(current.created_at).toLocaleString('pt-PT')}</p>}
            </div>
          </div>
          <button onClick={close} aria-label="Fechar"><X size={18} /></button>
        </div>
        <p className="mt-4 whitespace-pre-line text-sm text-dark">{current.body}</p>
        <div className="mt-5 flex justify-end">
          <button onClick={close} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white">OK</button>
        </div>
      </div>
    </div>
  )
}
