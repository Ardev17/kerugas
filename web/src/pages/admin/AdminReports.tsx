import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminService } from '@/services/adminService'
import { adminGroupsService } from '@/services/adminGroupsService'
import { GroupTabs } from '@/components/admin/GroupTabs'
import { LocationGroupsView } from '@/components/admin/LocationGroupsView'
import { useAuth } from '@/hooks/useAuth'
import { isProvinceScoped } from '@/lib/roles'

const REASON_LABEL: Record<string, string> = {
  GAS_UNAVAILABLE: 'Gás indisponível (indicado errado)',
  QUEUE_INCORRECT: 'Fila incorreta',
  STATION_CLOSED: 'Posto fechado',
  LOCATION_INCORRECT: 'Localização incorreta',
  MISLEADING_INFORMATION: 'Informação enganosa',
  OTHER: 'Outro',
}

type ReportItem = Record<string, unknown>
const STATUS_ORDER = ['OPEN', 'UNDER_REVIEW', 'RESOLVED', 'REJECTED']
const STATUS_LABEL: Record<string, string> = { OPEN: 'Abertas', UNDER_REVIEW: 'Em revisão', RESOLVED: 'Resolvidas', REJECTED: 'Rejeitadas' }

export default function AdminReports() {
  const [status, setStatus] = useState('OPEN')
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const isProvinceScopedAdmin = isProvinceScoped(user)

  // Organização (Outubro 2026): estado → província → município do posto.
  const groupsQuery = useQuery({
    queryKey: ['admin-reports', 'groups', status],
    queryFn: () => adminGroupsService.groups('reports', { status }),
  })

  const mutation = useMutation({
    mutationFn: ({ id, newStatus }: { id: number; newStatus: string }) => adminService.updateReportStatus(id, newStatus),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-reports'] }),
  })

  const renderReport = (r: ReportItem) => (
    <div key={String(r.id)} className="rounded-xl border border-gray/15 bg-white p-4">
      <div className="flex items-start justify-between">
        <div>
          <p className="font-medium text-dark">{String(r.station)}</p>
          <p className="text-sm text-gray">{REASON_LABEL[String(r.reason)] ?? String(r.reason)}</p>
          {!!r.description && <p className="mt-1 text-sm text-gray">{String(r.description)}</p>}
          <p className="mt-1 text-xs text-gray">Por {String(r.user)}</p>
        </div>
      </div>
      {status === 'OPEN' && (
        <div className="mt-3 flex gap-2">
          <button
            onClick={() => mutation.mutate({ id: Number(r.id), newStatus: 'UNDER_REVIEW' })}
            className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium"
          >
            Marcar em revisão
          </button>
          <button
            onClick={() => mutation.mutate({ id: Number(r.id), newStatus: 'RESOLVED' })}
            className="rounded-lg bg-success/10 px-3 py-1.5 text-sm font-medium text-success"
          >
            Resolver
          </button>
          <button
            onClick={() => mutation.mutate({ id: Number(r.id), newStatus: 'REJECTED' })}
            className="rounded-lg bg-danger/10 px-3 py-1.5 text-sm font-medium text-danger"
          >
            Rejeitar
          </button>
        </div>
      )}
    </div>
  )

  return (
    <div>
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold text-dark">Denúncias</h1>
      </div>
      <div className="mt-4">
        <GroupTabs ariaLabel="Estado das denúncias" tabs={STATUS_ORDER.map((k) => groupsQuery.data?.tabs.find((t) => t.key === k) ?? { key: k, count: 0 })} labels={STATUS_LABEL} value={status} onChange={setStatus} />
      </div>

      {isProvinceScopedAdmin && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          {user?.province
            ? <>A mostrar apenas denúncias de postos da província de <strong className="text-dark">{user.province}</strong></>
            : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin.'}
        </p>
      )}

      <div className="mt-6">
        <LocationGroupsView<ReportItem>
          resource="reports"
          queryKey={['admin-reports']}
          params={{ status: status }}
          groups={groupsQuery.data?.groups}
          isLoading={groupsQuery.isLoading}
          withMunicipalities
          renderItem={renderReport}
          emptyText="Nenhuma denúncia nesta categoria."
          emptyArt="clear"
        />
      </div>
    </div>
  )
}
