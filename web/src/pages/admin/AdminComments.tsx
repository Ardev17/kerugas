import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, EyeOff, MapPin } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { adminGroupsService } from '@/services/adminGroupsService'
import { GroupTabs } from '@/components/admin/GroupTabs'
import { LocationGroupsView } from '@/components/admin/LocationGroupsView'
import type { StationComment } from '@/types'
import { useAuth } from '@/hooks/useAuth'
import { canRemoveAvatarOf, isProvinceScoped } from '@/lib/roles'
import { Avatar } from '@/components/Avatar'
import { RemoveAvatarButton } from '@/components/RemoveAvatarButton'

type CommentItem = StationComment & { station_id?: number }
const STATUS_ORDER = ['PENDING', 'VISIBLE', 'HIDDEN']
const STATUS_LABEL: Record<string, string> = { PENDING: 'Pendentes', VISIBLE: 'Visíveis', HIDDEN: 'Ocultos' }

export default function AdminComments() {
  const [status, setStatus] = useState('PENDING')
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const isProvinceScopedAdmin = isProvinceScoped(user)

  // Organização (Outubro 2026): estado → província → município do posto.
  const groupsQuery = useQuery({
    queryKey: ['admin-comments', 'groups', status],
    queryFn: () => adminGroupsService.groups('comments', { status }),
  })

  const mutation = useMutation({
    mutationFn: ({ id, newStatus }: { id: number; newStatus: string }) => adminService.updateCommentStatus(id, newStatus),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-comments'] }),
  })

  const renderComment = (c: CommentItem) => (
    <div key={c.id} className="rounded-xl border border-gray/15 bg-white p-4">
      {/* Outubro 2026: o posto a que o comentário foi feito, visível antes de aprovar. */}
      {c.station && (
        <p className="mb-2 inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-semibold text-primary">
          <MapPin size={12} aria-hidden="true" />
          {c.station.name}
          {c.station.municipality ? ` · ${c.station.municipality}` : ''}
          {c.station.province ? `, ${c.station.province}` : ''}
        </p>
      )}
      <div className="flex flex-wrap items-center gap-2">
        <Avatar name={c.user_name} url={c.user_avatar_url} size="sm" />
        <p className="text-sm font-medium text-dark">{c.user_name}</p>
        {c.user_avatar_url && c.user_id && c.user_id !== user?.id && canRemoveAvatarOf(user, {}) && (
          <RemoveAvatarButton userId={c.user_id} userName={c.user_name} onRemoved={() => void queryClient.invalidateQueries({ queryKey: ['admin-comments'] })} />
        )}
      </div>
      <p className="mt-1 text-sm text-gray">{c.content}</p>
      {status === 'PENDING' && (
        <div className="mt-3 flex gap-2">
          <button
            onClick={() => mutation.mutate({ id: c.id, newStatus: 'VISIBLE' })}
            className="flex items-center gap-1.5 rounded-lg bg-success/10 px-3 py-1.5 text-sm font-medium text-success"
          >
            <Check size={14} /> Aprovar
          </button>
          <button
            onClick={() => mutation.mutate({ id: c.id, newStatus: 'HIDDEN' })}
            className="flex items-center gap-1.5 rounded-lg bg-danger/10 px-3 py-1.5 text-sm font-medium text-danger"
          >
            <EyeOff size={14} /> Ocultar
          </button>
        </div>
      )}
    </div>
  )

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-dark">Moderação de comentários</h1>
      </div>
      <div className="mt-4">
        <GroupTabs ariaLabel="Estado dos comentários" tabs={STATUS_ORDER.map((k) => groupsQuery.data?.tabs.find((t) => t.key === k) ?? { key: k, count: 0 })} labels={STATUS_LABEL} value={status} onChange={setStatus} />
      </div>

      {isProvinceScopedAdmin && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          {user?.province
            ? <>A mostrar apenas comentários de postos da província de <strong className="text-dark">{user.province}</strong></>
            : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin.'}
        </p>
      )}

      <div className="mt-6">
        <LocationGroupsView<CommentItem>
          resource="comments"
          queryKey={['admin-comments']}
          params={{ status: status }}
          groups={groupsQuery.data?.groups}
          isLoading={groupsQuery.isLoading}
          withMunicipalities
          renderItem={renderComment}
          emptyText="Nenhum comentário nesta categoria."
        />
      </div>
    </div>
  )
}
