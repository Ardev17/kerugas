import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, Loader2, MessageCircle } from 'lucide-react'
import { AvatarViewer } from '@/components/AvatarViewer'
import { RoomActions } from '@/components/roomchat/RoomActions'
import { RoomComposer } from '@/components/roomchat/RoomComposer'
import { MessageCard } from '@/components/vizinhos/MessageCard'
import { getApiErrorMessage } from '@/lib/api'
import { getEcho } from '@/lib/echo'
import { dayLabel } from '@/lib/vizinhos'
import { roomChatService, type RoomCursor } from '@/services/roomChatService'
import type { RoomOverview, RoomType } from '@/types/roomChat'
import type { VzMessage, VzWindow } from '@/types/vizinhos'

interface Props {
  type: RoomType
  roomId: number
  /** Mensagem a mostrar ao abrir (ex.: vinda de uma notificação). */
  focusId?: number
  /** Cabeçalho próprio do ecrã (recebe o resumo da sala). */
  header?: (overview: RoomOverview) => ReactNode
  emptyTitle?: string
  emptyText?: string
  placeholder?: string
  /** Quando não pode escrever (ex.: Central ainda não escreveu). */
  readOnlyText?: string
  onSent?: () => void
}

/**
 * Conversa por sala (Outubro 2026),a conversa dos condutores e a
 * "Conversa com a Central". Mesmas características dos Vizinhos: 30
 * mensagens de cada vez, respostas, @menções, reações (e quem reagiu),
 * fotos, partilha de localização e tempo real.
 */
export function RoomChat({ type, roomId, focusId, header, emptyTitle, emptyText, placeholder, readOnlyText, onSent }: Props) {
  const queryClient = useQueryClient()
  const [win, setWin] = useState<VzWindow | null>(null)
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [replyTo, setReplyTo] = useState<VzMessage | null>(null)
  const [actionsFor, setActionsFor] = useState<VzMessage | null>(null)
  const [image, setImage] = useState<string | null>(null)
  const [highlight, setHighlight] = useState<number | null>(null)
  const [newCount, setNewCount] = useState(0)
  const [stopping, setStopping] = useState(false)
  const listEnd = useRef<HTMLDivElement>(null)
  const scrollTo = useRef<'top' | 'bottom' | number | null>(null)
  const atLatest = useRef(true)

  const overviewQuery = useQuery({
    queryKey: ['room-overview', type, roomId],
    queryFn: () => roomChatService.overview(type, roomId),
    retry: false,
  })
  const overview = overviewQuery.data

  const load = useCallback(async (cursor: RoomCursor, where: 'top' | 'bottom' | number) => {
    setLoading(true)
    setLoadError(null)
    try {
      const result = await roomChatService.messages(type, roomId, cursor)
      scrollTo.current = where
      atLatest.current = !result.meta.has_newer
      setNewCount(0)
      setWin(result)
    } catch (e) {
      setLoadError(getApiErrorMessage(e, 'Não foi possível carregar as mensagens.'))
    } finally {
      setLoading(false)
    }
  }, [type, roomId])

  useEffect(() => {
    if (!overview) return
    if (focusId) void load({ around_id: focusId }, focusId).then(() => setHighlight(focusId))
    else void load({}, 'bottom')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [!!overview, type, roomId, focusId])

  useEffect(() => {
    if (!win || scrollTo.current === null) return
    const where = scrollTo.current
    scrollTo.current = null
    requestAnimationFrame(() => {
      if (typeof where === 'number') document.getElementById(`vz-msg-${where}`)?.scrollIntoView({ block: 'center' })
      else if (where === 'bottom') listEnd.current?.scrollIntoView({ block: 'nearest' })
    })
  }, [win])

  useEffect(() => {
    if (highlight === null) return
    const t = setTimeout(() => setHighlight(null), 3500)
    return () => clearTimeout(t)
  }, [highlight])

  // Mensagens novas: tempo real (Reverb) + verificação periódica de segurança.
  const checkNew = useCallback(async () => {
    if (!win) return
    if (!atLatest.current) {
      setNewCount((n) => n || 1)
      return
    }
    try {
      const more = win.meta.newest_id
        ? await roomChatService.messages(type, roomId, { after_id: win.meta.newest_id })
        : await roomChatService.messages(type, roomId)
      if (more.data.length === 0) return
      setWin((prev) => {
        if (!prev) return more
        const merged = [...prev.data, ...more.data.filter((m) => !prev.data.some((x) => x.id === m.id))]
        const trimmed = merged.slice(-90)
        return { ...prev, data: trimmed, meta: { ...more.meta, has_older: prev.meta.has_older || trimmed.length < merged.length, oldest_id: trimmed[0]?.id ?? null } }
      })
      scrollTo.current = 'bottom'
      if (type === 'central') void queryClient.invalidateQueries({ queryKey: ['central-me'] })
    } catch {
      // melhor esforço
    }
  }, [win, type, roomId, queryClient])
  const checkRef = useRef(checkNew)
  checkRef.current = checkNew

  const realtime = getEcho() !== null
  useEffect(() => {
    const echo = getEcho()
    const channel = type === 'drivers' ? `municipality.${roomId}.chat` : `${type}.${roomId}`
    const event = type === 'drivers' ? '.chat.message_sent' : `.${type}.message_posted`
    if (echo) echo.private(channel).listen(event, () => void checkRef.current())
    const timer = setInterval(() => void checkRef.current(), realtime ? 60_000 : 12_000)
    return () => {
      clearInterval(timer)
      if (echo) echo.leaveChannel(`private-${channel}`)
    }
  }, [realtime, type, roomId])

  const patch = useCallback((updated?: VzMessage) => {
    if (!updated) return
    setWin((prev) => (prev ? { ...prev, data: prev.data.map((m) => (m.id === updated.id ? updated : m)) } : prev))
  }, [])

  const react = useCallback(async (m: VzMessage, emoji: string | null) => {
    try {
      patch(await roomChatService.react(m.id, emoji))
    } catch (e) {
      setNotice(getApiErrorMessage(e, 'Não foi possível reagir.'))
    }
  }, [patch])

  const stopLocation = useCallback(async (m: VzMessage) => {
    setStopping(true)
    try {
      patch(await roomChatService.stopLocation(m.id))
    } catch (e) {
      setNotice(getApiErrorMessage(e, 'Não foi possível parar a partilha.'))
    } finally {
      setStopping(false)
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
    return <div className="flex justify-center py-16 text-gray"><Loader2 className="animate-spin" size={24} aria-label="A carregar" /></div>
  }
  if (overviewQuery.isError || !overview) {
    return <p role="alert" className="rounded-xl bg-danger/10 p-4 text-sm text-danger">{getApiErrorMessage(overviewQuery.error, 'Não foi possível abrir esta conversa.')}</p>
  }

  const messages = win?.data ?? []
  const grouped: Array<{ day: string; items: VzMessage[] }> = []
  for (const m of messages) {
    const day = dayLabel(m.created_at)
    const last = grouped[grouped.length - 1]
    if (last && last.day === day) last.items.push(m)
    else grouped.push({ day, items: [m] })
  }

  return (
    <div>
      {header?.(overview)}

      {win?.meta.has_older && (
        <button type="button" disabled={loading} onClick={() => void load({ before_id: win.meta.oldest_id ?? undefined }, 'top')} className="mb-3 mt-3 flex w-full items-center justify-center gap-1.5 rounded-full border border-gray/20 bg-white py-2.5 text-sm font-medium text-dark disabled:opacity-60">
          <ArrowUp size={14} aria-hidden="true" /> Ver mensagens anteriores
        </button>
      )}

      {loading && !win && <div className="flex justify-center py-10 text-gray"><Loader2 className="animate-spin" size={22} aria-label="A carregar" /></div>}
      {loadError && <p role="alert" className="mt-3 rounded-xl bg-danger/10 p-3 text-sm text-danger">{loadError}</p>}

      {win && messages.length === 0 && !loading && (
        <div className="mt-3 rounded-3xl border border-dashed border-gray/30 bg-white p-8 text-center">
          <MessageCircle className="mx-auto text-primary/60" size={36} aria-hidden="true" />
          <p className="mt-2 font-semibold text-dark">{emptyTitle ?? 'Ainda não há mensagens'}</p>
          {emptyText && <p className="mt-1 text-sm text-gray">{emptyText}</p>}
        </div>
      )}

      <div className={`mt-3 ${loading && win ? 'opacity-60 transition-opacity' : ''}`}>
        {grouped.map((g) => (
          <section key={g.day} aria-label={g.day} className="mb-2">
            <p className="mx-auto my-2 w-fit rounded-full bg-white px-3 py-1 text-[11px] font-semibold text-gray shadow-sm">{g.day}</p>
            <ul className="space-y-2">
              {g.items.map((m) => (
                <MessageCard
                  key={m.id}
                  message={m}
                  emojis={overview.emojis}
                  highlighted={highlight === m.id}
                  canReact={overview.me.can_write}
                  onReply={(x) => { setReplyTo(x); document.getElementById(`room-composer-${type}`)?.scrollIntoView({ block: 'end' }) }}
                  onMore={setActionsFor}
                  onReact={(x, e) => void react(x, e)}
                  onJump={(id) => void jump(id)}
                  onImage={setImage}
                  onStopLocation={(x) => void stopLocation(x)}
                  stoppingLocation={stopping}
                />
              ))}
            </ul>
          </section>
        ))}
      </div>

      {win?.meta.has_newer && (
        <button type="button" onClick={() => void load({}, 'bottom')} className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-full bg-primary py-2.5 text-sm font-semibold text-white">
          <ArrowDown size={14} aria-hidden="true" /> Ir para as mais recentes
        </button>
      )}
      {newCount > 0 && !win?.meta.has_newer && (
        <button type="button" onClick={() => void load({}, 'bottom')} className="mt-3 w-full rounded-full bg-primary/10 py-2 text-xs font-semibold text-primary">
          Há mensagens novas,mostrar
        </button>
      )}

      {notice && <p role="status" className="mt-3 rounded-xl bg-light p-3 text-xs text-dark" onClick={() => setNotice(null)}>{notice}</p>}

      <div id={`room-composer-${type}`} className="mt-4">
        {overview.me.can_write ? (
          <RoomComposer
            overview={overview}
            replyTo={replyTo}
            placeholder={placeholder}
            onCancelReply={() => setReplyTo(null)}
            onSent={(m) => {
              onSent?.()
              if (atLatest.current) {
                setWin((prev) => (prev ? { ...prev, data: [...prev.data.filter((x) => x.id !== m.id), m], meta: { ...prev.meta, newest_id: m.id } } : prev))
                scrollTo.current = 'bottom'
              } else {
                void load({}, 'bottom')
              }
            }}
          />
        ) : (
          <p className="rounded-2xl bg-light p-4 text-sm text-gray">{readOnlyText ?? 'Não pode escrever nesta conversa.'}</p>
        )}
      </div>
      <div ref={listEnd} aria-hidden="true" />

      {actionsFor && (
        <RoomActions
          message={actionsFor}
          editMinutes={overview.limits.edit_minutes}
          onClose={() => setActionsFor(null)}
          onReply={setReplyTo}
          onChanged={(updated) => {
            if (updated) patch(updated)
            else void load(win?.meta.has_newer ? { around_id: actionsFor.id } : {}, win?.meta.has_newer ? actionsFor.id : 'bottom')
          }}
        />
      )}
      {image && <AvatarViewer src={image} name="Foto da mensagem" shape="photo" onClose={() => setImage(null)} />}
    </div>
  )
}
