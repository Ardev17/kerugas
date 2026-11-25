import { useQuery } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { BADGE_LABEL, timeAgo } from '@/lib/vizinhos'
import { vizinhosService } from '@/services/vizinhosService'
import { Sheet } from './Sheet'

/** Quem está online e há quanto tempo estiveram os outros (só deste município). */
export function PresenceSheet({ municipalityId, onClose }: { municipalityId: number; onClose: () => void }) {
  const query = useQuery({ queryKey: ['vz-presence', municipalityId], queryFn: () => vizinhosService.presence(municipalityId), refetchInterval: 30_000 })
  const data = query.data

  return (
    <Sheet title="Vizinhos ativos" onClose={onClose}>
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
                    {p.badge && <span className="ml-1.5 text-[10px] font-semibold text-primary">{BADGE_LABEL[p.badge]}</span>}
                  </span>
                  <span className="block truncate text-xs text-gray">{p.neighborhood ?? 'Sem bairro'}</span>
                </span>
                <span className={`shrink-0 text-xs ${p.online ? 'font-semibold text-success' : 'text-gray'}`}>
                  {p.online ? 'Online agora' : `Visto ${timeAgo(p.last_seen_at)}`}
                </span>
              </li>
            ))}
            {data.people.length === 0 && <li className="py-6 text-center text-sm text-gray">Ainda ninguém por aqui,seja o primeiro a dizer olá!</li>}
          </ul>
        </>
      )}
    </Sheet>
  )
}
