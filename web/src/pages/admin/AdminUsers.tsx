import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { adminService } from '@/services/adminService'
import { adminGroupsService } from '@/services/adminGroupsService'
import { GroupTabs } from '@/components/admin/GroupTabs'
import { LocationGroupsView } from '@/components/admin/LocationGroupsView'
import type { AuthUser } from '@/types'
import { territoryService } from '@/services/territoryService'
import { getApiErrorMessage } from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { canRemoveAvatarOf, hasSuperPowers, isGlobalSuperAdmin, isProvinceSuperAdmin } from '@/lib/roles'
import { Avatar } from '@/components/Avatar'
import { RemoveAvatarButton } from '@/components/RemoveAvatarButton'

const ROLE_LABEL: Record<string, string> = {
  SUPER_ADMIN: 'Super Admin', PROVINCE_SUPER_ADMIN: 'Super Admin Provincial', ADMIN: 'Admin', MODERATOR: 'Moderador',
  STATION_OPERATOR: 'Operador', STATION_MANAGER: 'Admin de posto', DRIVER: 'Condutor', STATION_DRIVER: 'Condutor de posto',
  INVESTOR: 'Investidor', USER: 'Utilizador',
}

// Pessoal ligado a um posto escolhido aqui (Novembro 2026).
const STATION_LINKED_ROLES = ['STATION_MANAGER', 'STATION_DRIVER']

// Contas de Super Admin (geral ou provincial): só o Super Admin GERAL as
// cria, altera ou promove (Outubro 2026).
const SUPER_ONLY_ROLES = ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN']

// Condutor (Setembro 2026),mesma restrição de Admin/Super Admin:
// só o Super Admin cria/altera contas de Condutor (decisão explícita
// do cliente, ver UserAdminController::PRIVILEGED_ROLES no backend,
// que devolve 403 a um Admin comum que tente isto por esta via).
// Admin de Posto (Novembro 2026): só o Super Admin geral ou o Provincial.
const PRIVILEGED_ROLES = ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'DRIVER', 'STATION_MANAGER']

// Abas pela ordem pedida pelo cliente (Outubro 2026).
const TAB_ORDER = ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'ADMIN', 'STATION_MANAGER', 'STATION_OPERATOR', 'MODERATOR', 'USER', 'DRIVER', 'STATION_DRIVER', 'INVESTOR']
const TAB_LABEL: Record<string, string> = {
  SUPER_ADMIN: 'Super Admins', PROVINCE_SUPER_ADMIN: 'Admins provinciais', ADMIN: 'Admins', STATION_OPERATOR: 'Operadores de posto',
  MODERATOR: 'Moderadores', USER: 'Utilizadores', DRIVER: 'Condutores', INVESTOR: 'Investidores',
  STATION_MANAGER: 'Admins de posto', STATION_DRIVER: 'Condutores de posto',
}
// Administradores e moderadores: só por província; os restantes descem ao município.
const BY_MUNICIPALITY = ['STATION_OPERATOR', 'STATION_MANAGER', 'STATION_DRIVER', 'USER', 'DRIVER']

interface PendingEdit {
  role?: string
  province_id?: number | null
  municipality_id?: number | null
  sms_daily_limit?: number | null
  can_promote_users?: boolean
  station_id?: number | null
}

export default function AdminUsers() {
  const [search, setSearch] = useState('')
  // Edições ainda não guardadas, por utilizador,só disparamos o PUT
  // quando o próprio clica em "Guardar", nunca a meio da edição. Isto é
  // necessário (não só conveniente) para o caso "promover a Admin":
  // o backend exige província e papel no MESMO pedido quando o
  // utilizador ainda não é Admin, porque `UpdateUserAdminRequest` só
  // conhece o papel "novo" através do próprio payload,mandar o papel
  // sozinho primeiro chumbaria sempre com "província obrigatória".
  const [pending, setPending] = useState<Record<number, PendingEdit>>({})
  const queryClient = useQueryClient()
  const { user: currentUser } = useAuth()
  const isGlobal = isGlobalSuperAdmin(currentUser)
  const isProvincial = isProvinceSuperAdmin(currentUser)
  const superPowers = hasSuperPowers(currentUser)
  // Outubro 2026: um Admin só muda papéis se o Super Admin lhe der essa permissão.
  const mayChangeRoles = superPowers || !!currentUser?.can_promote_users
  // Investidores (Outubro 2026): só o Super Admin geral mexe nestas contas.
  const canTouch = (role: string) =>
    isGlobal || (role !== 'INVESTOR' && (isProvincial ? !SUPER_ONLY_ROLES.includes(role) : !PRIVILEGED_ROLES.includes(role)))

  const usersQuery = useQuery({
    queryKey: ['admin-users', search],
    queryFn: () => adminService.listUsers({ search: search || undefined }),
    enabled: search.trim() !== '',
  })

  // Organização por categoria → província → município (Outubro 2026).
  const [roleTab, setRoleTab] = useState('')
  const groupsQuery = useQuery({
    queryKey: ['admin-users', 'groups', roleTab],
    queryFn: () => adminGroupsService.groups('users', { role: roleTab || undefined }),
  })
  const orderedTabs = TAB_ORDER.map((key) => groupsQuery.data?.tabs.find((t) => t.key === key) ?? { key, count: 0 })
  const activeRole = roleTab || (groupsQuery.data ? (orderedTabs.find((t) => t.count > 0)?.key ?? 'USER') : '')
  useEffect(() => {
    if (!roleTab && activeRole) setRoleTab(activeRole)
  }, [roleTab, activeRole])

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: { station_id?: number | null; role?: string; status?: string; province_id?: number | null; municipality_id?: number | null; sms_daily_limit?: number | null; can_promote_users?: boolean } }) =>
      adminService.updateUser(id, payload),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['admin-users'] })
      setPending((prev) => {
        const next = { ...prev }
        delete next[variables.id]
        return next
      })
    },
  })

  const setPendingField = (id: number, field: keyof PendingEdit, value: PendingEdit[keyof PendingEdit]) => {
    setPending((prev) => ({ ...prev, [id]: { ...prev[id], [field]: value } }))
  }

  // Um cartão de utilizador (usado na pesquisa e dentro de cada grupo).
  const renderUser = (u: AuthUser) => {
    const canEditAccount = canTouch(u.role) && u.id !== currentUser?.id
    const canEditRole = canEditAccount && mayChangeRoles
    const edit = pending[u.id]
    const effectiveRole = edit?.role ?? u.role
    const effectiveProvinceId = edit?.province_id !== undefined ? edit.province_id : u.province_id
    const effectiveMunicipalityId = edit?.municipality_id !== undefined ? edit.municipality_id : u.municipality_id
    // Um Admin sem província nunca vê pedidos de gás nenhuns
    // (o backend filtra por `province_id`),por isso a província
    // é obrigatória sempre que o papel efetivo (já guardado ou
    // ainda só selecionado) for ADMIN ou CONDUTOR, não só quando
    // já é (mesma validação em UpdateUserAdminRequest). Condutor
    // precisa também de município,é isso que decide que
    // pedidos recebe, ao contrário do Admin (só por província).
    // Admin de Posto / Condutor de Posto: a província e o município vêm do posto escolhido.
    const stationRequired = STATION_LINKED_ROLES.includes(effectiveRole)
    const currentStationId = effectiveRole === u.role ? (u.managed_station_id ?? u.driver_station_id ?? null) : null
    const effectiveStationId = edit?.station_id !== undefined ? edit.station_id : currentStationId
    const provinceRequired = !stationRequired && (effectiveRole === 'ADMIN' || effectiveRole === 'PROVINCE_SUPER_ADMIN' || effectiveRole === 'DRIVER')
    const municipalityRequired = effectiveRole === 'DRIVER'
    const effectiveSmsDailyLimit = edit?.sms_daily_limit !== undefined ? edit.sms_daily_limit : (u.sms_daily_limit ?? null)
    const hasUnsavedChanges = edit !== undefined
    const canSave = hasUnsavedChanges
      && (!provinceRequired || !!effectiveProvinceId)
      && (!municipalityRequired || !!effectiveMunicipalityId)
      && (!stationRequired || !!effectiveStationId)

    return (
      <div key={u.id} className="flex flex-col gap-3 rounded-xl border border-gray/15 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <Avatar name={u.name} url={u.avatar_url} size="md" />
          <div>
            <p className="font-medium text-dark">
              {u.name}
              {u.deletion_scheduled_for && (
                <span className="ml-2 rounded-full bg-danger/10 px-2 py-0.5 text-xs font-medium text-danger" title="A pessoa pediu para eliminar a conta">
                  Eliminação a {new Date(u.deletion_scheduled_for).toLocaleDateString('pt-PT')}
                </span>
              )}
            </p>
            <p className="text-sm text-gray">{u.phone} · {u.municipality}{u.station ? ` · ${u.station.name}` : ''}</p>
            {u.avatar_url && u.id !== currentUser?.id && canRemoveAvatarOf(currentUser, u) && (
              <div className="mt-1">
                <RemoveAvatarButton userId={u.id} userName={u.name} onRemoved={() => void queryClient.invalidateQueries({ queryKey: ['admin-users'] })} />
              </div>
            )}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={effectiveRole}
            aria-label={`Alterar papel de ${u.name}`}
            disabled={!canEditRole}
            onChange={(e) => setPendingField(u.id, 'role', e.target.value)}
            className="rounded-lg border border-gray/20 px-2 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
          >
            {Object.entries(ROLE_LABEL)
              // O papel atual aparece sempre (um Admin vê "Super Admin"
              // em vez de "Moderador" por engano); as opções
              // privilegiadas só se escolhem sendo Super Admin.
              .filter(([role]) => canTouch(role) || role === u.role)
              // Ninguém passa a Investidor por aqui: associa-se um município na página Investidores.
              .filter(([role]) => role !== 'INVESTOR' || u.role === 'INVESTOR')
              // Condutor KeruGás e condutor de posto nunca se convertem um no outro (Novembro 2026).
              .filter(([role]) => !(u.role === 'DRIVER' && role === 'STATION_DRIVER') && !(u.role === 'STATION_DRIVER' && role === 'DRIVER'))
              .map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>

          {provinceRequired && (
            <select
              value={effectiveProvinceId ?? ''}
              aria-label={`Província de ${u.name}`}
              // Só o Super Admin geral muda a província de alguém.
              disabled={!canEditRole || (!isGlobal && !!u.province_id)}
              onChange={(e) => {
                const provinceId = e.target.value ? Number(e.target.value) : null
                setPendingField(u.id, 'province_id', provinceId)
                // Trocar de província invalida o município já
                // escolhido (pertence à província anterior) —
                // mesmo padrão do formulário de criação de Condutor.
                if (municipalityRequired) setPendingField(u.id, 'municipality_id', null)
              }}
              className="rounded-lg border border-gray/20 px-2 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
            >
              <option value="">Escolha a província...</option>
              {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          )}

          {stationRequired && (
            <StationSelect
              value={effectiveStationId ?? null}
              disabled={!canEditRole}
              label={`Posto de ${u.name}`}
              currentName={effectiveRole === u.role ? u.station?.name : undefined}
              onChange={(stationId) => setPendingField(u.id, 'station_id', stationId)}
            />
          )}

          {municipalityRequired && (
            <MunicipalitySelect
              provinceId={effectiveProvinceId ?? undefined}
              value={effectiveMunicipalityId ?? null}
              disabled={!canEditRole}
              label={`Município de ${u.name}`}
              onChange={(municipalityId) => setPendingField(u.id, 'municipality_id', municipalityId)}
            />
          )}

          {superPowers && effectiveRole === 'ADMIN' && (
            <div className="flex items-center gap-1">
              <label className="text-xs text-gray" title="Limite diário de SMS que este Admin pode enviar pela Central de Operações,em branco para sem limite (controlo de custo, Setembro 2026).">
                Limite SMS/dia
              </label>
              <input
                type="number"
                min={0}
                aria-label={`Limite diário de SMS de ${u.name}`}
                value={effectiveSmsDailyLimit ?? ''}
                placeholder="Sem limite"
                onChange={(e) => setPendingField(u.id, 'sms_daily_limit', e.target.value === '' ? null : Number(e.target.value))}
                className="w-24 rounded-lg border border-gray/20 px-2 py-1.5 text-sm"
              />
            </div>
          )}

          {superPowers && effectiveRole === 'ADMIN' && (
            <label
              className="flex cursor-pointer items-center gap-1.5 text-xs text-gray"
              title="Se ativo, este Admin pode promover utilizadores da sua província a Moderador ou Operador. Desativado por omissão."
            >
              <input
                type="checkbox"
                role="switch"
                aria-label={`Permitir que ${u.name} promova utilizadores`}
                checked={edit?.can_promote_users !== undefined ? edit.can_promote_users : !!u.can_promote_users}
                onChange={(e) => setPendingField(u.id, 'can_promote_users', e.target.checked)}
              />
              Pode promover utilizadores
            </label>
          )}

          {hasUnsavedChanges && (
            <button
              disabled={!canSave || updateMutation.isPending}
              onClick={() => updateMutation.mutate({
                id: u.id,
                payload: {
                  role: edit?.role,
                  province_id: edit?.province_id,
                  municipality_id: edit?.municipality_id,
                  sms_daily_limit: edit?.sms_daily_limit,
                  can_promote_users: edit?.can_promote_users,
                  station_id: stationRequired ? effectiveStationId : undefined,
                },
              })}
              className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-50"
            >
              Guardar
            </button>
          )}

          <button
            disabled={!canEditAccount}
            onClick={() => updateMutation.mutate({ id: u.id, payload: { status: u.status === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE' } })}
            className={`rounded-lg border px-3 py-1.5 text-sm font-medium disabled:cursor-not-allowed disabled:opacity-50 ${u.status === 'ACTIVE' ? 'border-danger text-danger' : 'border-success text-success'}`}
          >
            {u.status === 'ACTIVE' ? 'Bloquear' : 'Desbloquear'}
          </button>
        </div>

        {hasUnsavedChanges && provinceRequired && !effectiveProvinceId && (
          <p className="text-xs text-danger sm:w-full">
            Uma conta de {ROLE_LABEL[effectiveRole] ?? 'Admin'} precisa de uma província atribuída antes de poder ser guardada.
          </p>
        )}
        {hasUnsavedChanges && stationRequired && !effectiveStationId && (
          <p className="text-xs text-danger sm:w-full">
            Escolha o posto a que esta conta pertence,a província e o município vêm do posto.
          </p>
        )}
        {hasUnsavedChanges && municipalityRequired && !!effectiveProvinceId && !effectiveMunicipalityId && (
          <p className="text-xs text-danger sm:w-full">
            Uma conta de Condutor precisa de um município atribuído,é isso que determina que pedidos recebe.
          </p>
        )}
        {updateMutation.isError && updateMutation.variables?.id === u.id && (
          <p className="text-xs text-danger sm:w-full">
            {getApiErrorMessage(updateMutation.error, 'Não foi possível guardar as alterações.')}
          </p>
        )}
      </div>
    )
  }
  return (
    <div>
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <h1 className="text-xl font-bold text-dark">Utilizadores</h1>
        <input
          aria-label="Pesquisar utilizadores por nome ou telefone"
          placeholder="Pesquisar por nome ou telefone..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="rounded-lg border border-gray/20 px-3 py-2 text-sm"
        />
      </div>

      {isProvincial && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          Super Admin Provincial: gere os utilizadores da província de <strong className="text-dark">{currentUser?.province ?? '—'}</strong>,Admins, Condutores, Operadores e Moderadores, incluindo a permissão de promover e o limite de SMS dos Admins. Contas de Super Admin e mudanças de província são só do Super Admin geral.
        </p>
      )}
      {!superPowers && (
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          {currentUser?.province
            ? <>A mostrar e a gerir apenas utilizadores da província de <strong className="text-dark">{currentUser.province}</strong>,âmbito da sua conta de Admin. Só o Super Admin pode promover utilizadores a Admin ou alterar contas já administrativas.{mayChangeRoles ? ' O Super Admin ativou na sua conta a permissão de promover a Moderador ou Operador.' : ' Mudar o papel de um utilizador (Moderador, Operador) exige uma permissão que só o Super Admin pode ativar na sua conta.'}</>
            : 'A sua conta de Admin ainda não tem província atribuída,contacte um Super Admin para conseguir gerir utilizadores. Só o Super Admin pode promover utilizadores a Admin ou alterar contas já administrativas.'}
        </p>
      )}

      {(superPowers || mayChangeRoles) && <NewStationStaffForm canCreateManager={superPowers} />}

      <div className="mt-6 space-y-4">
        {search ? (
          <div className="space-y-3">
            {usersQuery.data?.data.map(renderUser)}
            {usersQuery.data?.data.length === 0 && <p className="text-center text-sm text-gray">Nenhum utilizador encontrado.</p>}
          </div>
        ) : (
          <>
            <GroupTabs
              ariaLabel="Categorias de utilizadores"
              tabs={orderedTabs}
              labels={TAB_LABEL}
              value={activeRole}
              onChange={setRoleTab}
            />
            <LocationGroupsView<AuthUser>
              resource="users"
              queryKey={['admin-users']}
              params={{ role: activeRole }}
              groups={groupsQuery.data?.groups}
              isLoading={groupsQuery.isLoading || !activeRole}
              withMunicipalities={BY_MUNICIPALITY.includes(activeRole)}
              renderItem={renderUser}
              emptyText="Nenhum utilizador nesta categoria."
            />
          </>
        )}
      </div>
    </div>
  )
}

/**
 * Seletor de município para promover/editar um Condutor,só ativo
 * depois de escolhida a província (mesma dependência já usada no
 * formulário de criação de Condutor em AdminDrivers.tsx).
 */
function MunicipalitySelect({
  provinceId, value, disabled, label, onChange,
}: {
  provinceId?: number
  value: number | null
  disabled: boolean
  label: string
  onChange: (municipalityId: number | null) => void
}) {
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(provinceId!),
    enabled: !!provinceId,
  })

  return (
    <select
      value={value ?? ''}
      aria-label={label}
      disabled={disabled || !provinceId}
      onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
      className="rounded-lg border border-gray/20 px-2 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
    >
      <option value="">Escolha o município...</option>
      {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
    </select>
  )
}

/**
 * Seletor de posto para o Admin de Posto / Condutor de Posto (Novembro 2026).
 * Procura pelo nome ou número; o servidor só devolve postos do âmbito de
 * quem pede (o Provincial e o Admin só veem a sua província).
 */
function StationSelect({
  value, disabled, label, currentName, onChange,
}: {
  value: number | null
  disabled: boolean
  label: string
  currentName?: string
  onChange: (stationId: number | null) => void
}) {
  const [term, setTerm] = useState('')
  const stationsQuery = useQuery({
    queryKey: ['admin-stations-picker', term],
    queryFn: () => adminService.listStations({ search: term || undefined }),
    enabled: !disabled,
  })
  const options = stationsQuery.data?.data ?? []
  const hasCurrent = value !== null && !options.some((s) => s.id === value)

  return (
    <div className="flex items-center gap-1">
      <input
        value={term}
        disabled={disabled}
        onChange={(e) => setTerm(e.target.value)}
        placeholder="Procurar posto..."
        aria-label={`${label},procurar`}
        className="w-32 rounded-lg border border-gray/20 px-2 py-1.5 text-sm disabled:opacity-50"
      />
      <select
        value={value ?? ''}
        aria-label={label}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value ? Number(e.target.value) : null)}
        className="max-w-[14rem] rounded-lg border border-gray/20 px-2 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
      >
        <option value="">Escolha o posto...</option>
        {hasCurrent && <option value={value!}>{currentName ?? `Posto #${value}`}</option>}
        {options.map((s) => <option key={s.id} value={s.id}>{s.code ? `${s.code} · ` : ''}{s.name} ({s.municipality})</option>)}
      </select>
    </div>
  )
}

/** Criar uma conta de Admin de Posto ou de Condutor de Posto (Novembro 2026). */
function NewStationStaffForm({ canCreateManager }: { canCreateManager: boolean }) {
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', phone: '', password: '', role: canCreateManager ? 'STATION_MANAGER' : 'STATION_DRIVER' })
  const [stationId, setStationId] = useState<number | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const mutation = useMutation({
    mutationFn: () => adminService.createUser({ ...form, name: form.name.trim(), phone: form.phone.trim(), station_id: stationId }),
    onSuccess: (u) => {
      setDone(`${u.name} criado como ${ROLE_LABEL[u.role] ?? u.role}${u.station ? ` em ${u.station.name}` : ''}.`)
      setForm((f) => ({ ...f, name: '', phone: '', password: '' }))
      void queryClient.invalidateQueries({ queryKey: ['admin-users'] })
    },
  })

  const valid = form.name.trim() && form.phone.trim() && form.password.length >= 8 && !!stationId

  if (!open) {
    return (
      <button onClick={() => setOpen(true)} className="mt-4 rounded-lg border border-primary/30 px-3 py-2 text-sm font-semibold text-primary hover:bg-primary/5">
        + Nova conta de posto ({canCreateManager ? 'Admin de posto ou condutor de posto' : 'condutor de posto'})
      </button>
    )
  }

  const field = 'rounded-lg border border-gray/20 px-3 py-2 text-sm'

  return (
    <form
      onSubmit={(e) => { e.preventDefault(); setDone(null); if (valid) mutation.mutate() }}
      className="mt-4 space-y-3 rounded-xl border border-gray/15 bg-white p-4"
    >
      <div className="flex items-center justify-between">
        <p className="font-semibold text-dark">Nova conta de posto</p>
        <button type="button" onClick={() => setOpen(false)} className="text-sm text-gray">Fechar</button>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        <select value={form.role} aria-label="Tipo de conta" onChange={(e) => setForm((f) => ({ ...f, role: e.target.value }))} className={field}>
          {canCreateManager && <option value="STATION_MANAGER">Admin de posto (gerente)</option>}
          <option value="STATION_DRIVER">Condutor de posto</option>
        </select>
        <StationSelect value={stationId} disabled={false} label="Posto da nova conta" onChange={setStationId} />
        <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="Nome completo" aria-label="Nome" className={field} />
        <input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} placeholder="Telemóvel (923 000 000)" inputMode="tel" aria-label="Telemóvel" className={field} />
        <input value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} type="password" placeholder="Palavra-passe (mín. 8)" aria-label="Palavra-passe" className={field} />
      </div>
      <p className="text-xs text-gray">A província e o município da conta são os do posto. Um posto pode ter vários admins e vários condutores.</p>
      {mutation.isError && <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível criar a conta.')}</p>}
      {done && <p className="text-sm text-success">{done}</p>}
      <button disabled={!valid || mutation.isPending} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
        {mutation.isPending ? 'A criar...' : 'Criar conta'}
      </button>
    </form>
  )
}
