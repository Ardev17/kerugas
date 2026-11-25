import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Mail, MailOpen, ShieldAlert } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { useAuth } from '@/hooks/useAuth'

/**
 * Mensagens recebidas pelo formulário de Contacto do site (Setembro
 * 2026). Ficam aqui mesmo quando o email falhar de enviar (SMTP mal
 * configurado, por exemplo),ver ContactController::store().
 *
 * ACESSO: estritamente Super Admin (decisão explícita do cliente),ao
 * contrário da maioria das secções do painel, o Admin comum não vê esta
 * página, nem sequer o link na sidebar (ver AdminLayout.tsx). O backend
 * já recusa com 403 (permission:contact.view, que só o Super Admin tem
 * via RolePermissions::MAP['*']); esta verificação aqui é só para dar
 * uma mensagem clara em vez de um erro de API, caso alguém aceda pelo
 * URL diretamente,segue o mesmo padrão usado em AdminDrivers.tsx.
 */
export default function AdminContactMessages() {
  const { user } = useAuth()
  const [status, setStatus] = useState('')
  const queryClient = useQueryClient()
  const isSuperAdmin = user?.role === 'SUPER_ADMIN'

  const messagesQuery = useQuery({
    queryKey: ['admin-contact-messages', status],
    queryFn: () => adminService.listContactMessages({ status: status || undefined }),
    enabled: isSuperAdmin,
  })

  const markReadMutation = useMutation({
    mutationFn: (id: number) => adminService.markContactMessageRead(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-contact-messages'] }),
  })

  const unreadCount = messagesQuery.data?.meta.unread_count ?? 0

  if (!isSuperAdmin) {
    return (
      <div className="flex flex-col items-center gap-2 py-16 text-center text-gray">
        <ShieldAlert size={28} />
        <p className="text-sm">Esta secção está disponível apenas para o Super Admin.</p>
      </div>
    )
  }

  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-bold text-dark">
          Mensagens de contacto
          {unreadCount > 0 && (
            <span className="ml-2 rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-white">
              {unreadCount} por ler
            </span>
          )}
        </h1>
        <select value={status} onChange={(e) => setStatus(e.target.value)} className="rounded-lg border border-gray/20 px-3 py-2 text-sm">
          <option value="">Todas</option>
          <option value="NEW">Por ler</option>
          <option value="READ">Lidas</option>
        </select>
      </div>

      <div className="mt-6 space-y-3">
        {(messagesQuery.data?.data ?? []).map((m) => (
          <div
            key={m.id}
            className={`rounded-xl border p-4 ${m.status === 'NEW' ? 'border-primary/30 bg-primary/5' : 'border-gray/15 bg-white'}`}
          >
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <p className="font-medium text-dark">{m.name}</p>
                <p className="text-sm text-gray">{m.contact}</p>
              </div>
              <div className="flex shrink-0 items-center gap-2">
                <span className="text-xs text-gray">{new Date(m.created_at).toLocaleString('pt-PT')}</span>
                {m.status === 'NEW' && (
                  <button
                    onClick={() => markReadMutation.mutate(m.id)}
                    disabled={markReadMutation.isPending}
                    className="flex items-center gap-1 rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-semibold text-dark hover:bg-light disabled:opacity-50"
                  >
                    <MailOpen size={13} /> Marcar como lida
                  </button>
                )}
              </div>
            </div>
            <p className="mt-3 whitespace-pre-line text-sm text-dark">{m.message}</p>
          </div>
        ))}

        {messagesQuery.data?.data.length === 0 && (
          <div className="flex flex-col items-center gap-2 py-12 text-center text-gray">
            <Mail size={28} />
            <p className="text-sm">Nenhuma mensagem de contacto por aqui.</p>
          </div>
        )}
      </div>
    </div>
  )
}
