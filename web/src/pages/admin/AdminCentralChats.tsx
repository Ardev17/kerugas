import { useEffect, useState } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ArrowLeft, Headset, Loader2, Plus, Search } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { RoomChat } from '@/components/roomchat/RoomChat'
import { Sheet } from '@/components/vizinhos/Sheet'
import { getApiErrorMessage } from '@/lib/api'
import { timeAgo } from '@/lib/vizinhos'
import { adminService } from '@/services/adminService'
import { roomChatService } from '@/services/roomChatService'

const STAFF_ROLES = ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'ADMIN']
const ROLE_LABEL: Record<string, string> = { USER: 'Utilizador', DRIVER: 'Condutor', STATION_OPERATOR: 'Operador', MODERATOR: 'Moderador' }

/** Escolher a quem escrever (utilizadores do seu âmbito; o servidor confirma). */
function NewConversationSheet({ onPick, onClose }: { onPick: (id: number) => void; onClose: () => void }) {
  const [search, setSearch] = useState('')
  const [q, setQ] = useState('')
  useEffect(() => {
    const t = setTimeout(() => setQ(search.trim()), 350)
    return () => clearTimeout(t)
  }, [search])

  const users = useQuery({
    queryKey: ['central-new-users', q],
    queryFn: () => adminService.listUsers({ search: q }),
    enabled: q.length >= 2,
  })
  const list = (users.data?.data ?? []).filter((u) => !STAFF_ROLES.includes(u.role))

  return (
    <Sheet title="Nova conversa" onClose={onClose}>
      <div className="relative">
        <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray" aria-hidden="true" />
        <input autoFocus value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nome ou telefone do utilizador…" aria-label="Procurar utilizador" className="w-full rounded-full border border-gray/20 py-2 pl-9 pr-3 text-sm" />
      </div>
      {q.length < 2 && <p className="mt-3 text-xs text-gray">Escreva pelo menos 2 letras.</p>}
      {users.isLoading && q.length >= 2 && <Loader2 className="mx-auto mt-4 animate-spin text-gray" size={18} />}
      <ul className="mt-2 divide-y divide-gray/10">
        {list.map((u) => (
          <li key={u.id}>
            <button type="button" onClick={() => onPick(u.id)} className="flex w-full items-center gap-3 py-2.5 text-left">
              <Avatar name={u.name} url={u.avatar_url ?? null} size="sm" zoomable={false} />
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium text-dark">{u.name}</span>
                <span className="block truncate text-xs text-gray">{[ROLE_LABEL[u.role] ?? u.role, u.phone, u.municipality].filter(Boolean).join(' · ')}</span>
              </span>
            </button>
          </li>
        ))}
        {q.length >= 2 && users.data && list.length === 0 && <li className="py-6 text-center text-sm text-gray">Ninguém encontrado.</li>}
      </ul>
    </Sheet>
  )
}

/**
 * Conversa com a Central,lado dos admins (Outubro 2026). A direção escreve
 * diretamente a um utilizador para o avisar de algo; o utilizador vê o botão
 * "Conversa com a Central" e responde. Admin e Provincial: utilizadores da
 * sua província; Super Admin: todos.
 */
export default function AdminCentralChats() {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const selected = Number(params.get('utilizador')) || null
  const focusId = Number(params.get('mensagem')) || undefined
  const [newOpen, setNewOpen] = useState(false)

  const threads = useQuery({ queryKey: ['central-threads'], queryFn: roomChatService.centralThreads, refetchInterval: 60_000 })
  const open = (id: number | null) => setParams(id ? { utilizador: String(id) } : {})

  if (selected) {
    return (
      <div className="mx-auto max-w-2xl">
        <button type="button" onClick={() => { open(null); void queryClient.invalidateQueries({ queryKey: ['central-threads'] }) }} className="mb-3 inline-flex items-center gap-1 text-sm text-gray hover:text-dark">
          <ArrowLeft size={15} /> Todas as conversas
        </button>
        <RoomChat
          key={selected}
          type="central"
          roomId={selected}
          focusId={focusId}
          header={(o) => (
            <header className="flex items-center gap-3 rounded-2xl border border-gray/15 bg-white p-3">
              <Avatar name={o.room.person?.name ?? '?'} url={o.room.person?.avatar_url ?? null} size="md" />
              <div className="min-w-0">
                <h1 className="truncate text-base font-bold text-dark">{o.room.person?.name ?? 'Utilizador'}</h1>
                <p className="truncate text-xs text-gray">{[o.room.person?.type_label, o.room.person?.phone, o.room.person?.municipality, o.room.person?.province].filter(Boolean).join(' · ')}</p>
              </div>
            </header>
          )}
          emptyTitle="Ainda não escreveu a esta pessoa"
          emptyText="A primeira mensagem faz aparecer o botão “Conversa com a Central” na conta dela, com uma notificação."
          placeholder="Escreva o aviso para o utilizador…"
          onSent={() => void queryClient.invalidateQueries({ queryKey: ['central-threads'] })}
        />
      </div>
    )
  }

  const list = threads.data ?? []

  return (
    <div className="mx-auto max-w-2xl">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-bold text-dark"><Headset size={22} className="text-primary" /> Conversa com a Central</h1>
          <p className="mt-1 text-sm text-gray">Canal direto entre a direção e um utilizador, para avisar sobre algo. O utilizador só vê a conversa depois de receber uma mensagem.</p>
        </div>
        <button type="button" onClick={() => setNewOpen(true)} className="flex items-center gap-1.5 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white">
          <Plus size={15} aria-hidden="true" /> Nova conversa
        </button>
      </div>

      {threads.isLoading && <div className="flex justify-center py-12 text-gray"><Loader2 className="animate-spin" size={22} /></div>}
      {threads.isError && <p role="alert" className="mt-4 rounded-xl bg-danger/10 p-3 text-sm text-danger">{getApiErrorMessage(threads.error, 'Não foi possível carregar as conversas.')}</p>}
      {threads.data && list.length === 0 && (
        <p className="mt-4 rounded-2xl border border-dashed border-gray/25 p-8 text-center text-sm text-gray">Ainda não há conversas. Use “Nova conversa” para escrever a um utilizador.</p>
      )}

      <ul className="mt-4 divide-y divide-gray/10 overflow-hidden rounded-2xl border border-gray/15 bg-white">
        {list.map((t) => (
          <li key={t.user.id}>
            <button type="button" onClick={() => open(t.user.id)} className="flex w-full items-center gap-3 px-3 py-3 text-left hover:bg-light">
              <Avatar name={t.user.name} url={t.user.avatar_url} size="sm" zoomable={false} />
              <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                  <span className="truncate text-sm font-semibold text-dark">{t.user.name}</span>
                  <span className="shrink-0 text-[11px] text-gray">{t.user.type_label}{t.user.province ? ` · ${t.user.province}` : ''}</span>
                </span>
                <span className={`block truncate text-xs ${t.unread > 0 ? 'font-semibold text-dark' : 'text-gray'}`}>
                  {t.last_message ? `${t.last_message.from_user ? '' : `${t.last_message.author ?? 'Central'}: `}${t.last_message.excerpt}` : '—'}
                </span>
              </span>
              <span className="flex shrink-0 flex-col items-end gap-1">
                {t.last_message?.created_at && <span className="text-[11px] text-gray">{timeAgo(t.last_message.created_at)}</span>}
                {t.unread > 0 && <span className="rounded-full bg-primary px-1.5 text-[11px] font-bold text-white" aria-label={`${t.unread} por ler`}>{t.unread}</span>}
              </span>
            </button>
          </li>
        ))}
      </ul>

      {newOpen && <NewConversationSheet onClose={() => setNewOpen(false)} onPick={(id) => { setNewOpen(false); open(id) }} />}
    </div>
  )
}
