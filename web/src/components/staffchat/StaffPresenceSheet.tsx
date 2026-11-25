import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { RoleIcon } from '@/components/illustrations/StaffChat'
import { Sheet } from '@/components/vizinhos/Sheet'
import { timeAgo } from '@/lib/vizinhos'
import { staffChatService } from '@/services/staffChatService'

/** Quem da equipa está online e há quanto tempo estiveram os outros. */
export function StaffPresenceSheet({ onClose }: { onClose: () => void }) {
  const query = useQuery({ queryKey: ['sc-presence'], queryFn: staffChatService.presence, refetchInterval: 30_000 })
  const data = query.data

  return (
    <Sheet title="Equipa ativa" onClose={onClose}>
      {query.isLoading && <div className="flex justify-center py-6 text-gray"><Loader2 className="animate-spin" size={20} /></div>}
      {data && (
        <>
          <p className="text-sm text-gray">
            <strong className="text-dark">{data.online}</strong> online agora · <strong className="text-dark">{data.active_today}</strong> ativos nas últimas 24 h
          </p>
          <ul className="mt-3 divide-y divide-gray/10">
            {data.people.map((p) => (
              <li key={p.id} className="flex items-center gap-3 py-2.5">
                <span className="relative">
                  <Avatar name={p.name} url={p.avatar_url} size="sm" zoomable={false} />
                  <span aria-hidden="true" className={`absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full border-2 border-white ${p.online ? 'bg-success' : 'bg-gray/40'}`} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium text-dark">
                    {p.name}
                    <span className="ml-1.5 inline-flex items-center gap-1 align-middle text-[10px] font-semibold text-primary"><RoleIcon role={p.role} size={12} />{p.type_label}</span>
                  </span>
                  <span className="block truncate text-xs text-gray">{[p.province, p.municipality].filter(Boolean).join(' · ') || 'Todo o país'}</span>
                </span>
                <span className={`shrink-0 text-xs ${p.online ? 'font-semibold text-success' : 'text-gray'}`}>
                  {p.online ? 'Online agora' : `Visto ${timeAgo(p.last_seen_at)}`}
                </span>
              </li>
            ))}
            {data.people.length === 0 && <li className="py-6 text-center text-sm text-gray">Ainda ninguém por aqui.</li>}
          </ul>
        </>
      )}
    </Sheet>
  )
}
