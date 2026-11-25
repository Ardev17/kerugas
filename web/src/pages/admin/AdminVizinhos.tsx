import { useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2, Pencil, Plus, Trash2, VolumeX } from 'lucide-react'
import { MessageActions } from '@/components/vizinhos/MessageActions'
import { MessageCard } from '@/components/vizinhos/MessageCard'
import { useAuth } from '@/hooks/useAuth'
import { getApiErrorMessage } from '@/lib/api'
import { vizinhosService } from '@/services/vizinhosService'
import { territoryService } from '@/services/territoryService'
import type { AuthUser } from '@/types'
import type { VzModerationMessage, VzOverview } from '@/types/vizinhos'

const MESSAGE_TABS = [
  { key: 'reported', label: 'Denunciadas' },
  { key: 'hidden', label: 'Em revisão' },
  { key: 'removed', label: 'Removidas' },
  { key: 'deleted', label: 'Apagadas pelo autor' },
  { key: 'all', label: 'Todas' },
] as const

type TabKey = (typeof MESSAGE_TABS)[number]['key'] | 'mutes' | 'stats' | 'neighborhoods' | 'words' | 'municipalities'

const REASONS = [
  { key: 'spam', label: 'Spam ou publicidade' }, { key: 'abuse', label: 'Insulto ou assédio' }, { key: 'scam', label: 'Burla ou engano' },
  { key: 'illegal', label: 'Conteúdo ilegal' }, { key: 'personal', label: 'Dados pessoais de terceiros' },
]

function maxMuteFor(role: AuthUser['role']): number | null {
  if (role === 'MODERATOR') return 24
  if (role === 'ADMIN') return 168
  if (role === 'SUPER_ADMIN' || role === 'PROVINCE_SUPER_ADMIN') return null
  return 0
}

/** Visão mínima para reutilizar as ações sobre uma mensagem no painel. */
function panelOverview(user: AuthUser): VzOverview {
  return {
    municipality: { id: 0, name: '', province: null, enabled: true },
    neighborhoods: [], topics: [], report_reasons: REASONS, emojis: [],
    me: {
      accepted_rules: true, neighborhood_id: null, can_write: false, writes_as_team: false, muted: false, muted_until: null,
      is_staff: true, can_administer: user.role !== 'MODERATOR', is_super: user.role === 'SUPER_ADMIN', max_mute_hours: maxMuteFor(user.role),
    },
    limits: { max_body: 1000, edit_minutes: 15, max_mentions: 5 },
    switch_municipalities: [], presence: { online: 0, active_today: 0 },
  }
}

export default function AdminVizinhos() {
  const { user } = useAuth()
  const [tab, setTab] = useState<TabKey>('reported')
  if (!user) return null

  const isAdminUp = user.role !== 'MODERATOR'
  const isSuper = user.role === 'SUPER_ADMIN'
  const extra: Array<{ key: TabKey; label: string }> = [{ key: 'mutes', label: 'Silenciados' }]
  if (isAdminUp) extra.push({ key: 'stats', label: 'Estatísticas' }, { key: 'neighborhoods', label: 'Bairros' })
  if (isSuper) extra.push({ key: 'words', label: 'Palavras proibidas' }, { key: 'municipalities', label: 'Ligar/desligar' })

  return (
    <div>
      <h1 className="text-2xl font-bold text-dark">Vizinhos KeruGás</h1>
      <p className="mt-1 text-sm text-gray">
        {user.role === 'MODERATOR' ? 'Modera as conversas de todo o país.' : user.role === 'ADMIN' || user.role === 'PROVINCE_SUPER_ADMIN' ? 'Modera as conversas da sua província.' : 'Modera as conversas de todo o país e gere as regras.'}
      </p>

      <div role="tablist" aria-label="Secções" className="mt-4 flex flex-wrap gap-2">
        {[...MESSAGE_TABS, ...extra].map((t) => (
          <button key={t.key} role="tab" aria-selected={tab === t.key} onClick={() => setTab(t.key)} className={`rounded-full border px-3 py-1.5 text-sm font-medium ${tab === t.key ? 'border-primary bg-primary text-white' : 'border-gray/20 bg-white text-dark'}`}>
            {t.label}
          </button>
        ))}
      </div>

      <div className="mt-5">
        {MESSAGE_TABS.some((t) => t.key === tab) && <MessagesTab key={tab} tab={tab} user={user} />}
        {tab === 'mutes' && <MutesTab />}
        {tab === 'stats' && <StatsTab />}
        {tab === 'neighborhoods' && <NeighborhoodsTab user={user} />}
        {tab === 'words' && <WordsTab />}
        {tab === 'municipalities' && <MunicipalitiesTab />}
      </div>
    </div>
  )
}

function MessagesTab({ tab, user }: { tab: string; user: AuthUser }) {
  const queryClient = useQueryClient()
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<VzModerationMessage | null>(null)
  const overview = useMemo(() => panelOverview(user), [user])
  const query = useQuery({ queryKey: ['vz-mod', tab, page], queryFn: () => vizinhosService.moderationList(tab, page) })

  const sections = useMemo(() => {
    const map = new Map<string, Map<string, VzModerationMessage[]>>()
    for (const m of query.data?.data ?? []) {
      const p = m.province ?? 'Sem província'
      const c = m.municipality ?? 'Sem município'
      if (!map.has(p)) map.set(p, new Map())
      const inner = map.get(p)!
      inner.set(c, [...(inner.get(c) ?? []), m])
    }
    return [...map.entries()]
  }, [query.data])

  if (query.isLoading) return <Loader2 className="animate-spin text-gray" size={22} aria-label="A carregar" />
  if (query.isError) return <p role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger">{getApiErrorMessage(query.error, 'Não foi possível carregar.')}</p>
  if (!query.data || query.data.data.length === 0) return <p className="rounded-2xl border border-dashed border-gray/30 p-8 text-center text-sm text-gray">Nada por aqui. 🎉</p>

  return (
    <div className="space-y-6">
      {sections.map(([province, municipalities]) => (
        <section key={province} aria-label={province}>
          <h2 className="text-sm font-bold uppercase tracking-wide text-gray">{province}</h2>
          {[...municipalities.entries()].map(([municipality, items]) => (
            <div key={municipality} className="mt-3">
              <h3 className="mb-2 text-sm font-semibold text-dark">{municipality} <span className="font-normal text-gray">({items.length})</span></h3>
              <ul className="space-y-2">
                {items.map((m) => (
                  <MessageCard
                    key={m.id}
                    message={{ ...m, can_edit: false, can_report: false, can_delete: false }}
                    emojis={[]}
                    highlighted={false}
                    canReact={false}
                    onReply={() => undefined}
                    onMore={() => setSelected(m)}
                    onReact={() => undefined}
                    onJump={() => undefined}
                    onImage={() => undefined}
                  />
                ))}
              </ul>
            </div>
          ))}
        </section>
      ))}

      <div className="flex items-center justify-between">
        <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-full border border-gray/20 px-4 py-2 text-sm disabled:opacity-40">Anterior</button>
        <span className="text-xs text-gray">Página {query.data.meta.current_page} de {query.data.meta.last_page}</span>
        <button type="button" disabled={page >= query.data.meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-full border border-gray/20 px-4 py-2 text-sm disabled:opacity-40">Seguinte</button>
      </div>

      {selected && (
        <MessageActions
          message={{ ...selected, can_edit: false, can_report: false, can_delete: false }}
          overview={overview}
          onClose={() => setSelected(null)}
          onChanged={() => void queryClient.invalidateQueries({ queryKey: ['vz-mod'] })}
        />
      )}
    </div>
  )
}

function MutesTab() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['vz-mutes'], queryFn: vizinhosService.modMutes })
  const unmute = useMutation({ mutationFn: vizinhosService.modUnmute, onSuccess: () => queryClient.invalidateQueries({ queryKey: ['vz-mutes'] }) })

  if (query.isLoading) return <Loader2 className="animate-spin text-gray" size={22} />
  return (
    <ul className="space-y-2">
      {query.data?.map((m) => (
        <li key={m.user_id} className="flex items-center gap-3 rounded-xl border border-gray/15 bg-white p-3">
          <VolumeX size={18} className="shrink-0 text-danger" aria-hidden="true" />
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-dark">{m.name ?? `Utilizador ${m.user_id}`}</p>
            <p className="text-xs text-gray">{m.permanent ? 'Permanente' : `Até ${new Date(m.expires_at!).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })}`}{m.reason ? ` · ${m.reason}` : ''}</p>
          </div>
          <button type="button" onClick={() => unmute.mutate(m.user_id)} disabled={unmute.isPending} className="rounded-full border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark">Levantar</button>
        </li>
      ))}
      {query.data?.length === 0 && <li className="rounded-2xl border border-dashed border-gray/30 p-8 text-center text-sm text-gray">Ninguém silenciado.</li>}
    </ul>
  )
}

function StatsTab() {
  const query = useQuery({ queryKey: ['vz-stats'], queryFn: vizinhosService.modStats })
  if (query.isLoading) return <Loader2 className="animate-spin text-gray" size={22} />
  const s = query.data
  if (!s) return null
  const max = Math.max(1, ...s.per_day.map((d) => d.count))
  return (
    <div className="space-y-5">
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[['Mensagens', s.total], ['Últimos 7 dias', s.last_7_days], ['Denúncias abertas', s.open_reports], ['Removidas', s.removed]].map(([label, value]) => (
          <div key={label as string} className="rounded-xl border border-gray/15 bg-white p-3">
            <dt className="text-xs text-gray">{label}</dt>
            <dd className="text-2xl font-bold tabular-nums text-dark">{value}</dd>
          </div>
        ))}
      </dl>
      <section aria-label="Mensagens por dia" className="rounded-xl border border-gray/15 bg-white p-4">
        <h2 className="text-sm font-semibold text-dark">Últimos 14 dias</h2>
        <div className="mt-3 flex h-28 items-end gap-1" role="img" aria-label="Mensagens por dia nos últimos 14 dias">
          {s.per_day.map((d) => (
            <div key={d.day} title={`${d.day}: ${d.count}`} className="flex-1 rounded-t bg-primary/70" style={{ height: `${Math.max(4, (d.count / max) * 100)}%` }} />
          ))}
        </div>
      </section>
      <section aria-label="Municípios mais ativos" className="rounded-xl border border-gray/15 bg-white p-4">
        <h2 className="text-sm font-semibold text-dark">Municípios mais ativos (30 dias)</h2>
        <ol className="mt-2 space-y-1 text-sm">
          {s.top_municipalities.map((m) => <li key={m.name} className="flex justify-between"><span>{m.name}</span><span className="tabular-nums text-gray">{m.count}</span></li>)}
          {s.top_municipalities.length === 0 && <li className="text-gray">Sem atividade ainda.</li>}
        </ol>
      </section>
    </div>
  )
}

function NeighborhoodsTab({ user }: { user: AuthUser }) {
  const queryClient = useQueryClient()
  const [provinceId, setProvinceId] = useState<number | ''>(user.province_id ?? '')
  const [municipalityId, setMunicipalityId] = useState<number | ''>('')
  const [name, setName] = useState('')
  const [error, setError] = useState<string | null>(null)

  const provinces = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalities = useQuery({ queryKey: ['municipalities', provinceId], queryFn: () => territoryService.listMunicipalities(Number(provinceId)), enabled: !!provinceId })
  const list = useQuery({ queryKey: ['neighborhoods', municipalityId], queryFn: () => vizinhosService.neighborhoods(Number(municipalityId)), enabled: !!municipalityId })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['neighborhoods'] })
  const onError = (e: unknown) => setError(getApiErrorMessage(e, 'Não foi possível guardar.'))
  const create = useMutation({ mutationFn: () => vizinhosService.createNeighborhood(Number(municipalityId), name.trim()), onSuccess: () => { setName(''); setError(null); void refresh() }, onError })
  const rename = useMutation({ mutationFn: ({ id, value }: { id: number; value: string }) => vizinhosService.renameNeighborhood(id, value), onSuccess: () => { setError(null); void refresh() }, onError })
  const remove = useMutation({ mutationFn: vizinhosService.deleteNeighborhood, onSuccess: () => { setError(null); void refresh() }, onError })
  const locked = user.role === 'ADMIN' || user.role === 'PROVINCE_SUPER_ADMIN'

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-xs text-gray">Província
          <select value={provinceId} disabled={locked} onChange={(e) => { setProvinceId(e.target.value ? Number(e.target.value) : ''); setMunicipalityId('') }} className="mt-1 w-full rounded-xl border border-gray/20 bg-white px-3 py-2 text-sm text-dark">
            <option value="">Selecione…</option>
            {provinces.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
          </select>
        </label>
        <label className="text-xs text-gray">Município
          <select value={municipalityId} disabled={!provinceId} onChange={(e) => setMunicipalityId(e.target.value ? Number(e.target.value) : '')} className="mt-1 w-full rounded-xl border border-gray/20 bg-white px-3 py-2 text-sm text-dark">
            <option value="">Selecione…</option>
            {municipalities.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
          </select>
        </label>
      </div>

      {municipalityId && (
        <>
          <form onSubmit={(e) => { e.preventDefault(); if (name.trim().length >= 2) create.mutate() }} className="flex gap-2">
            <input value={name} onChange={(e) => setName(e.target.value)} maxLength={80} placeholder="Novo bairro" aria-label="Nome do novo bairro" className="min-w-0 flex-1 rounded-xl border border-gray/20 px-3 py-2 text-sm" />
            <button type="submit" disabled={create.isPending || name.trim().length < 2} className="flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><Plus size={15} /> Adicionar</button>
          </form>
          {error && <p role="alert" className="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>}
          <ul className="divide-y divide-gray/10 rounded-xl border border-gray/15 bg-white">
            {list.data?.map((n) => (
              <li key={n.id} className="flex items-center gap-2 px-3 py-2.5">
                <span className="min-w-0 flex-1 truncate text-sm text-dark">{n.name}</span>
                <button type="button" aria-label={`Renomear ${n.name}`} onClick={() => { const value = window.prompt('Novo nome do bairro', n.name); if (value && value.trim().length >= 2) rename.mutate({ id: n.id, value: value.trim() }) }} className="rounded-full p-1.5 text-gray hover:bg-light"><Pencil size={15} /></button>
                <button type="button" aria-label={`Apagar ${n.name}`} onClick={() => { if (window.confirm(`Apagar o bairro "${n.name}"? As pessoas e mensagens ficam sem bairro.`)) remove.mutate(n.id) }} className="rounded-full p-1.5 text-danger hover:bg-danger/10"><Trash2 size={15} /></button>
              </li>
            ))}
            {list.data?.length === 0 && <li className="px-3 py-6 text-center text-sm text-gray">Ainda não há bairros neste município.</li>}
          </ul>
        </>
      )}
    </div>
  )
}

function WordsTab() {
  const queryClient = useQueryClient()
  const [word, setWord] = useState('')
  const query = useQuery({ queryKey: ['vz-words'], queryFn: vizinhosService.bannedWords })
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['vz-words'] })
  const add = useMutation({ mutationFn: () => vizinhosService.addBannedWord(word.trim()), onSuccess: () => { setWord(''); void refresh() } })
  const remove = useMutation({ mutationFn: vizinhosService.removeBannedWord, onSuccess: () => void refresh() })

  return (
    <div className="space-y-3">
      <p className="text-xs text-gray">Mensagens com estas palavras (ignorando acentos e maiúsculas) não são enviadas.</p>
      <form onSubmit={(e) => { e.preventDefault(); if (word.trim().length >= 2) add.mutate() }} className="flex gap-2">
        <input value={word} onChange={(e) => setWord(e.target.value)} maxLength={60} placeholder="Palavra ou expressão" aria-label="Palavra proibida" className="min-w-0 flex-1 rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        <button type="submit" disabled={add.isPending || word.trim().length < 2} className="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Adicionar</button>
      </form>
      <ul className="flex flex-wrap gap-2">
        {query.data?.map((w) => (
          <li key={w.id} className="flex items-center gap-1.5 rounded-full border border-gray/20 bg-white py-1 pl-3 pr-1 text-sm text-dark">
            {w.word}
            <button type="button" aria-label={`Remover ${w.word}`} onClick={() => remove.mutate(w.id)} className="rounded-full p-1 text-gray hover:bg-light"><Trash2 size={13} /></button>
          </li>
        ))}
      </ul>
    </div>
  )
}

function MunicipalitiesTab() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['vz-municipalities'], queryFn: vizinhosService.municipalitiesToggle })
  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: number; enabled: boolean }) => vizinhosService.setMunicipalityEnabled(id, enabled),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['vz-municipalities'] }),
  })

  return (
    <ul className="divide-y divide-gray/10 rounded-xl border border-gray/15 bg-white">
      {query.data?.map((m) => (
        <li key={m.id} className="flex items-center gap-3 px-3 py-2.5">
          <span className="min-w-0 flex-1 truncate text-sm text-dark">{m.name} <span className="text-xs text-gray">· {m.province}</span></span>
          <button role="switch" aria-checked={m.enabled} aria-label={`Vizinhos em ${m.name}`} onClick={() => toggle.mutate({ id: m.id, enabled: !m.enabled })} className={`relative h-6 w-11 rounded-full transition-colors ${m.enabled ? 'bg-primary' : 'bg-gray/30'}`}>
            <span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white transition-all ${m.enabled ? 'left-[22px]' : 'left-0.5'}`} />
          </button>
        </li>
      ))}
    </ul>
  )
}
