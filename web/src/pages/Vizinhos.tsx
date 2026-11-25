import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { EmptyChatArt, MegaphoneArt, NeighborhoodWelcomeArt } from '@/components/illustrations/Neighborhood'
import { EmptyArt } from '@/components/illustrations/Admin'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { ArrowDown, ArrowUp, ChevronLeft, ChevronRight, Filter, Loader2, MapPin, Search, ShieldCheck, VolumeX, X } from 'lucide-react'
import { AvatarViewer } from '@/components/AvatarViewer'
import { Composer } from '@/components/vizinhos/Composer'
import { MessageActions } from '@/components/vizinhos/MessageActions'
import { MessageCard } from '@/components/vizinhos/MessageCard'
import { PresenceSheet } from '@/components/vizinhos/PresenceSheet'
import { getApiErrorMessage } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import { dayLabel, locationLive, withLocationEnded } from '@/lib/vizinhos'
import { vizinhosService, type VzCursor } from '@/services/vizinhosService'
import type { VzFilters, VzMessage, VzWindow } from '@/types/vizinhos'

const RULES = [
  'Trate todos com respeito,somos vizinhos.',
  'Sem insultos, ameaças nem discriminação.',
  'Sem publicidade, burlas ou spam.',
  'Não partilhe telemóveis, links nem dados de outras pessoas.',
  'Denuncie o que estiver errado,a nossa equipa analisa.',
]

function errorStatus(e: unknown): { status?: number; code?: string } {
  const r = (e as { response?: { status?: number; data?: { code?: string } } })?.response
  return { status: r?.status, code: r?.data?.code }
}

/**
 * Vizinhos KeruGás (Outubro 2026),a comunidade de cada município.
 * Independente dos comentários dos postos e do chat dos condutores.
 * A conversa mostra 30 mensagens de cada vez, com botões para as anteriores
 * e as seguintes; uma menção abre diretamente na mensagem (?mensagem=ID).
 */
export default function Vizinhos() {
  const queryClient = useQueryClient()
  const [params, setParams] = useSearchParams()
  const municipalityParam = Number(params.get('municipio')) || undefined
  const targetId = Number(params.get('mensagem')) || undefined

  const [filters, setFilters] = useState<VzFilters>({})
  const [search, setSearch] = useState('')
  const [showFilters, setShowFilters] = useState(false)
  const [win, setWin] = useState<VzWindow | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [replyTo, setReplyTo] = useState<VzMessage | null>(null)
  const [actionsFor, setActionsFor] = useState<VzMessage | null>(null)
  const [image, setImage] = useState<string | null>(null)
  const [presenceOpen, setPresenceOpen] = useState(false)
  const [highlight, setHighlight] = useState<number | null>(null)
  const [newCount, setNewCount] = useState(0)
  const listTop = useRef<HTMLDivElement>(null)
  // Fim da conversa (depois da caixa de escrever). No site há um rodapé
  // grande por baixo: rolar para o fundo da PÁGINA mostrava só o rodapé —
  // rola-se até aqui.
  const listEnd = useRef<HTMLDivElement>(null)
  const scrollToEnd = (behavior: ScrollBehavior = 'auto') => listEnd.current?.scrollIntoView({ block: 'end', behavior })
  const scrollTo = useRef<'top' | 'bottom' | number | null>(null)
  const atLatest = useRef(true)

  const overviewQuery = useQuery({
    queryKey: ['vz-overview', municipalityParam],
    queryFn: () => vizinhosService.overview(municipalityParam),
    retry: false,
  })
  const overview = overviewQuery.data
  const municipalityId = overview?.municipality.id

  // Debounce da pesquisa por texto.
  useEffect(() => {
    const t = setTimeout(() => setFilters((f) => (f.q === (search.trim() || undefined) ? f : { ...f, q: search.trim() || undefined })), 400)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(
    async (cursor: VzCursor, where: 'top' | 'bottom' | number) => {
      if (!municipalityId) return
      setLoading(true)
      setLoadError(null)
      try {
        const result = await vizinhosService.messages(municipalityId, filters, cursor)
        scrollTo.current = where
        atLatest.current = !result.meta.has_newer
        setNewCount(0)
        setWin((prev) => ({ ...result, pinned: result.pinned ?? prev?.pinned }))
      } catch (e) {
        setLoadError(errorStatus(e).status === 404 ? 'Essa mensagem já não está disponível.' : getApiErrorMessage(e, 'Não foi possível carregar as mensagens.'))
      } finally {
        setLoading(false)
      }
    },
    [municipalityId, filters],
  )

  // Primeira carga (ou mudança de filtros/município): as mais recentes, ou à volta da mensagem pedida.
  useEffect(() => {
    if (!overview) return
    if (!overview.me.accepted_rules && !overview.me.writes_as_team) return // ainda no ecrã de boas-vindas
    if (targetId && !Object.values(filters).some(Boolean)) {
      void load({ around_id: targetId }, targetId).then(() => setHighlight(targetId))
    } else {
      void load({}, 'bottom')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [overview?.municipality.id, overview?.me.accepted_rules, filters, targetId])

  // Depois de desenhar a janela: rolar para onde deve.
  useEffect(() => {
    if (!win || scrollTo.current === null) return
    const where = scrollTo.current
    scrollTo.current = null
    requestAnimationFrame(() => {
      if (typeof where === 'number') document.getElementById(`vz-msg-${where}`)?.scrollIntoView({ block: 'center' })
      else if (where === 'top') listTop.current?.scrollIntoView({ block: 'start' })
      else scrollToEnd()
    })
  }, [win])

  useEffect(() => {
    if (highlight === null) return
    const t = setTimeout(() => setHighlight(null), 3500)
    return () => clearTimeout(t)
  }, [highlight])

  // Mensagens novas: tempo real (Reverb) + verificação periódica de segurança.
  const checkNew = useCallback(async () => {
    if (!municipalityId || !win?.meta.newest_id) return
    if (!atLatest.current) {
      setNewCount((n) => n || 1)
      return
    }
    try {
      const more = await vizinhosService.messages(municipalityId, filters, { after_id: win.meta.newest_id })
      if (more.data.length === 0) return
      const end = listEnd.current?.getBoundingClientRect().bottom
      const nearBottom = end === undefined || end <= window.innerHeight + 160
      setWin((prev) => {
        if (!prev) return prev
        const merged = [...prev.data, ...more.data.filter((m) => !prev.data.some((x) => x.id === m.id))]
        const trimmed = merged.slice(-90)
        return { ...prev, data: trimmed, meta: { ...more.meta, has_older: prev.meta.has_older || trimmed.length < merged.length, oldest_id: trimmed[0]?.id ?? null } }
      })
      if (nearBottom) scrollTo.current = 'bottom'
      else setNewCount((n) => n + more.data.length)
    } catch {
      // melhor esforço: tenta de novo no próximo ciclo
    }
  }, [municipalityId, filters, win?.meta.newest_id])

  const checkRef = useRef(checkNew)
  checkRef.current = checkNew

  // Partilhas de localização (Outubro 2026): uma que acabou (expirou ou a pessoa
  // parou) sai do ecrã de toda a gente,pelo tempo real e, como rede de
  // segurança, perguntando à API quais continuam ativas.
  const endLocations = useCallback((ended: (id: number) => boolean) => {
    setWin((prev) => {
      if (!prev || !prev.data.some((m) => m.location?.active && ended(m.id))) return prev
      return { ...prev, data: prev.data.map((m) => (m.location?.active && ended(m.id) ? withLocationEnded(m) : m)) }
    })
  }, [])
  const checkLocations = useCallback(async () => {
    if (!municipalityId || !win?.data.some((m) => locationLive(m.location))) return
    try {
      const active = new Set(await vizinhosService.activeLocations(municipalityId))
      endLocations((id) => !active.has(id))
    } catch {
      // melhor esforço
    }
  }, [municipalityId, win?.data, endLocations])
  const locRef = useRef(checkLocations)
  locRef.current = checkLocations
  const [stopping, setStopping] = useState<number | null>(null)
  const realtime = getEcho() !== null

  useEffect(() => {
    if (!municipalityId) return
    const echo = getEcho()
    const channelName = `community.municipality.${municipalityId}`
    if (echo) {
      echo.private(channelName)
        .listen('.community.message_posted', () => void checkRef.current())
        .listen('.community.location_ended', (e: { id?: number }) => { if (e?.id) endLocations((id) => id === e.id) })
    }
    const timer = setInterval(() => { void checkRef.current(); void locRef.current() }, realtime ? 60_000 : 12_000)
    return () => {
      clearInterval(timer)
      if (echo) echo.leaveChannel(`private-${channelName}`)
    }
  }, [municipalityId, realtime, endLocations])

  const acceptMutation = useMutation({
    mutationFn: vizinhosService.acceptRules,
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['vz-overview'] }),
  })

  const patch = useCallback((updated?: VzMessage) => {
    if (!updated) return
    setWin((prev) => (prev ? { ...prev, data: prev.data.map((m) => (m.id === updated.id ? updated : m)) } : prev))
  }, [])

  // Depois de uma ação: volta a pedir a janela à volta dessa mensagem (mantém o sítio onde a pessoa está).
  const refreshNear = useCallback(async (id: number) => {
    if (!municipalityId) return
    try {
      const r = await vizinhosService.messages(municipalityId, filters, { around_id: id })
      scrollTo.current = id
      atLatest.current = !r.meta.has_newer
      setWin((prev) => ({ ...r, pinned: r.pinned ?? prev?.pinned }))
    } catch {
      void load({}, 'bottom')
    }
  }, [municipalityId, filters, load])

  const reload = useCallback(() => {
    void load({}, 'bottom')
    void queryClient.invalidateQueries({ queryKey: ['vz-overview'] })
  }, [load, queryClient])

  const react = useCallback(async (m: VzMessage, emoji: string | null) => {
    try {
      patch(await vizinhosService.react(m.id, emoji))
    } catch (e) {
      setNotice(getApiErrorMessage(e, 'Não foi possível reagir.'))
    }
  }, [patch])

  const stopLocation = useCallback(async (m: VzMessage) => {
    setStopping(m.id)
    try {
      patch(await vizinhosService.stopLocation(m.id))
    } catch (e) {
      setNotice(getApiErrorMessage(e, 'Não foi possível parar a partilha. Tente de novo.'))
    } finally {
      setStopping(null)
    }
  }, [patch])

  const jump = useCallback(async (id: number) => {
    if (document.getElementById(`vz-msg-${id}`)) {
      document.getElementById(`vz-msg-${id}`)?.scrollIntoView({ block: 'center' })
      setHighlight(id)
      return
    }
    await load({ around_id: id }, id)
    setHighlight(id)
  }, [load])

  const topicLabel = useMemo(() => Object.fromEntries((overview?.topics ?? []).map((t) => [t.key, t.label])), [overview?.topics])

  // ── Estados de entrada ──────────────────────────────────────────────

  if (overviewQuery.isLoading) {
    return <div className="flex justify-center py-24 text-gray"><Loader2 className="animate-spin" size={26} aria-label="A carregar" /></div>
  }

  if (overviewQuery.isError) {
    const { code } = errorStatus(overviewQuery.error)
    return (
      <div className="mx-auto max-w-lg px-4 py-10 text-center">
        <MapPin size={40} className="mx-auto text-primary" aria-hidden="true" />
        <h1 className="mt-3 text-xl font-bold text-dark">Vizinhos KeruGás</h1>
        <p className="mt-2 text-sm text-gray">
          {code === 'needs_municipality'
            ? 'Para entrar na comunidade do seu município, diga-nos onde vive.'
            : getApiErrorMessage(overviewQuery.error, 'Não foi possível abrir a comunidade.')}
        </p>
        {code === 'needs_municipality' && <Link to="/perfil" className="mt-4 inline-block rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-white">Escolher o meu município</Link>}
      </div>
    )
  }

  if (!overview || !municipalityId) return null

  const me = overview.me
  const mustAccept = !me.accepted_rules && !me.writes_as_team

  if (mustAccept) {
    return (
      <div className="mx-auto max-w-lg px-4 py-8">
        <div className="fx-slide-next overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-secondary p-3 pb-6 text-white">
          <NeighborhoodWelcomeArt className="block w-full rounded-2xl" />
          <div className="px-3">
          <h1 className="mt-3 text-2xl font-bold">Bem-vindo aos Vizinhos KeruGás!</h1>
          <p className="mt-1 text-sm text-white/90">A conversa de quem vive em {overview.municipality.name}: gás, postos, entregas e o dia a dia do bairro.</p>
          </div>
        </div>
        <h2 className="mt-5 text-sm font-semibold text-dark">As regras da casa</h2>
        <ol className="mt-2 space-y-2">
          {RULES.map((r, i) => (
            <li key={r} className="flex gap-3 rounded-xl border border-gray/15 bg-white p-3 text-sm text-dark">
              <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{i + 1}</span>
              {r}
            </li>
          ))}
        </ol>
        <button type="button" onClick={() => acceptMutation.mutate()} disabled={acceptMutation.isPending} className="mt-5 w-full rounded-full bg-primary py-3 text-sm font-semibold text-white disabled:opacity-60">
          {acceptMutation.isPending ? 'A entrar…' : 'Aceito e quero entrar'}
        </button>
        {acceptMutation.isError && <p role="alert" className="mt-2 text-center text-xs text-danger">Não foi possível continuar. Tente de novo.</p>}
      </div>
    )
  }

  const messages = win?.data ?? []
  const filtersActive = Object.values(filters).some(Boolean)
  const grouped: Array<{ day: string; items: VzMessage[] }> = []
  for (const m of messages) {
    const day = dayLabel(m.created_at)
    const last = grouped[grouped.length - 1]
    if (last && last.day === day) last.items.push(m)
    else grouped.push({ day, items: [m] })
  }

  const setMunicipality = (id: number) => {
    const next = new URLSearchParams(params)
    next.set('municipio', String(id))
    next.delete('mensagem')
    setWin(null)
    setFilters({})
    setSearch('')
    setReplyTo(null)
    setParams(next)
  }

  const toggle = (key: 'mine' | 'mentions_me' | 'staff_only') => setFilters((f) => ({ ...f, [key]: f[key] ? undefined : true }))
  const chip = (active: boolean) => `rounded-full border px-3 py-1.5 text-xs font-medium ${active ? 'border-primary bg-primary text-white' : 'border-gray/20 bg-white text-dark'}`

  return (
    <div className="mx-auto max-w-2xl px-4 py-5">
      <header className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-2xl font-extrabold text-dark">Vizinhos <span className="fx-gradient-text">KeruGás</span></h1>
          <p className="flex items-center gap-1 text-sm text-gray"><MapPin size={14} aria-hidden="true" /> {overview.municipality.name}{overview.municipality.province ? `, ${overview.municipality.province}` : ''}</p>
        </div>
        <button type="button" onClick={() => setPresenceOpen(true)} className="flex shrink-0 items-center gap-1.5 rounded-full border border-gray/20 bg-white px-3 py-1.5 text-xs font-medium text-dark">
          <span aria-hidden="true" className="fx-live-dot" /> {overview.presence.online} online · {overview.presence.active_today} hoje
        </button>
      </header>

      {overview.switch_municipalities.length > 0 && (
        <label className="mt-3 block text-xs text-gray">
          Município
          <select value={municipalityId} onChange={(e) => setMunicipality(Number(e.target.value))} className="mt-1 w-full rounded-xl border border-gray/20 bg-white px-3 py-2 text-sm text-dark">
            {Object.entries(
              overview.switch_municipalities.reduce<Record<string, typeof overview.switch_municipalities>>((acc, m) => {
                const key = m.province ?? 'Sem província'
                ;(acc[key] ??= []).push(m)
                return acc
              }, {}),
            ).map(([province, list]) => (
              <optgroup key={province} label={province}>
                {list.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
              </optgroup>
            ))}
          </select>
        </label>
      )}

      {me.is_staff && (
        <Link to="/admin/vizinhos" className="mt-3 flex items-center gap-2 rounded-xl bg-primary/10 px-3 py-2 text-xs font-medium text-primary">
          <ShieldCheck size={15} aria-hidden="true" /> Painel de moderação dos Vizinhos
        </Link>
      )}

      {/* Avisos fixados */}
      {win?.pinned && win.pinned.length > 0 && (
        <section aria-label="Avisos fixados" className="mt-3 space-y-2">
          <p className="flex items-center gap-2 text-xs font-bold uppercase tracking-wide text-amber-700">
            <MegaphoneArt size={18} /> Avisos da equipa
          </p>
          {win.pinned.map((p) => (
            <button key={p.id} type="button" onClick={() => void jump(p.id)} className="block w-full rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-left text-sm text-dark">
              <span className="text-[11px] font-bold uppercase tracking-wide text-amber-700">📌 Aviso · {p.author?.name}</span>
              <span className="line-clamp-2 block">{(p.body ?? '').replace(/@\[(\d+)\]/g, (_m, id: string) => `@${p.mentions[id] ?? 'vizinho'}`)}</span>
            </button>
          ))}
        </section>
      )}

      {/* Filtros */}
      <div className="mt-3 flex items-center gap-2">
        <div className="relative flex-1">
          <Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-gray" aria-hidden="true" />
          <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Procurar nas conversas…" aria-label="Procurar nas conversas" className="w-full rounded-full border border-gray/20 bg-white py-2 pl-9 pr-3 text-sm outline-none focus:border-primary" />
        </div>
        <button type="button" onClick={() => setShowFilters((s) => !s)} aria-expanded={showFilters} className={`flex items-center gap-1.5 ${chip(showFilters || filtersActive)}`}>
          <Filter size={14} aria-hidden="true" /> Filtros
        </button>
      </div>
      {showFilters && (
        <div className="mt-2 space-y-3 rounded-2xl border border-gray/15 bg-white p-3">
          <div className="flex flex-wrap gap-2">
            <button type="button" aria-pressed={!!filters.mentions_me} onClick={() => toggle('mentions_me')} className={chip(!!filters.mentions_me)}>Menções a mim</button>
            <button type="button" aria-pressed={!!filters.mine} onClick={() => toggle('mine')} className={chip(!!filters.mine)}>As minhas</button>
            <button type="button" aria-pressed={!!filters.staff_only} onClick={() => toggle('staff_only')} className={chip(!!filters.staff_only)}>Só da equipa</button>
          </div>
          <div className="flex flex-wrap gap-2">
            {overview.topics.map((t) => (
              <button key={t.key} type="button" aria-pressed={filters.topic === t.key} onClick={() => setFilters((f) => ({ ...f, topic: f.topic === t.key ? undefined : t.key }))} className={chip(filters.topic === t.key)}>{t.label}</button>
            ))}
          </div>
          {overview.neighborhoods.length > 0 && (
            <label className="block text-xs text-gray">
              Bairro
              <select value={filters.neighborhood_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, neighborhood_id: e.target.value ? Number(e.target.value) : undefined }))} className="mt-1 w-full rounded-xl border border-gray/20 bg-white px-3 py-2 text-sm text-dark">
                <option value="">Todos os bairros</option>
                {overview.neighborhoods.map((n) => <option key={n.id} value={n.id}>{n.name}</option>)}
              </select>
            </label>
          )}
          {filtersActive && (
            <button type="button" onClick={() => { setFilters({}); setSearch('') }} className="flex items-center gap-1 text-xs font-medium text-primary"><X size={13} /> Limpar filtros</button>
          )}
        </div>
      )}

      {/* Conversa */}
      <div ref={listTop} className="mt-4 scroll-mt-20" />
      {win?.meta.has_older && (
        <button type="button" disabled={loading} onClick={() => void load({ before_id: win.meta.oldest_id ?? undefined }, 'bottom')} className="mb-3 flex w-full items-center justify-center gap-1.5 rounded-full border border-gray/20 bg-white py-2.5 text-sm font-medium text-dark disabled:opacity-60">
          <ChevronLeft size={15} className="rotate-90" aria-hidden="true" /> <ArrowUp size={14} aria-hidden="true" /> Ver mensagens anteriores
        </button>
      )}

      {loading && !win && <div className="flex justify-center py-12 text-gray"><Loader2 className="animate-spin" size={22} aria-label="A carregar" /></div>}
      {loadError && <p role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger">{loadError}</p>}

      {win && messages.length === 0 && !loading && (
        <div className="rounded-3xl border border-dashed border-gray/30 bg-white p-8 text-center">
          {filtersActive ? <EmptyArt className="mx-auto h-24 w-auto" /> : <EmptyChatArt className="mx-auto h-28 w-auto" />}
          <p className="mt-2 font-semibold text-dark">{filtersActive ? 'Nada encontrado com estes filtros' : 'A conversa ainda não começou'}</p>
          <p className="mt-1 text-sm text-gray">{filtersActive ? 'Experimente limpar os filtros.' : 'Seja o primeiro a dizer olá aos seus vizinhos!'}</p>
        </div>
      )}

      <div className={loading && win ? 'opacity-60 transition-opacity' : ''}>
        {grouped.map((g) => (
          <section key={g.day} aria-label={g.day} className="mb-2">
            <p className="sticky top-14 z-10 mx-auto my-2 w-fit rounded-full bg-light px-3 py-1 text-[11px] font-semibold text-gray">{g.day}</p>
            <ul className="space-y-2">
              {g.items.map((m) => (
                <MessageCard
                  key={m.id}
                  message={m}
                  emojis={overview.emojis}
                  topicLabel={m.topic ? topicLabel[m.topic] : undefined}
                  highlighted={highlight === m.id}
                  canReact={me.can_write}
                  onReply={(x) => { setReplyTo(x); document.getElementById('vz-composer')?.scrollIntoView({ block: 'end' }) }}
                  onMore={setActionsFor}
                  onReact={(x, e) => void react(x, e)}
                  onJump={(id) => void jump(id)}
                  onImage={setImage}
                  onStopLocation={(x) => void stopLocation(x)}
                  stoppingLocation={stopping === m.id}
                />
              ))}
            </ul>
          </section>
        ))}
      </div>

      {win?.meta.has_newer && (
        <div className="mt-3 flex flex-col gap-2">
          <button type="button" disabled={loading} onClick={() => void load({ after_id: win.meta.newest_id ?? undefined }, 'top')} className="flex w-full items-center justify-center gap-1.5 rounded-full border border-gray/20 bg-white py-2.5 text-sm font-medium text-dark disabled:opacity-60">
            Ver mensagens seguintes <ChevronRight size={15} className="rotate-90" aria-hidden="true" />
          </button>
          <button type="button" onClick={reload} className="flex w-full items-center justify-center gap-1.5 rounded-full bg-primary py-2.5 text-sm font-semibold text-white">
            <ArrowDown size={14} aria-hidden="true" /> Ir para as mais recentes
          </button>
        </div>
      )}

      {newCount > 0 && !win?.meta.has_newer && (
        <button type="button" onClick={() => { scrollTo.current = 'bottom'; setNewCount(0); scrollToEnd('smooth') }} className="fixed bottom-24 left-1/2 z-30 -translate-x-1/2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-white shadow-lg">
          {newCount} {newCount === 1 ? 'mensagem nova' : 'mensagens novas'} ↓
        </button>
      )}

      {notice && <p role="status" className="mt-3 rounded-xl bg-light p-3 text-xs text-dark" onClick={() => setNotice(null)}>{notice}</p>}

      {/* Escrever */}
      <div id="vz-composer" className="sticky bottom-2 mt-4 scroll-mb-4">
        {!overview.municipality.enabled ? (
          <p className="rounded-2xl bg-light p-4 text-center text-sm text-gray">Os Vizinhos KeruGás estão em pausa neste município.</p>
        ) : me.muted ? (
          <p className="flex items-center gap-2 rounded-2xl bg-danger/10 p-4 text-sm text-danger">
            <VolumeX size={18} aria-hidden="true" />
            {me.muted_until ? `Está silenciado até ${new Date(me.muted_until).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })}. Pode ler, mas não escrever.` : 'Está silenciado: pode ler, mas não escrever.'}
          </p>
        ) : !me.can_write ? (
          <p className="rounded-2xl bg-light p-4 text-center text-sm text-gray">Está a ver {overview.municipality.name} só para leitura. Só pode escrever no seu município.</p>
        ) : (
          <Composer
            overview={overview}
            municipalityId={municipalityId}
            replyTo={replyTo}
            onCancelReply={() => setReplyTo(null)}
            onSent={(m) => {
              if (atLatest.current && !filtersActive) {
                setWin((prev) => (prev ? { ...prev, data: [...prev.data.filter((x) => x.id !== m.id), m], meta: { ...prev.meta, newest_id: m.id } } : prev))
                scrollTo.current = 'bottom'
              } else {
                reload()
              }
            }}
          />
        )}
      </div>
      {/* Fora da caixa de escrever (que é "sticky" e por isso parece sempre visível). */}
      <div ref={listEnd} className="scroll-mb-24" aria-hidden="true" />

      {actionsFor && (
        <MessageActions
          message={actionsFor}
          overview={overview}
          onClose={() => { setActionsFor(null); }}
          onChanged={(updated) => {
            if (updated) patch(updated)
            else void refreshNear(actionsFor.id)
          }}
        />
      )}
      {presenceOpen && <PresenceSheet municipalityId={municipalityId} onClose={() => setPresenceOpen(false)} />}
      {image && <AvatarViewer src={image} name="Foto da mensagem" shape="photo" onClose={() => setImage(null)} />}
    </div>
  )
}
