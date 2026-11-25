import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Pencil, Trash2, X, Fuel, LocateFixed, Loader2, MessageSquareWarning, Truck, PackageX } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { adminGroupsService } from '@/services/adminGroupsService'
import { GroupTabs } from '@/components/admin/GroupTabs'
import { LocationGroupsView } from '@/components/admin/LocationGroupsView'
import { territoryService } from '@/services/territoryService'
import { getApiErrorMessage } from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { useGeolocation } from '@/hooks/useGeolocation'
import type { AdminStation, StationOrdersOverride } from '@/types'
import { hasSuperPowers, isGlobalSuperAdmin } from '@/lib/roles'

const STATUS_LABEL: Record<string, string> = {
  ACTIVE: 'Ativo', INACTIVE: 'Inativo', PENDING: 'Pendente', SUSPENDED: 'Suspenso',
}

const GAS_TABS = ['available', 'unavailable', 'unknown']
const GAS_LABEL: Record<string, string> = { available: 'Com gás', unavailable: 'Sem gás', unknown: 'Sem informação' }

/**
 * Interruptor dos pedidos do posto (Novembro 2026). O estado por omissão
 * devolve a decisão à subscrição paga,por isso só se mostra distintivo
 * quando o administrador mexeu mesmo.
 */
const ORDERS_LABEL: Record<StationOrdersOverride, string> = {
  INHERIT: 'Segue a subscrição',
  ALLOW: 'Recebe sempre',
  BLOCK: 'Nunca recebe',
}

const ORDERS_HELP: Record<StationOrdersOverride, string> = {
  INHERIT: 'Como sempre funcionou: o posto recebe pedidos enquanto a subscrição estiver paga.',
  ALLOW: 'O posto recebe pedidos do município mesmo sem subscrição paga. Para cortesias, parceiros ou o arranque de um mercado.',
  BLOCK: 'O posto não recebe pedidos, mesmo que a subscrição esteja paga e em dia. Travão imediato.',
}

const LISTING_LABEL: Record<StationOrdersOverride, string> = {
  INHERIT: 'Segue a subscrição',
  ALLOW: 'Aparece sempre',
  BLOCK: 'Não aparece',
}

function ordersTone(o: StationOrdersOverride): string {
  return o === 'BLOCK' ? 'text-danger' : o === 'ALLOW' ? 'text-secondary' : 'text-gray'
}

export default function AdminStations() {
  const [search, setSearch] = useState('')
  const [editing, setEditing] = useState<AdminStation | 'new' | null>(null)
  const [updatingStatus, setUpdatingStatus] = useState<AdminStation | null>(null)
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const isSuperAdmin = hasSuperPowers(user)
  const isGlobal = isGlobalSuperAdmin(user)

  const stationsQuery = useQuery({
    queryKey: ['admin-stations', search],
    queryFn: () => adminService.listStations({ search: search || undefined }),
    enabled: search.trim() !== '',
  })

  // Organização por estado do gás → província → município (Outubro 2026).
  const [gasTab, setGasTab] = useState<string>('available')
  const groupsQuery = useQuery({
    queryKey: ['admin-stations', 'groups', gasTab],
    queryFn: () => adminGroupsService.groups('stations', { gas: gasTab }),
  })

  const deleteMutation = useMutation({
    mutationFn: (id: number) => adminService.deleteStation(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-stations'] }),
  })

  const [confirmDelete, setConfirmDelete] = useState<AdminStation | null>(null)
  const [ordersFor, setOrdersFor] = useState<AdminStation | null>(null)
  const [notice, setNotice] = useState<{ tone: 'ok' | 'error'; text: string } | null>(null)


  // Aviso MANUAL por SMS ao operador (Super Admin decide se envia).
  const noticeSmsMutation = useMutation({
    mutationFn: (s: AdminStation) => adminService.sendStationSubscriptionNotice(s.id).then(() => s),
    onSuccess: (s) => setNotice({ tone: 'ok', text: `SMS enviado ao operador de ${s.name}.` }),
    onError: (err) => setNotice({ tone: 'error', text: getApiErrorMessage(err, 'Não foi possível enviar o SMS ao operador.') }),
  })

  const confirmNoticeSms = (s: AdminStation) => {
    if (window.confirm(`Enviar SMS a ${s.operator_name ?? 'o operador'} a pedir que atualize a subscrição do posto ${s.name}?`)) {
      noticeSmsMutation.mutate(s)
    }
  }

  // Cartão de um posto (telemóvel e grupos por província/município).
  const renderCard = (s: AdminStation) => (
    <div key={s.id} className="rounded-xl border border-gray/15 bg-white p-4">
      <div className="flex items-start justify-between">
        <div>
          <p className="font-medium text-dark">{s.name}</p>
          <p className="text-sm text-gray">{s.municipality}{s.code ? ` · Nº ${s.code}` : ''}</p>
        </div>
        <div className="flex gap-2">
          <button onClick={() => setUpdatingStatus(s)} aria-label={`Atualizar estado de ${s.name}`} className="text-primary"><Fuel size={16} /></button>
          {isSuperAdmin && (
            <button
              onClick={() => setOrdersFor(s)}
              aria-label={`Pedidos de ${s.name}: ${ORDERS_LABEL[s.orders_override]}`}
              title={`Pedidos do município,${ORDERS_LABEL[s.orders_override]}`}
              className={ordersTone(s.orders_override)}
            >
              {s.orders_override === 'BLOCK' ? <PackageX size={16} /> : <Truck size={16} />}
            </button>
          )}
          {isSuperAdmin && !s.is_listed && s.operator_id && (
            <button
              onClick={() => confirmNoticeSms(s)}
              disabled={noticeSmsMutation.isPending}
              aria-label={`Enviar SMS de subscrição ao operador de ${s.name}`}
              className="text-warning disabled:opacity-50"
            >
              <MessageSquareWarning size={16} />
            </button>
          )}
          {isSuperAdmin && (
            <>
              <button onClick={() => setEditing(s)} aria-label="Editar"><Pencil size={16} /></button>
              <button onClick={() => setConfirmDelete(s)} aria-label="Remover" className="text-danger"><Trash2 size={16} /></button>
            </>
          )}
        </div>
      </div>
      <div className="mt-2 flex flex-wrap gap-4 text-xs text-gray">
        <span>{STATUS_LABEL[s.status]}</span>
        <span>{s.gas_available ? 'Disponível' : 'Indisponível'}</span>
        <span>{s.confidence_score}% confiança</span>
        {s.cylinder_types && <span>Garrafas: {s.cylinder_types}</span>}
        <span>{s.operator_name ? `${(s.operators?.length ?? 1) > 1 ? 'Operadores' : 'Operador'}: ${s.operator_name}` : <span className="italic text-danger">Sem operador</span>}</span>
        {s.orders_override !== 'INHERIT' && (
          <span className={`font-medium ${ordersTone(s.orders_override)}`}>
            Pedidos: {ORDERS_LABEL[s.orders_override]}
          </span>
        )}
        {s.listing_grace_until && (
          <span className="font-medium text-warning">
            Presença expirada,sai do mapa a {new Date(s.listing_grace_until).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit' })}
          </span>
        )}
      </div>
    </div>
  )

  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-bold text-dark">Postos</h1>
        <div className="flex gap-2">
          <input
            aria-label="Pesquisar postos por nome"
            placeholder="Pesquisar por nome..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
          {isSuperAdmin && (
            <button
              onClick={() => setEditing('new')}
              className="flex items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white"
            >
              <Plus size={16} /> Novo posto
            </button>
          )}
        </div>
      </div>

      {notice && (
        <p
          role="status"
          className={`mt-3 rounded-lg p-3 text-sm ${notice.tone === 'ok' ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'}`}
        >
          {notice.text}
        </p>
      )}

      {!isGlobal && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          {user?.province
            ? <>A mostrar e a gerir apenas postos da província de <strong className="text-dark">{user.province}</strong>,âmbito da sua conta{isSuperAdmin ? ' de Super Admin Provincial' : ' de Admin (só pode mudar o estado dos postos)'}.</>
            : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin para conseguir gerir postos.'}
        </p>
      )}

      {search ? (
        <>
        {/* Desktop: tabela */}
        <div className="mt-6 hidden overflow-x-auto rounded-xl border border-gray/15 bg-white md:block">
          <table className="w-full text-sm">
            <thead className="bg-light text-left text-xs uppercase text-gray">
              <tr>
                <th className="px-4 py-3">Nome</th>
                <th className="px-4 py-3">Município</th>
                <th className="px-4 py-3">Estado</th>
                <th className="px-4 py-3">Gás</th>
                <th className="px-4 py-3">Garrafas</th>
                <th className="px-4 py-3">Confiança</th>
                <th className="px-4 py-3">Operador</th>
                <th className="px-4 py-3">Ações</th>
              </tr>
            </thead>
            <tbody>
              {stationsQuery.data?.data.map((s) => (
                <tr key={s.id} className="border-t border-gray/10">
                  <td className="px-4 py-3 font-medium text-dark">{s.name}{s.code && <span className="ml-2 rounded bg-light px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-gray" title="Número do posto">{s.code}</span>}</td>
                  <td className="px-4 py-3 text-gray">{s.municipality}</td>
                  <td className="px-4 py-3">{STATUS_LABEL[s.status]}</td>
                  <td className="px-4 py-3">{s.gas_available ? 'Disponível' : 'Indisponível'}</td>
                  <td className="px-4 py-3 text-gray">{s.cylinder_types || '—'}</td>
                  <td className="px-4 py-3">{s.confidence_score}%</td>
                  <td className="px-4 py-3 text-gray">
                    {s.operator_name ?? <span className="italic text-danger">Sem operador</span>}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex gap-2">
                      <button onClick={() => setUpdatingStatus(s)} aria-label={`Atualizar estado de ${s.name}`} className="text-primary"><Fuel size={16} /></button>
                      {isSuperAdmin && (
                        <button
                          onClick={() => setOrdersFor(s)}
                          aria-label={`Pedidos de ${s.name}: ${ORDERS_LABEL[s.orders_override]}`}
                          title={`Pedidos do município,${ORDERS_LABEL[s.orders_override]}`}
                          className={ordersTone(s.orders_override)}
                        >
                          {s.orders_override === 'BLOCK' ? <PackageX size={16} /> : <Truck size={16} />}
                        </button>
                      )}
                      {isSuperAdmin && !s.is_listed && s.operator_id && (
                        <button
                          onClick={() => confirmNoticeSms(s)}
                          disabled={noticeSmsMutation.isPending}
                          aria-label={`Enviar SMS de subscrição ao operador de ${s.name}`}
                          title="Enviar SMS ao operador a pedir que atualize a subscrição"
                          className="text-warning disabled:opacity-50"
                        >
                          <MessageSquareWarning size={16} />
                        </button>
                      )}
                      {isSuperAdmin && (
                        <>
                          <button onClick={() => setEditing(s)} aria-label="Editar"><Pencil size={16} /></button>
                          <button onClick={() => setConfirmDelete(s)} aria-label="Remover" className="text-danger"><Trash2 size={16} /></button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

          {/* Mobile: cards */}
          <div className="mt-6 space-y-3 md:hidden">{stationsQuery.data?.data.map(renderCard)}</div>
          {stationsQuery.data?.data.length === 0 && (
            <p className="mt-6 text-center text-sm text-gray">Nenhum posto encontrado.</p>
          )}
        </>
      ) : (
        // Organização (Outubro 2026): estado do gás → província → município.
        <div className="mt-6 space-y-4">
          <GroupTabs ariaLabel="Estado do gás" tabs={groupsQuery.data?.tabs ?? GAS_TABS.map((key) => ({ key, count: 0 }))} labels={GAS_LABEL} value={gasTab} onChange={setGasTab} />
          <LocationGroupsView<AdminStation>
            resource="stations"
            queryKey={['admin-stations']}
            params={{ gas: gasTab }}
            groups={groupsQuery.data?.groups}
            isLoading={groupsQuery.isLoading}
            withMunicipalities
            renderItem={renderCard}
            emptyText="Nenhum posto nesta categoria."
          />
        </div>
      )}

      {editing && (
        <StationFormModal
          station={editing === 'new' ? null : editing}
          isSuperAdmin={isSuperAdmin}
          canChooseProvince={isGlobal}
          adminProvinceId={user?.province_id ?? null}
          adminProvinceName={user?.province ?? null}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); queryClient.invalidateQueries({ queryKey: ['admin-stations'] }) }}
        />
      )}

      {confirmDelete && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => !deleteMutation.isPending && setConfirmDelete(null)}>
          <div role="alertdialog" aria-modal="true" aria-labelledby="del-station-title" className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl" onClick={(e) => e.stopPropagation()}>
            <h2 id="del-station-title" className="font-display text-lg font-bold text-dark">Eliminar posto?</h2>
            <p className="mt-2 text-sm text-gray">
              Vai eliminar o posto <strong className="text-dark">{confirmDelete.name}</strong>. O posto deixa de aparecer na plataforma.
            </p>
            {deleteMutation.isError && <p className="mt-2 text-xs text-danger">{getApiErrorMessage(deleteMutation.error, 'Não foi possível eliminar o posto.')}</p>}
            <div className="mt-4 flex justify-end gap-2">
              <button type="button" disabled={deleteMutation.isPending} onClick={() => setConfirmDelete(null)} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">Cancelar</button>
              <button
                type="button"
                disabled={deleteMutation.isPending}
                onClick={() => deleteMutation.mutate(confirmDelete.id, { onSuccess: () => setConfirmDelete(null) })}
                className="rounded-lg bg-danger px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
              >
                {deleteMutation.isPending ? 'A eliminar...' : 'Eliminar'}
              </button>
            </div>
          </div>
        </div>
      )}

      {ordersFor && (
        <StationOrdersModal
          station={ordersFor}
          canMarkOwned={isGlobal}
          onClose={() => setOrdersFor(null)}
          onSaved={(texto) => {
            setOrdersFor(null)
            setNotice({ tone: 'ok', text: texto })
            void queryClient.invalidateQueries({ queryKey: ['admin-stations'] })
          }}
        />
      )}

      {updatingStatus && (
        <StationStatusModal
          station={updatingStatus}
          onClose={() => setUpdatingStatus(null)}
          onSaved={() => { setUpdatingStatus(null); queryClient.invalidateQueries({ queryKey: ['admin-stations'] }) }}
        />
      )}
    </div>
  )
}

function StationFormModal({
  station, isSuperAdmin, canChooseProvince, adminProvinceId, adminProvinceName, onClose, onSaved,
}: {
  station: AdminStation | null
  isSuperAdmin: boolean
  /** Só o Super Admin geral escolhe a província; os outros ficam na sua. */
  canChooseProvince: boolean
  // Um ADMIN só gere postos da sua própria província (pedido explícito
  // do cliente),o backend recusa (422) qualquer `province_id`
  // diferente do da conta, e recusa (403) tocar num posto de outra
  // província. Em vez de deixar o formulário submeter e só depois
  // mostrar o erro, a província já nasce fixa e o campo fica bloqueado
  // para quem não é Super Admin,nunca há uma tentativa inválida.
  adminProvinceId: number | null
  adminProvinceName: string | null
  onClose: () => void
  onSaved: () => void
}) {
  const [form, setForm] = useState({
    name: station?.name ?? '',
    province_id: station?.province_id ?? (canChooseProvince ? 0 : adminProvinceId ?? 0),
    municipality_id: station?.municipality_id ?? 0,
    address: station?.address ?? '',
    reference_point: station?.reference_point ?? '',
    latitude: station?.latitude ?? -8.8,
    longitude: station?.longitude ?? 13.2,
    phone: station?.phone ?? '',
    sms_phone: station?.sms_phone ?? '',
    opening_hours: station?.opening_hours ?? '',
    status: station?.status ?? 'ACTIVE',
  })
  // Vários operadores por posto (Outubro 2026).
  const [operators, setOperators] = useState<{ id: number; name: string }[]>(
    station?.operators?.map((o) => ({ id: o.id, name: o.name })) ?? [],
  )
  const [operatorSearch, setOperatorSearch] = useState('')
  // Botão "Obter localização atual" (Setembro 2026): útil quando o
  // Admin/Operador está fisicamente no posto ao fazer o cadastro e não
  // sabe a latitude/longitude de cor,reaproveita o mesmo hook já
  // usado em OrderGas/Register, só que aqui o resultado vai direto
  // para os campos numéricos em vez de mover um mapa.
  const geolocation = useGeolocation()

  useEffect(() => {
    if (geolocation.status === 'granted' && geolocation.latitude !== null && geolocation.longitude !== null) {
      setForm((f) => ({ ...f, latitude: geolocation.latitude as number, longitude: geolocation.longitude as number }))
    }
  }, [geolocation.status, geolocation.latitude, geolocation.longitude])

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', form.province_id],
    queryFn: () => territoryService.listMunicipalities(form.province_id),
    enabled: !!form.province_id,
  })
  // Candidatos a operador: utilizadores comuns (podem ser promovidos
  // automaticamente ao guardar) ou já operadores de OUTRO posto (esses
  // ficam visíveis mas o backend recusa,a mensagem de erro explica
  // porquê, em vez de os escondermos sem explicação nenhuma).
  const usersQuery = useQuery({
    queryKey: ['admin-users-for-operator', operatorSearch],
    queryFn: () => adminService.listUsers({ search: operatorSearch || undefined }),
  })
  const operatorCandidates = (usersQuery.data?.data ?? []).filter(
    (u) => u.role === 'USER' || u.role === 'STATION_OPERATOR'
  )

  const mutation = useMutation({
    mutationFn: () => {
      // Só o Super Admin mexe nos operadores; para os outros o campo nem vai.
      const payload = isSuperAdmin ? { ...form, operator_ids: operators.map((o) => o.id) } : form
      return station ? adminService.updateStation(station.id, payload) : adminService.createStation(payload)
    },
    onSuccess: onSaved,
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">{station ? 'Editar posto' : 'Novo posto'}</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>

        <div className="mt-4 space-y-3">
          <p className="rounded-lg bg-light px-3 py-2 text-xs text-gray">
            {station?.code
              ? <>Número do posto: <strong className="tabular-nums text-dark">{station.code}</strong> (automático, não se altera)</>
              : 'O número do posto (5 dígitos) é atribuído automaticamente ao guardar.'}
          </p>
          <Field label="Nome" value={form.name} onChange={(v) => setForm({ ...form, name: v })} />

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">Província</span>
              {canChooseProvince ? (
                <select
                  className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                  value={form.province_id}
                  onChange={(e) => setForm({ ...form, province_id: Number(e.target.value), municipality_id: 0 })}
                >
                  <option value={0}>Selecione</option>
                  {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                </select>
              ) : (
                <p className="w-full rounded-lg border border-gray/10 bg-light px-3 py-2 text-sm text-gray">
                  {adminProvinceName ?? '—'}
                </p>
              )}
            </label>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">Município</span>
              <select
                className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                value={form.municipality_id}
                onChange={(e) => setForm({ ...form, municipality_id: Number(e.target.value) })}
                disabled={!form.province_id}
              >
                <option value={0}>Selecione</option>
                {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </select>
            </label>
          </div>

          <Field label="Endereço" value={form.address} onChange={(v) => setForm({ ...form, address: v })} />
          <Field label="Ponto de referência" value={form.reference_point} onChange={(v) => setForm({ ...form, reference_point: v })} />

          <div>
            <div className="grid grid-cols-2 gap-3">
              <Field label="Latitude" type="number" value={String(form.latitude)} onChange={(v) => setForm({ ...form, latitude: Number(v) })} />
              <Field label="Longitude" type="number" value={String(form.longitude)} onChange={(v) => setForm({ ...form, longitude: Number(v) })} />
            </div>
            <button
              type="button"
              onClick={geolocation.request}
              disabled={geolocation.status === 'requesting'}
              className="mt-2 flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-light disabled:opacity-60"
            >
              {geolocation.status === 'requesting' ? (
                <><Loader2 size={13} className="animate-spin" /> A obter localização...</>
              ) : (
                <><LocateFixed size={13} /> Obter localização atual</>
              )}
            </button>
            {geolocation.status === 'granted' && (
              <p className="mt-1 text-xs text-secondary">
                Localização preenchida (precisão de ±{Math.round(geolocation.accuracy ?? 0)} m). Confirme se corresponde à entrada do posto antes de guardar.
              </p>
            )}
            {(geolocation.status === 'denied' || geolocation.status === 'unsupported') && (
              <p className="mt-1 text-xs text-danger">
                {geolocation.status === 'unsupported'
                  ? geolocation.error
                  : 'Não foi possível obter a localização,verifique a permissão do navegador ou introduza as coordenadas manualmente.'}
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Telefone" value={form.phone} onChange={(v) => setForm({ ...form, phone: v })} />
            <Field label="Nº autorizado para SMS" value={form.sms_phone} onChange={(v) => setForm({ ...form, sms_phone: v })} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <Field label="Horário" value={form.opening_hours} onChange={(v) => setForm({ ...form, opening_hours: v })} />
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">Estado</span>
              <select
                className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                value={form.status}
                onChange={(e) => setForm({ ...form, status: e.target.value as AdminStation['status'] })}
              >
                {Object.entries(STATUS_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </label>
          </div>

          <div className="rounded-lg border border-gray/15 p-3">
            {isSuperAdmin ? (
              <>
                <span className="mb-1 block text-sm font-medium text-dark">Operadores do posto</span>
                {operators.length === 0 ? (
                  <p className="mb-2 text-xs italic text-gray">Sem operador atribuído.</p>
                ) : (
                  <ul className="mb-2 flex flex-wrap gap-1.5">
                    {operators.map((o) => (
                      <li key={o.id} className="flex items-center gap-1 rounded-full bg-primary/10 py-0.5 pl-2.5 pr-1 text-xs font-medium text-primary">
                        {o.name}
                        <button
                          type="button"
                          onClick={() => setOperators((list) => list.filter((x) => x.id !== o.id))}
                          aria-label={`Retirar ${o.name}`}
                          className="rounded-full p-0.5 hover:bg-primary/15"
                        >
                          <X size={12} />
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
                <label className="block text-sm">
                  <span className="sr-only">Pesquisar operador</span>
                  <input
                    type="text"
                    placeholder="Pesquisar por nome ou telefone..."
                    value={operatorSearch}
                    onChange={(e) => setOperatorSearch(e.target.value)}
                    className="mb-2 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                  />
                </label>
                <label className="block text-sm">
                  <span className="sr-only">Adicionar operador</span>
                  <select
                    value=""
                    onChange={(e) => {
                      const u = operatorCandidates.find((c) => c.id === Number(e.target.value))
                      if (u && !operators.some((o) => o.id === u.id)) setOperators((list) => [...list, { id: u.id, name: u.name }])
                    }}
                    className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                  >
                    <option value="">+ Adicionar operador</option>
                    {operatorCandidates.filter((u) => !operators.some((o) => o.id === u.id)).map((u) => (
                      <option key={u.id} value={u.id}>
                        {u.name} ({u.phone}){u.role === 'STATION_OPERATOR' ? ',já é Operador' : ''}
                      </option>
                    ))}
                  </select>
                </label>
                <p className="mt-1.5 text-xs text-gray">
                  Um posto pode ter vários operadores, mas cada operador só pertence a um posto.
                  Quem ainda não é Operador passa a sê-lo automaticamente ao guardar; quem for retirado volta a utilizador comum.
                </p>
              </>
            ) : (
              <>
                <span className="mb-1 block text-sm font-medium text-dark">Operadores do posto</span>
                <p className="text-sm text-gray">{station?.operator_name ?? 'Sem operador atribuído'}</p>
                <p className="mt-1.5 text-xs text-gray">
                  Só o Super Admin pode atribuir ou alterar os operadores de um posto.
                </p>
              </>
            )}
          </div>

          {mutation.isError && (
            <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível guardar. Verifique os dados.')}</p>
          )}

          <button
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending}
            className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {mutation.isPending ? 'A guardar...' : 'Guardar'}
          </button>
        </div>
      </div>
    </div>
  )
}

function StationStatusModal({ station, onClose, onSaved }: { station: AdminStation; onClose: () => void; onSaved: () => void }) {
  const [gasAvailable, setGasAvailable] = useState(station.gas_available)
  const [queueLevel, setQueueLevel] = useState(station.queue_level ?? 'LOW')
  const [cylinderTypes, setCylinderTypes] = useState(station.cylinder_types ?? '')
  const [status, setStatus] = useState<AdminStation['status']>(station.status)

  const mutation = useMutation({
    mutationFn: () =>
      adminService.updateStationStatus(station.id, {
        gas_available: gasAvailable,
        queue_level: queueLevel,
        cylinder_types: cylinderTypes,
        status,
      }),
    onSuccess: onSaved,
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">Atualizar estado,{station.name}</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>

        <div className="mt-4 space-y-3">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Estado do posto</span>
            <select
              value={status}
              onChange={(e) => setStatus(e.target.value as AdminStation['status'])}
              className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
            >
              {Object.entries(STATUS_LABEL).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>

          <div className="flex gap-2">
            <button
              onClick={() => setGasAvailable(true)}
              className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium ${gasAvailable ? 'border-success bg-success/10 text-success' : 'border-gray/20'}`}
            >
              Tem gás
            </button>
            <button
              onClick={() => setGasAvailable(false)}
              className={`flex-1 rounded-lg border px-3 py-2 text-sm font-medium ${!gasAvailable ? 'border-danger bg-danger/10 text-danger' : 'border-gray/20'}`}
            >
              Sem gás
            </button>
          </div>

          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Fila</span>
            <select
              value={queueLevel}
              onChange={(e) => setQueueLevel(e.target.value)}
              className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
            >
              <option value="LOW">Fila baixa</option>
              <option value="MEDIUM">Fila média</option>
              <option value="HIGH">Fila alta</option>
            </select>
          </label>

          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Tipos de garrafa disponíveis</span>
            <input
              type="text"
              value={cylinderTypes}
              onChange={(e) => setCylinderTypes(e.target.value)}
              placeholder="Ex: garrafa leve, garrafa pequena, garrafa grande"
              className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
            />
            <span className="mt-1 block text-xs text-gray">
              Nomes separados por vírgula. O SMS nunca altera este campo.
            </span>
          </label>

          {mutation.isError && <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível atualizar o estado.')}</p>}

          <button
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending}
            className="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60"
          >
            {mutation.isPending ? 'A atualizar...' : 'Atualizar estado'}
          </button>
        </div>
      </div>
    </div>
  )
}

function Field({ label, value, onChange, type = 'text' }: { label: string; value: string; onChange: (v: string) => void; type?: string }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-dark">{label}</span>
      <input type={type} value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
    </label>
  )
}

/**
 * Interruptor dos pedidos de um posto (Novembro 2026).
 *
 * Três estados em vez de um liga/desliga, porque o normal,e o valor por
 * omissão,é não haver interruptor nenhum: quem decide é a subscrição paga.
 * ALLOW e BLOCK são a mão do administrador por cima disso, e ambos pedem um
 * motivo, que é o texto que o operador vai ler no aviso e que fica na
 * auditoria.
 */
function StationOrdersModal({
  station, canMarkOwned, onClose, onSaved,
}: {
  station: AdminStation
  /** Só o Super Admin geral decide que um posto é da própria KeruGás. */
  canMarkOwned: boolean
  onClose: () => void
  onSaved: (texto: string) => void
}) {
  const [escolha, setEscolha] = useState<StationOrdersOverride>(station.orders_override)
  const [motivo, setMotivo] = useState(station.orders_override_reason ?? '')
  const [presenca, setPresenca] = useState<StationOrdersOverride>(station.listing_override)
  const [proprio, setProprio] = useState(station.platform_owned)

  const mudouPedidos = escolha !== station.orders_override
  const mudouPresenca = presenca !== station.listing_override || proprio !== station.platform_owned

  const mutation = useMutation({
    mutationFn: async () => {
      // Duas coisas independentes na mesma janela: envia-se só o que mexeu.
      const pedidos = mudouPedidos
        ? await adminService.setStationOrdersOverride(station.id, {
            override: escolha,
            reason: escolha === 'INHERIT' ? undefined : (motivo.trim() || undefined),
          })
        : null

      if (mudouPresenca) {
        await adminService.setStationListing(station.id, {
          override: presenca,
          platform_owned: proprio,
          reason: presenca === 'INHERIT' ? undefined : (motivo.trim() || undefined),
        })
      }

      return pedidos
    },
    onSuccess: (r) => {
      if (!r) {
        onSaved(`Presença de ${station.name} actualizada.`)
        return
      }
      const estado = r.canReceiveOrders ? 'recebe pedidos' : 'não recebe pedidos'
      const aviso = r.operatorNotified
        ? 'O operador foi avisado na plataforma.'
        : 'Este posto não tem operador, por isso não houve aviso.'
      onSaved(`${station.name} ${estado}. ${aviso}`)
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={() => !mutation.isPending && onClose()}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="orders-override-title"
        // Num telemóvel a janela é mais alta do que o ecrã: sem isto o título
        // e o botão Guardar ficavam cortados e não havia como lá chegar.
        className="max-h-[calc(100dvh-2rem)] w-full max-w-md overflow-y-auto overscroll-contain rounded-2xl bg-white p-5 shadow-xl"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-start justify-between">
          <div>
            <h2 id="orders-override-title" className="font-display text-lg font-bold text-dark">Pedidos e presença</h2>
            <p className="text-sm text-gray">{station.name}</p>
          </div>
          <button type="button" onClick={onClose} aria-label="Fechar" className="text-gray"><X size={18} /></button>
        </div>

        <fieldset className="mt-4 space-y-2">
          <legend className="sr-only">Quem decide se este posto recebe pedidos</legend>
          {(Object.keys(ORDERS_LABEL) as StationOrdersOverride[]).map((op) => (
            <label
              key={op}
              className={`flex cursor-pointer gap-3 rounded-xl border p-3 ${escolha === op ? 'border-primary bg-primary/5' : 'border-gray/20'}`}
            >
              <input
                type="radio"
                name="orders-override"
                value={op}
                checked={escolha === op}
                onChange={() => setEscolha(op)}
                className="mt-1"
              />
              <span className="min-w-0">
                <span className={`block text-sm font-semibold ${ordersTone(op)}`}>{ORDERS_LABEL[op]}</span>
                <span className="block text-xs leading-relaxed text-gray">{ORDERS_HELP[op]}</span>
              </span>
            </label>
          ))}
        </fieldset>

        {escolha !== 'INHERIT' && (
          <label className="mt-4 block text-xs font-semibold text-dark">
            Motivo
            <input
              type="text"
              value={motivo}
              maxLength={200}
              onChange={(e) => setMotivo(e.target.value)}
              placeholder={escolha === 'BLOCK' ? 'Ex.: reclamações de clientes' : 'Ex.: cortesia de lançamento'}
              className="mt-1 block w-full rounded-lg border border-gray/20 px-3 py-2 text-sm font-normal"
            />
            <span className="mt-1 block font-normal text-gray">
              O operador vai ler isto no aviso, e fica no registo de auditoria.
            </span>
          </label>
        )}

        <hr className="my-5 border-gray/15" />

        <div>
          <div className="flex items-baseline justify-between gap-2">
            <h3 className="text-sm font-bold text-dark">Presença na aplicação</h3>
            {!station.listing_enforced && (
              <span className="rounded-full bg-gray/10 px-2 py-0.5 text-[11px] font-semibold text-gray">Mecanismo desligado</span>
            )}
          </div>
          <p className="mt-1 text-xs leading-relaxed text-gray">
            {station.listing_enforced
              ? 'A presença paga está a ser exigida: um posto sem subscrição não aparece no mapa nem nas pesquisas.'
              : 'Ainda não se cobra a presença,todos os postos aparecem. O que escolher aqui fica guardado e só passa a valer quando a cobrança for ligada nas Definições das subscrições.'}
          </p>

          {station.listing_grace_until && (
            <p className="mt-2 rounded-lg bg-warning/10 px-3 py-2 text-xs font-semibold text-warning">
              A subscrição da presença expirou. O posto sai do mapa a{' '}
              {new Date(station.listing_grace_until).toLocaleDateString('pt-PT', { day: '2-digit', month: 'long' })}.
            </p>
          )}

          <div className="mt-3 flex flex-wrap gap-2">
            {(Object.keys(LISTING_LABEL) as StationOrdersOverride[]).map((op) => (
              <button
                key={op}
                type="button"
                aria-pressed={presenca === op}
                onClick={() => setPresenca(op)}
                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${presenca === op ? 'border-primary bg-primary/10 text-primary' : 'border-gray/20 text-gray'}`}
              >
                {LISTING_LABEL[op]}
              </button>
            ))}
          </div>

          <label className={`mt-3 flex items-start gap-2 text-xs ${canMarkOwned ? 'text-dark' : 'text-gray'}`}>
            <input
              type="checkbox"
              checked={proprio}
              disabled={!canMarkOwned}
              onChange={(e) => setProprio(e.target.checked)}
              className="mt-0.5"
            />
            <span>
              <span className="font-semibold">Posto da própria KeruGás</span>
              <span className="block text-gray">
                {canMarkOwned
                  ? 'Nunca paga presença e nunca sai do mapa.'
                  : 'Nunca paga presença e nunca sai do mapa. Só o Super Admin geral muda isto.'}
              </span>
            </span>
          </label>
        </div>

        {mutation.isError && (
          <p className="mt-3 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível guardar as alterações deste posto.')}</p>
        )}

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" disabled={mutation.isPending} onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">
            Cancelar
          </button>
          <button
            type="button"
            disabled={mutation.isPending || (!mudouPedidos && !mudouPresenca)}
            onClick={() => mutation.mutate()}
            className="inline-flex items-center gap-2 rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50"
          >
            {mutation.isPending && <Loader2 size={14} className="animate-spin" aria-hidden="true" />}
            Guardar
          </button>
        </div>
      </div>
    </div>
  )
}
