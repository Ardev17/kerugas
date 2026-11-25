import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, X, CheckCircle2, XCircle, Star, ChevronDown, ChevronUp } from 'lucide-react'
import { adminService, type DriverWithStock } from '@/services/adminService'
import { adminGroupsService } from '@/services/adminGroupsService'
import { GroupTabs } from '@/components/admin/GroupTabs'
import { LocationGroupsView } from '@/components/admin/LocationGroupsView'
import { territoryService } from '@/services/territoryService'
import { ExportControls } from '@/components/ExportControls'
import { getApiErrorMessage } from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { Avatar } from '@/components/Avatar'
import type { AuthUser } from '@/types'
import { hasSuperPowers, isProvinceSuperAdmin } from '@/lib/roles'

/**
 * Gestão de Condutores (pedido explícito do cliente, Setembro 2026).
 * LISTAGEM/FILTRO: Admin (só a sua província, com filtro opcional por
 * município) e Super Admin (todas, com filtro por província depois
 * município),o backend já aplica isto sozinho
 * (DriverAdminController::index).
 *
 * CRIAÇÃO: estritamente SUPER_ADMIN (decisão explícita do cliente,
 * mesmo sendo uma exceção à regra geral de que um Admin gere
 * utilizadores da sua própria província),o botão "Novo condutor" só
 * aparece para Super Admin; o backend recusa (403) de qualquer forma
 * se alguém tentar contornar isto.
 */
const TAB_LABEL: Record<string, string> = { all: 'Todos', available: 'Disponíveis agora' }

export default function AdminDrivers() {
  const { user: currentUser } = useAuth()
  // Super Admin geral ou provincial (Outubro 2026) cria condutores.
  const isSuperAdmin = hasSuperPowers(currentUser)
  // Organização (Outubro 2026): todos/disponíveis → província → município.
  const [tab, setTab] = useState('all')
  const [exportMunicipalityId, setExportMunicipalityId] = useState<number | undefined>(undefined)
  const [creating, setCreating] = useState(false)
  const [expandedDriverId, setExpandedDriverId] = useState<number | null>(null)
  const [exportDriverId, setExportDriverId] = useState<number | undefined>(undefined)
  const queryClient = useQueryClient()

  // Lista simples só para o seletor da exportação.
  const driversQuery = useQuery({
    queryKey: ['admin-drivers'],
    queryFn: () => adminService.listDrivers({}),
  })
  const groupsQuery = useQuery({
    queryKey: ['admin-drivers', 'groups', tab],
    queryFn: () => adminGroupsService.groups('drivers', { available_only: tab === 'available' ? 1 : undefined }),
  })

  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', currentUser?.province_id],
    queryFn: () => territoryService.listMunicipalities(currentUser!.province_id!),
    enabled: !!currentUser?.province_id,
  })

  const createMutation = useMutation({
    mutationFn: adminService.createUser,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin-drivers'] })
      setCreating(false)
    },
  })

  // Cartão de um condutor (dentro de cada grupo província → município).
  const renderDriver = (d: DriverWithStock) => (
    <div key={d.id} className="rounded-xl border border-gray/15 bg-white p-4">
      <button
        onClick={() => setExpandedDriverId(expandedDriverId === d.id ? null : d.id)}
        className="flex w-full items-center justify-between text-left"
      >
        <div className="flex items-center gap-3">
          <Avatar name={d.name} url={d.avatar_url} size="md" />
          <div>
            <p className="font-medium text-dark">{d.name}</p>
            <p className="text-sm text-gray">{d.phone} · {d.municipality}, {d.province}</p>
          </div>
        </div>
        <div className="flex items-center gap-2">
          {d.driver_available ? (
            <span className="flex items-center gap-1.5 rounded-full bg-success/10 px-2.5 py-1 text-xs font-semibold text-success">
              <CheckCircle2 size={14} /> Disponível
            </span>
          ) : (
            <span className="flex items-center gap-1.5 rounded-full bg-gray/10 px-2.5 py-1 text-xs font-semibold text-gray">
              <XCircle size={14} /> Indisponível
            </span>
          )}
          {expandedDriverId === d.id ? <ChevronUp size={18} className="text-gray" /> : <ChevronDown size={18} className="text-gray" />}
        </div>
      </button>

      {expandedDriverId === d.id && <DriverStatsPanel driver={d} />}
    </div>
  )

  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-bold text-dark">Condutores</h1>
        <div className="flex flex-wrap items-center gap-2">
          {isSuperAdmin && (
            <button
              onClick={() => setCreating(true)}
              className="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white"
            >
              <Plus size={16} /> Novo condutor
            </button>
          )}
        </div>
      </div>

      {!isSuperAdmin && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          Só o Super Admin pode criar ou editar contas de Condutor. Esta lista serve para escolher a quem enviar um pedido.
        </p>
      )}

      <div className="mt-6 space-y-4">
        <GroupTabs ariaLabel="Condutores" tabs={groupsQuery.data?.tabs ?? [{ key: 'all', count: 0 }, { key: 'available', count: 0 }]} labels={TAB_LABEL} value={tab} onChange={setTab} />
        <LocationGroupsView<DriverWithStock>
          resource="drivers"
          queryKey={['admin-drivers']}
          params={{ available_only: tab === 'available' ? 1 : undefined }}
          groups={groupsQuery.data?.groups}
          isLoading={groupsQuery.isLoading}
          withMunicipalities
          renderItem={renderDriver}
          emptyText="Nenhum condutor encontrado."
        />
      </div>

      {/* Exportação de entregas por condutor (Setembro 2026),para
          pagamento/comissão. `driver_id` opcional: sem ele, exporta
          todos os condutores no período (dentro do âmbito da conta —
          o backend força `province_id` para um Admin limitado). */}
      <div className="mt-8">
        <ExportControls
          title="Exportar entregas por condutor"
          description="Descarregue as entregas feitas por condutor, para pagamento/comissão, em Excel ou PDF."
          onExport={(period, date, format) =>
            adminService.exportDriverDeliveries({
              period,
              date,
              format,
              driver_id: exportDriverId,
              municipality_id: exportMunicipalityId,
            })
          }
          extraFilters={
            <div className="mt-4 border-t border-gray/10 pt-4">
              <label className="block text-sm">
                <span className="mb-1 block font-medium text-dark">Condutor (opcional)</span>
                <select
                  value={exportDriverId ?? ''}
                  onChange={(e) => setExportDriverId(e.target.value ? Number(e.target.value) : undefined)}
                  className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                >
                  <option value="">Todos os condutores</option>
                  {driversQuery.data?.data.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
                </select>
              </label>
              {!!municipalitiesQuery.data?.length && (
                <label className="mt-3 block text-sm">
                  <span className="mb-1 block font-medium text-dark">Município (opcional)</span>
                  <select
                    aria-label="Município da exportação"
                    value={exportMunicipalityId ?? ''}
                    onChange={(e) => setExportMunicipalityId(e.target.value ? Number(e.target.value) : undefined)}
                    className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                  >
                    <option value="">Todos os municípios</option>
                    {municipalitiesQuery.data.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
                  </select>
                </label>
              )}
            </div>
          }
        />
      </div>

      {creating && (
        <CreateDriverModal
          onClose={() => setCreating(false)}
          onSubmit={(payload) => createMutation.mutate(payload)}
          isPending={createMutation.isPending}
          error={createMutation.isError ? getApiErrorMessage(createMutation.error, 'Não foi possível criar o condutor.') : null}
          defaultProvinceId={currentUser?.province_id ?? undefined}
          lockProvince={isProvinceSuperAdmin(currentUser)}
        />
      )}
    </div>
  )
}

/**
 * Estatísticas/ranking simples de um condutor (Setembro 2026) —
 * carregadas só quando a linha é expandida (evita N chamadas extra ao
 * simplesmente listar condutores). Mesmo cálculo que o próprio
 * condutor vê de si próprio, ver `DriverStatsService`.
 */
function DriverStatsPanel({ driver }: { driver: AuthUser }) {
  const statsQuery = useQuery({
    queryKey: ['admin-driver-stats', driver.id],
    queryFn: () => adminService.getDriverStats(driver.id),
  })

  if (statsQuery.isLoading) {
    return <p className="mt-3 border-t border-gray/10 pt-3 text-sm text-gray">A carregar estatísticas...</p>
  }

  if (statsQuery.isError || !statsQuery.data) {
    return <p className="mt-3 border-t border-gray/10 pt-3 text-sm text-danger">Não foi possível carregar as estatísticas.</p>
  }

  const stats = statsQuery.data

  return (
    <div className="mt-3 grid grid-cols-2 gap-3 border-t border-gray/10 pt-3 sm:grid-cols-4">
      <div className="rounded-lg bg-light p-3 text-center">
        <p className="text-lg font-bold text-dark">{stats.total_delivered}</p>
        <p className="text-xs text-gray">Entregas feitas</p>
      </div>
      <div className="rounded-lg bg-light p-3 text-center">
        <p className="text-lg font-bold text-dark">{stats.cancellation_rate}%</p>
        <p className="text-xs text-gray">Taxa de cancelamento</p>
      </div>
      <div className="rounded-lg bg-light p-3 text-center">
        <p className="text-lg font-bold text-dark">{stats.average_delivery_minutes != null ? `${stats.average_delivery_minutes} min` : '—'}</p>
        <p className="text-xs text-gray">Tempo médio de entrega</p>
      </div>
      <div className="rounded-lg bg-light p-3 text-center">
        <p className="flex items-center justify-center gap-1 text-lg font-bold text-dark">
          {stats.average_rating != null ? (
            <>
              <Star size={14} className="fill-amber-400 text-amber-400" /> {stats.average_rating}
            </>
          ) : '—'}
        </p>
        <p className="text-xs text-gray">Avaliação média {stats.ratings_count > 0 ? `(${stats.ratings_count})` : ''}</p>
      </div>
    </div>
  )
}

function CreateDriverModal({
  onClose,
  onSubmit,
  isPending,
  error,
  defaultProvinceId, lockProvince,
}: {
  onClose: () => void
  onSubmit: (payload: {
    name: string
    phone: string
    password: string
    sms_pin: string
    role: string
    province_id: number
    municipality_id: number
  }) => void
  isPending: boolean
  error: string | null
  defaultProvinceId?: number
  lockProvince?: boolean
}) {
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [password, setPassword] = useState('')
  const [smsPin, setSmsPin] = useState('')
  const [provinceId, setProvinceId] = useState<number | undefined>(defaultProvinceId)
  const [municipalityId, setMunicipalityId] = useState<number | undefined>(undefined)

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(provinceId!),
    enabled: !!provinceId,
  })

  const isValid = name.trim() !== '' && phone.trim() !== '' && password.length >= 8 && /^\d{4,6}$/.test(smsPin) && smsPin !== password && !!provinceId && !!municipalityId

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">Novo condutor</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        <p className="mt-2 text-sm text-gray">
          O condutor só recebe pedidos do município escolhido abaixo,comunique-lhe a palavra-passe diretamente.
        </p>

        <div className="mt-3 space-y-2">
          <input placeholder="Nome" value={name} onChange={(e) => setName(e.target.value)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          <input placeholder="Telefone" value={phone} onChange={(e) => setPhone(e.target.value)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          <input placeholder="Palavra-passe inicial (mín. 8 caracteres)" type="text" value={password} onChange={(e) => setPassword(e.target.value)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          <div>
            <div className="flex gap-2">
              <input
                placeholder="PIN de SMS (4 a 6 números)"
                aria-label="PIN de SMS"
                inputMode="numeric"
                maxLength={6}
                autoComplete="off"
                value={smsPin}
                onChange={(e) => setSmsPin(e.target.value.replace(/\D/g, ''))}
                className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
              />
              <button type="button" onClick={() => setSmsPin(String(Math.floor(100000 + Math.random() * 900000)).slice(0, 4))} className="shrink-0 rounded-lg border border-gray/20 px-3 py-2 text-xs font-medium text-dark">Gerar</button>
            </div>
            <p className="mt-1 text-[11px] text-gray">
              Serve para o condutor registar carregamentos por SMS. Tem de ser <strong>diferente da palavra-passe</strong>. É provisório: o condutor muda-o na app e passa a ser só dele.
            </p>
            {smsPin !== '' && smsPin === password && <p role="alert" className="text-[11px] text-danger">O PIN não pode ser igual à palavra-passe.</p>}
          </div>
          <select
            value={provinceId ?? ''}
            onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : undefined); setMunicipalityId(undefined) }}
            disabled={lockProvince}
            aria-label="Província do condutor"
            className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
          >
            <option value="">Selecione a província</option>
            {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
          <select
            value={municipalityId ?? ''}
            onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : undefined)}
            disabled={!provinceId}
            className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm disabled:opacity-50"
          >
            <option value="">Selecione o município</option>
            {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
        </div>

        {error && <p className="mt-2 text-xs text-danger">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">
            Cancelar
          </button>
          <button
            onClick={() =>
              isValid &&
              onSubmit({ name, phone, password, sms_pin: smsPin, role: 'DRIVER', province_id: provinceId!, municipality_id: municipalityId! })
            }
            disabled={!isValid || isPending}
            className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {isPending ? 'A criar...' : 'Criar condutor'}
          </button>
        </div>
      </div>
    </div>
  )
}
