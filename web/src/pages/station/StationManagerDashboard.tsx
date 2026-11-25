import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Flame, Package, Store, Truck, UserPlus, Users } from 'lucide-react'
import { StationOrdersPanel } from '@/components/station/StationOrdersPanel'
import { stationManagerService } from '@/services/stationManagerService'
import { getApiErrorMessage } from '@/lib/api'
import type { StationManagerOverview, StationStaffPerson } from '@/types'

type Tab = 'pedidos' | 'posto' | 'operadores' | 'condutores'

/**
 * Painel do Admin de Posto / gerente (Novembro 2026). Tudo é do posto ligado
 * à conta: informações, operadores (só do município do posto) e condutores
 * do posto. O estado do gás atualiza-se no painel /operator.
 */
export default function StationManagerDashboard() {
  const [tab, setTab] = useState<Tab>('pedidos')
  const query = useQuery({ queryKey: ['station-manager'], queryFn: stationManagerService.overview })

  if (query.isLoading) {
    return <div className="mx-auto max-w-2xl px-4 py-16 text-center text-gray">A carregar o seu posto...</div>
  }

  if (query.isError || !query.data) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-16 text-center">
        <p className="font-medium text-dark">Não existe posto atribuído à sua conta.</p>
        <p className="mt-1 text-sm text-gray">Contacte o suporte do KERUGAS para associar o seu posto.</p>
      </div>
    )
  }

  const data = query.data
  const station = data.station

  return (
    <div className="mx-auto max-w-2xl px-4 py-10">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wider text-primary">Admin de posto</p>
          <h1 className="text-2xl font-extrabold text-dark">{station.name}</h1>
          <p className="text-gray">
            {station.code ? `N.º ${station.code} · ` : ''}{station.municipality}, {station.province}
          </p>
        </div>
        <Link to="/operator" className="flex shrink-0 items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white hover:opacity-90">
          <Flame size={16} /> Estado do gás
        </Link>
      </div>

      <div role="tablist" aria-label="Gestão do posto" className="mt-6 grid grid-cols-2 gap-1 rounded-xl bg-light p-1 text-sm font-medium sm:grid-cols-4">
        {([
          ['pedidos', 'Pedidos', Package],
          ['posto', 'Posto', Store],
          ['operadores', `Operadores (${data.operators.length})`, Users],
          ['condutores', `Condutores (${data.drivers.length})`, Truck],
        ] as const).map(([key, label, Icon]) => (
          <button
            key={key}
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={`flex items-center justify-center gap-1.5 rounded-lg py-2 transition-colors ${tab === key ? 'bg-white text-dark shadow-sm' : 'text-gray'}`}
          >
            <Icon size={14} /> {label}
          </button>
        ))}
      </div>

      {tab === 'pedidos' && <StationOrdersPanel />}
      {tab === 'posto' && <StationInfoForm data={data} />}
      {tab === 'operadores' && <OperatorsPanel data={data} />}
      {tab === 'condutores' && <DriversPanel data={data} />}
    </div>
  )
}

function useOverviewMutation<T>(fn: (v: T) => Promise<StationManagerOverview>, onDone?: () => void) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: fn,
    onSuccess: (overview) => {
      queryClient.setQueryData(['station-manager'], overview)
      onDone?.()
    },
  })
}

const input = 'w-full rounded-lg border border-gray/20 px-3 py-2 text-sm'

function StationInfoForm({ data }: { data: StationManagerOverview }) {
  const s = data.station
  const [form, setForm] = useState({
    name: s.name ?? '', address: s.address ?? '', reference_point: s.reference_point ?? '',
    phone: s.phone ?? '', opening_hours: s.opening_hours ?? '',
  })
  const [saved, setSaved] = useState(false)
  useEffect(() => setSaved(false), [form])
  const mutation = useOverviewMutation(stationManagerService.updateStation, () => setSaved(true))

  const submit = (e: FormEvent) => {
    e.preventDefault()
    mutation.mutate({
      name: form.name.trim(),
      address: form.address.trim() || null,
      reference_point: form.reference_point.trim() || null,
      phone: form.phone.trim() || null,
      opening_hours: form.opening_hours.trim() || null,
    })
  }

  const field = (key: keyof typeof form, label: string, placeholder = '') => (
    <label className="block">
      <span className="text-sm font-medium text-dark">{label}</span>
      <input
        value={form[key]}
        placeholder={placeholder}
        onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
        className={`mt-1 ${input}`}
      />
    </label>
  )

  return (
    <form onSubmit={submit} className="mt-6 space-y-4 rounded-xl border border-gray/15 bg-white p-5">
      {field('name', 'Nome do posto')}
      {field('address', 'Morada')}
      {field('reference_point', 'Ponto de referência')}
      {field('phone', 'Telefone do posto', '923 000 000')}
      {field('opening_hours', 'Horário', 'Ex.: 07h às 19h')}
      <p className="text-xs text-gray">A província, o município e o estado do posto são definidos pela administração da KeruGás.</p>
      {mutation.isError && <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível guardar.')}</p>}
      {saved && <p className="text-sm text-success">Informações guardadas.</p>}
      <button disabled={mutation.isPending || form.name.trim() === ''} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
        {mutation.isPending ? 'A guardar...' : 'Guardar informações'}
      </button>

      {data.managers.length > 0 && (
        <div className="border-t border-gray/10 pt-4">
          <p className="text-sm font-medium text-dark">Admins deste posto</p>
          <ul className="mt-1 text-sm text-gray">
            {data.managers.map((m) => <li key={m.id}>{m.name} · {m.phone}</li>)}
          </ul>
        </div>
      )}
    </form>
  )
}

function OperatorsPanel({ data }: { data: StationManagerOverview }) {
  const [phone, setPhone] = useState('')
  const add = useOverviewMutation(stationManagerService.addOperator, () => setPhone(''))
  const remove = useOverviewMutation(stationManagerService.removeOperator)

  return (
    <div className="mt-6 space-y-4">
      <form
        onSubmit={(e) => { e.preventDefault(); if (phone.trim()) add.mutate(phone.trim()) }}
        className="rounded-xl border border-gray/15 bg-white p-5"
      >
        <p className="flex items-center gap-1.5 font-semibold text-dark"><UserPlus size={16} /> Promover a operador</p>
        <p className="mt-1 text-xs text-gray">
          A pessoa tem de ter conta na KeruGás e ser do município de {data.station.municipality}. Escreva o telemóvel com que se registou.
        </p>
        <div className="mt-3 flex gap-2">
          <input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="923 000 000" inputMode="tel" aria-label="Telemóvel da pessoa a promover" className={input} />
          <button disabled={add.isPending || !phone.trim()} className="shrink-0 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Promover</button>
        </div>
        {add.isError && <p className="mt-2 text-sm text-danger">{getApiErrorMessage(add.error, 'Não foi possível promover.')}</p>}
      </form>

      <PeopleList
        people={data.operators}
        empty="Este posto ainda não tem operadores."
        actions={(p) => (
          <button
            disabled={remove.isPending}
            onClick={() => { if (window.confirm(`Retirar ${p.name} de operador? A conta volta a utilizador comum.`)) remove.mutate(p.id) }}
            className="rounded-lg border border-danger px-3 py-1.5 text-xs font-medium text-danger disabled:opacity-50"
          >
            Retirar
          </button>
        )}
      />
      {remove.isError && <p className="text-sm text-danger">{getApiErrorMessage(remove.error, 'Não foi possível retirar.')}</p>}
    </div>
  )
}

function DriversPanel({ data }: { data: StationManagerOverview }) {
  const [form, setForm] = useState({ name: '', phone: '', password: '' })
  const [newPassword, setNewPassword] = useState<{ id: number; value: string } | null>(null)
  const create = useOverviewMutation(stationManagerService.createDriver, () => setForm({ name: '', phone: '', password: '' }))
  const update = useOverviewMutation(({ id, payload }: { id: number; payload: Parameters<typeof stationManagerService.updateDriver>[1] }) => stationManagerService.updateDriver(id, payload), () => setNewPassword(null))
  const remove = useOverviewMutation(stationManagerService.removeDriver)

  const valid = form.name.trim() && form.phone.trim() && form.password.length >= 8

  return (
    <div className="mt-6 space-y-4">
      <form
        onSubmit={(e) => { e.preventDefault(); if (valid) create.mutate({ name: form.name.trim(), phone: form.phone.trim(), password: form.password }) }}
        className="space-y-3 rounded-xl border border-gray/15 bg-white p-5"
      >
        <p className="flex items-center gap-1.5 font-semibold text-dark"><Truck size={16} /> Novo condutor do posto</p>
        <p className="text-xs text-gray">O condutor entra na app com o telemóvel e a palavra-passe e só entrega pedidos aceites por este posto, em {data.station.municipality}.</p>
        <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} placeholder="Nome completo" aria-label="Nome do condutor" className={input} />
        <input value={form.phone} onChange={(e) => setForm((f) => ({ ...f, phone: e.target.value }))} placeholder="Telemóvel (923 000 000)" inputMode="tel" aria-label="Telemóvel do condutor" className={input} />
        <input value={form.password} onChange={(e) => setForm((f) => ({ ...f, password: e.target.value }))} type="password" placeholder="Palavra-passe (mínimo 8 caracteres)" aria-label="Palavra-passe do condutor" className={input} />
        {create.isError && <p className="text-sm text-danger">{getApiErrorMessage(create.error, 'Não foi possível criar o condutor.')}</p>}
        <button disabled={create.isPending || !valid} className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          {create.isPending ? 'A criar...' : 'Criar condutor'}
        </button>
      </form>

      <PeopleList
        people={data.drivers}
        empty="Este posto ainda não tem condutores."
        actions={(p) => (
          <div className="flex flex-wrap items-center gap-2">
            {newPassword?.id === p.id ? (
              <>
                <input
                  type="password"
                  autoFocus
                  value={newPassword.value}
                  onChange={(e) => setNewPassword({ id: p.id, value: e.target.value })}
                  placeholder="Nova palavra-passe"
                  aria-label={`Nova palavra-passe de ${p.name}`}
                  className="w-40 rounded-lg border border-gray/20 px-2 py-1.5 text-xs"
                />
                <button
                  disabled={update.isPending || newPassword.value.length < 8}
                  onClick={() => update.mutate({ id: p.id, payload: { password: newPassword.value } })}
                  className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-50"
                >
                  Guardar
                </button>
                <button onClick={() => setNewPassword(null)} className="text-xs text-gray">Cancelar</button>
              </>
            ) : (
              <button onClick={() => setNewPassword({ id: p.id, value: '' })} className="rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark">
                Nova palavra-passe
              </button>
            )}
            <button
              disabled={update.isPending}
              onClick={() => update.mutate({ id: p.id, payload: { status: p.status === 'ACTIVE' ? 'SUSPENDED' : 'ACTIVE' } })}
              className={`rounded-lg border px-3 py-1.5 text-xs font-medium disabled:opacity-50 ${p.status === 'ACTIVE' ? 'border-warning text-warning' : 'border-success text-success'}`}
            >
              {p.status === 'ACTIVE' ? 'Suspender' : 'Reativar'}
            </button>
            <button
              disabled={remove.isPending}
              onClick={() => { if (window.confirm(`Retirar ${p.name} dos condutores do posto? A conta passa a utilizador comum.`)) remove.mutate(p.id) }}
              className="rounded-lg border border-danger px-3 py-1.5 text-xs font-medium text-danger disabled:opacity-50"
            >
              Retirar
            </button>
          </div>
        )}
      />
      {(update.isError || remove.isError) && (
        <p className="text-sm text-danger">{getApiErrorMessage(update.error ?? remove.error, 'Não foi possível guardar.')}</p>
      )}
    </div>
  )
}

function PeopleList({ people, empty, actions }: { people: StationStaffPerson[]; empty: string; actions: (p: StationStaffPerson) => ReactNode }) {
  if (people.length === 0) {
    return <p className="rounded-xl border border-dashed border-gray/20 p-6 text-center text-sm text-gray">{empty}</p>
  }

  return (
    <ul className="space-y-2">
      {people.map((p) => (
        <li key={p.id} className="flex flex-col gap-2 rounded-xl border border-gray/15 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <p className="font-medium text-dark">
              {p.name}
              {p.status !== 'ACTIVE' && <span className="ml-2 rounded-full bg-warning/10 px-2 py-0.5 text-xs font-medium text-warning">Suspenso</span>}
            </p>
            <p className="text-sm text-gray">{p.phone}</p>
          </div>
          {actions(p)}
        </li>
      ))}
    </ul>
  )
}
