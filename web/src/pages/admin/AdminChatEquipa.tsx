import { useCallback, useEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSearchParams } from 'react-router-dom'
import { ArrowDown, ArrowUp, Filter, Loader2, Search, VolumeX, X } from 'lucide-react'
import { AvatarViewer } from '@/components/AvatarViewer'
import { PinnedNoticeArt, RoleIcon, StaffEmptyArt, StaffHeroArt, StaffLockArt, StaffMutedArt } from '@/components/illustrations/StaffChat'
import { StaffActions } from '@/components/staffchat/StaffActions'
import { StaffComposer } from '@/components/staffchat/StaffComposer'
import { StaffPresenceSheet } from '@/components/staffchat/StaffPresenceSheet'
import { MessageCard } from '@/components/vizinhos/MessageCard'
import { Sheet } from '@/components/vizinhos/Sheet'
import { getApiErrorMessage, api } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import { dayLabel } from '@/lib/vizinhos'
import { staffChatService, type StaffCursor } from '@/services/staffChatService'
import type { ApiSuccess } from '@/types'
import type { StaffFilters, StaffRole } from '@/types/staffChat'
import type { VzMessage, VzWindow } from '@/types/vizinhos'

interface MuteRow { user_id: number; name: string | null; reason: string | null; expires_at: string | null; permanent: boolean }

/** Lista de silenciados (só o Admin Geral) com botão para levantar. */
function MutesSheet({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient()
  const list = useQuery({
    queryKey: ['sc-mutes'],
    queryFn: async () => (await api.get<ApiSuccess<MuteRow[]>>('/staff-chat/mutes')).data.data,
  })
  const lift = useMutation({
    mutationFn: (id: number) => staffChatService.unmute(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['sc-mutes'] }),
  })
  return (
    <Sheet title="Silenciados" onClose={onClose}>
      {list.isLoading && <Loader2 className="animate-spin text-gray" size={18} />}
      <ul className="divide-y divide-gray/10">
        {list.data?.map((r) => (
          <li key={r.user_id} className="flex items-center gap-3 py-2.5 text-sm">
            <span className="min-w-0 flex-1">
              <span className="block truncate font-medium text-dark">{r.name ?? '—'}</span>
              <span className="block truncate text-xs text-gray">
                {r.permanent ? 'Permanente' : `Até ${new Date(r.expires_at ?? '').toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })}`}{r.reason ? ` · ${r.reason}` : ''}
              </span>
            </span>
            <button type="button" disabled={lift.isPending} onClick={() => lift.mutate(r.user_id)} className="rounded-full border border-gray/20 px-3 py-1 text-xs font-medium text-dark">Levantar</button>
          </li>
        ))}
        {list.data?.length === 0 && <li className="py-6 text-center text-sm text-gray">Ninguém está silenciado.</li>}
      </ul>
    </Sheet>
  )
}

/**
 * Chat da Equipa (Outubro 2026),a sala única dos admins de todo o país.
 * Mesmas características dos Vizinhos: 30 mensagens de cada vez, respostas,
 * @menções, reações, fotos, avisos fixados, pesquisa, filtros e tempo real.
 * Cada admin aparece com nome, tipo (Admin Geral / Provincial / Admin),
 * província e município.
 */
export default function AdminChatEquipa() {
  const queryClient = useQueryClient()
  const [params] = useSearchParams()
  const targetId = Number(params.get('mensagem')) || undefined

  const [filters, setFilters] = useState<StaffFilters>({})
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
  const [mutesOpen, setMutesOpen] = useState(false)
  const [highlight, setHighlight] = useState<number | null>(null)
  const [newCount, setNewCount] = useState(0)
  const listTop = useRef<HTMLDivElement>(null)
  const listEnd = useRef<HTMLDivElement>(null)
  const scrollTo = useRef<'top' | 'bottom' | number | null>(null)
  const atLatest = useRef(true)

  const overviewQuery = useQuery({ queryKey: ['sc-overview'], queryFn: staffChatService.overview, retry: false })
  const overview = overviewQuery.data

  useEffect(() => {
    const t = setTimeout(() => setFilters((f) => (f.q === (search.trim() || undefined) ? f : { ...f, q: search.trim() || undefined })), 400)
    return () => clearTimeout(t)
  }, [search])

  const load = useCallback(async (cursor: StaffCursor, where: 'top' | 'bottom' | number) => {
    setLoading(true)
    setLoadError(null)
    try {
      const result = await staffChatService.messages(filters, cursor)
      scrollTo.current = where
      atLatest.current = !result.meta.has_newer
      setNewCount(0)
      setWin((prev) => ({ ...result, pinned: result.pinned ?? prev?.pinned }))
    } catch (e) {
      setLoadError(getApiErrorMessage(e, 'Não foi possível carregar as mensagens.'))
    } finally {
      setLoading(false)
    }
  }, [filters])

  useEffect(() => {
    if (!overview) return
    if (targetId && !Object.values(filters).some(Boolean)) {
      void load({ around_id: targetId }, targetId).then(() => setHighlight(targetId))
    } else {
      void load({}, 'bottom')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [!!overview, filters, targetId])

  useEffect(() => {
    if (!win || scrollTo.current === null) return
    const where = scrollTo.current
    scrollTo.current = null
    requestAnimationFrame(() => {
      if (typeof where === 'number') document.getElementById(`vz-msg-${where}`)?.scrollIntoView({ block: 'center' })
      else if (where === 'top') listTop.current?.scrollIntoView({ block: 'start' })
      else listEnd.current?.scrollIntoView({ block: 'end' })
    })
  }, [win])

  useEffect(() => {
    if (highlight === null) return
    const t = setTimeout(() => setHighlight(null), 3500)
    return () => clearTimeout(t)
  }, [highlight])

  // Mensagens novas: tempo real (Reverb) + verificação periódica de segurança.
  const checkNew = useCallback(async () => {
    if (!win?.meta.newest_id) return
    if (!atLatest.current) {
      setNewCount((n) => n || 1)
      return
    }
    try {
      const more = await staffChatService.messages(filters, { after_id: win.meta.newest_id })
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
      // melhor esforço
    }
  }, [filters, win?.meta.newest_id])
  const checkRef = useRef(checkNew)
  checkRef.current = checkNew

  const realtime = getEcho() !== null
  useEffect(() => {
    const echo = getEcho()
    if (echo) echo.private('staff.chat').listen('.staff_chat.message_posted', () => void checkRef.current())
    const timer = setInterval(() => void checkRef.current(), realtime ? 60_000 : 12_000)
    return () => {
      clearInterval(timer)
      if (echo) echo.leaveChannel('private-staff.chat')
    }
  }, [realtime])

  const patch = useCallback((updated?: VzMessage) => {
    if (!updated) return
    setWin((prev) => (prev ? { ...prev, data: prev.data.map((m) => (m.id === updated.id ? updated : m)) } : prev))
  }, [])

  const refreshNear = useCallback(async (id: number) => {
    try {
      const r = await staffChatService.messages(filters, { around_id: id })
      scrollTo.current = id
      atLatest.current = !r.meta.has_newer
      setWin((prev) => ({ ...r, pinned: r.pinned ?? prev?.pinned }))
    } catch {
      void load({}, 'bottom')
    }
  }, [filters, load])

  const reload = useCallback(() => {
    void load({}, 'bottom')
    void queryClient.invalidateQueries({ queryKey: ['sc-overview'] })
  }, [load, queryClient])

  const react = useCallback(async (m: VzMessage, emoji: string | null) => {
    try {
      patch(await staffChatService.react(m.id, emoji))
    } catch (e) {
      setNotice(getApiErrorMessage(e, 'Não foi possível reagir.'))
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

  if (overviewQuery.isLoading) {
    return <div className="flex justify-center py-24 text-gray"><Loader2 className="animate-spin" size={26} aria-label="A carregar" /></div>
  }
  if (overviewQuery.isError || !overview) {
    return (
      <div className="mx-auto max-w-sm py-12 text-center">
        <StaffLockArt className="mx-auto h-32 w-32" />
        <h1 className="mt-3 text-lg font-bold text-dark">Chat da Equipa</h1>
        <p role="alert" className="mt-1 text-sm text-gray">{getApiErrorMessage(overviewQuery.error, 'Não foi possível abrir o Chat da Equipa.')}</p>
      </div>
    )
  }

  const me = overview.me
  const messages = win?.data ?? []
  const filtersActive = Object.values(filters).some(Boolean)
  const grouped: Array<{ day: string; items: VzMessage[] }> = []
  for (const m of messages) {
    const day = dayLabel(m.created_at)
    const last = grouped[grouped.length - 1]
    if (last && last.day === day) last.items.push(m)
    else grouped.push({ day, items: [m] })
  }

  const toggle = (key: 'mine' | 'mentions_me') => setFilters((f) => ({ ...f, [key]: f[key] ? undefined : true }))
  const chip = (active: boolean) => `rounded-full border px-3 py-1.5 text-xs font-medium ${active ? 'border-primary bg-primary text-white' : 'border-gray/20 bg-white text-dark'}`

  return (
    <div className="mx-auto max-w-2xl">
      <header className="overflow-hidden rounded-3xl bg-gradient-to-br from-primary to-secondary p-3 text-white">
        <StaffHeroArt className="block w-full rounded-2xl" />
        <div className="flex flex-wrap items-end justify-between gap-3 px-1 pb-1 pt-3">
          <div className="min-w-0">
            <h1 className="text-xl font-extrabold">Chat da Equipa</h1>
            <p className="flex items-center gap-1.5 text-sm text-white/90">
              Admins de todo o país · {overview.members} {overview.members === 1 ? 'membro' : 'membros'} · você:
              <span className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold text-dark">
                <RoleIcon role={me.type} size={13} /> {me.type_label}
              </span>
            </p>
          </div>
          <div className="flex shrink-0 flex-wrap justify-end gap-2">
            {me.is_super && (
              <button type="button" onClick={() => setMutesOpen(true)} className="flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 text-xs font-medium text-white ring-1 ring-white/30">
                <VolumeX size={13} aria-hidden="true" /> Silenciados
              </button>
            )}
            <button type="button" onClick={() => setPresenceOpen(true)} className="flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1.5 text-xs font-medium text-white ring-1 ring-white/30">
              <span aria-hidden="true" className="fx-live-dot" /> {overview.presence.online} online · {overview.presence.active_today} hoje
            </button>
          </div>
        </div>
      </header>

      {win?.pinned && win.pinned.length > 0 && (
        <section aria-label="Avisos fixados" className="mt-3 space-y-2">
          {win.pinned.map((p) => (
            <button key={p.id} type="button" onClick={() => void jump(p.id)} className="block w-full rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-left text-sm text-dark">
              <span className="flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-amber-700"><PinnedNoticeArt size={16} /> Aviso · {p.author?.name}</span>
              <span className="line-clamp-2 block">{(p.body ?? '').replace(/@\[(\d+)\]/g, (_m, id: string) => `@${p.mentions[id] ?? 'colega'}`)}</span>
            </button>
          ))}
        </section>
      )}

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
            {overview.types.map((t) => (
              <button key={t.key} type="button" aria-pressed={filters.type === t.key} onClick={() => setFilters((f) => ({ ...f, type: f.type === t.key ? undefined : (t.key as StaffRole) }))} className={chip(filters.type === t.key)}>{t.label}</button>
            ))}
          </div>
          <label className="block text-xs text-gray">
            Província
            <select value={filters.province_id ?? ''} onChange={(e) => setFilters((f) => ({ ...f, province_id: e.target.value ? Number(e.target.value) : undefined }))} className="mt-1 w-full rounded-xl border border-gray/20 bg-white px-3 py-2 text-sm text-dark">
              <option value="">Todas as províncias</option>
              {overview.provinces.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </label>
          {filtersActive && (
            <button type="button" onClick={() => { setFilters({}); setSearch('') }} className="flex items-center gap-1 text-xs font-medium text-primary"><X size={13} /> Limpar filtros</button>
          )}
        </div>
      )}

      <div ref={listTop} className="mt-4 scroll-mt-20" />
      {win?.meta.has_older && (
        <button type="button" disabled={loading} onClick={() => void load({ before_id: win.meta.oldest_id ?? undefined }, 'bottom')} className="mb-3 flex w-full items-center justify-center gap-1.5 rounded-full border border-gray/20 bg-white py-2.5 text-sm font-medium text-dark disabled:opacity-60">
          <ArrowUp size={14} aria-hidden="true" /> Ver mensagens anteriores
        </button>
      )}

      {loading && !win && <div className="flex justify-center py-12 text-gray"><Loader2 className="animate-spin" size={22} aria-label="A carregar" /></div>}
      {loadError && <p role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger">{loadError}</p>}

      {win && messages.length === 0 && !loading && (
        <div className="rounded-3xl border border-dashed border-gray/30 bg-white p-8 text-center">
          <StaffEmptyArt className="mx-auto h-28 w-auto" />
          <p className="mt-2 font-semibold text-dark">{filtersActive ? 'Nada encontrado com estes filtros' : 'O chat da equipa ainda está vazio'}</p>
          <p className="mt-1 text-sm text-gray">{filtersActive ? 'Experimente limpar os filtros.' : 'Seja o primeiro a escrever aos colegas.'}</p>
        </div>
      )}

      <div className={loading && win ? 'opacity-60 transition-opacity' : ''}>
        {grouped.map((g) => (
          <section key={g.day} aria-label={g.day} className="mb-2">
            <p className="sticky top-2 z-10 mx-auto my-2 w-fit rounded-full bg-white px-3 py-1 text-[11px] font-semibold text-gray shadow-sm">{g.day}</p>
            <ul className="space-y-2">
              {g.items.map((m) => (
                <MessageCard
                  key={m.id}
                  message={m}
                  emojis={overview.emojis}
                  highlighted={highlight === m.id}
                  canReact={me.can_write}
                  onReply={(x) => { setReplyTo(x); document.getElementById('sc-composer')?.scrollIntoView({ block: 'end' }) }}
                  onMore={setActionsFor}
                  onReact={(x, e) => void react(x, e)}
                  onJump={(id) => void jump(id)}
                  onImage={setImage}
                />
              ))}
            </ul>
          </section>
        ))}
      </div>

      {win?.meta.has_newer && (
        <div className="mt-3 flex flex-col gap-2">
          <button type="button" disabled={loading} onClick={() => void load({ after_id: win.meta.newest_id ?? undefined }, 'top')} className="flex w-full items-center justify-center gap-1.5 rounded-full border border-gray/20 bg-white py-2.5 text-sm font-medium text-dark disabled:opacity-60">
            Ver mensagens seguintes
          </button>
          <button type="button" onClick={reload} className="flex w-full items-center justify-center gap-1.5 rounded-full bg-primary py-2.5 text-sm font-semibold text-white">
            <ArrowDown size={14} aria-hidden="true" /> Ir para as mais recentes
          </button>
        </div>
      )}

      {newCount > 0 && !win?.meta.has_newer && (
        <button type="button" onClick={() => { scrollTo.current = 'bottom'; setNewCount(0); listEnd.current?.scrollIntoView({ block: 'end', behavior: 'smooth' }) }} className="fixed bottom-24 left-1/2 z-30 -translate-x-1/2 rounded-full bg-primary px-4 py-2 text-xs font-semibold text-white shadow-lg">
          {newCount} {newCount === 1 ? 'mensagem nova' : 'mensagens novas'} ↓
        </button>
      )}

      {notice && <p role="status" className="mt-3 rounded-xl bg-light p-3 text-xs text-dark" onClick={() => setNotice(null)}>{notice}</p>}

      <div id="sc-composer" className="sticky bottom-2 mt-4 scroll-mb-4">
        {me.muted ? (
          <p className="flex items-center gap-2 rounded-2xl bg-danger/10 p-4 text-sm text-danger">
            <StaffMutedArt size={36} className="shrink-0" />
            {me.muted_until ? `Está silenciado até ${new Date(me.muted_until).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })}. Pode ler, mas não escrever.` : 'Está silenciado: pode ler, mas não escrever.'}
          </p>
        ) : (
          <StaffComposer
            overview={overview}
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
      <div ref={listEnd} className="scroll-mb-24" aria-hidden="true" />

      {actionsFor && (
        <StaffActions
          message={actionsFor}
          overview={overview}
          onClose={() => setActionsFor(null)}
          onChanged={(updated) => {
            if (updated) patch(updated)
            else void refreshNear(actionsFor.id)
          }}
        />
      )}
      {presenceOpen && <StaffPresenceSheet onClose={() => setPresenceOpen(false)} />}
      {mutesOpen && <MutesSheet onClose={() => setMutesOpen(false)} />}
      {image && <AvatarViewer src={image} name="Foto da mensagem" shape="photo" onClose={() => setImage(null)} />}
    </div>
  )
}
