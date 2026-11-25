import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CornerUpLeft, ImagePlus, Loader2, LocateFixed, MapPin, Send, X } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { Sheet } from '@/components/vizinhos/Sheet'
import { useGeolocation } from '@/hooks/useGeolocation'
import { getApiErrorMessage } from '@/lib/api'
import { durationLabel, encodeMentions, mentionQuery } from '@/lib/vizinhos'
import { roomChatService } from '@/services/roomChatService'
import type { RoomOverview, RoomPerson } from '@/types/roomChat'
import type { VzMessage } from '@/types/vizinhos'

interface Props {
  overview: RoomOverview
  replyTo: VzMessage | null
  onCancelReply: () => void
  onSent: (m: VzMessage) => void
  placeholder?: string
}

/** Acima disto o GPS é demasiado impreciso para alguém chegar lá. */
const MAX_ACCURACY = 500

/** "Partilhar a minha localização": lê o GPS e escolhe por quanto tempo fica visível. */
function LocationSheet({ overview, onClose, onSent }: { overview: RoomOverview; onClose: () => void; onSent: (m: VzMessage) => void }) {
  const geo = useGeolocation()
  const options = overview.limits.location_minutes
  const [minutes, setMinutes] = useState(options.includes(60) ? 60 : options[0])

  useEffect(() => {
    geo.request()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const located = geo.status === 'granted' && geo.latitude !== null && geo.longitude !== null
  const tooVague = located && geo.accuracy !== null && geo.accuracy > MAX_ACCURACY

  const send = useMutation({
    mutationFn: () => roomChatService.send(overview.room.type, overview.room.id, {
      location: { latitude: geo.latitude as number, longitude: geo.longitude as number, accuracy: geo.accuracy, minutes },
    }),
    onSuccess: (m) => { onSent(m); onClose() },
  })

  return (
    <Sheet title="Partilhar a minha localização" onClose={onClose}>
      <div className="space-y-3 text-sm">
        {geo.status === 'requesting' && <p className="flex items-center gap-2 text-gray"><Loader2 size={15} className="animate-spin" /> A obter a localização…</p>}
        {(geo.status === 'denied' || geo.status === 'unsupported') && (
          <div className="rounded-xl bg-danger/10 p-3 text-xs text-danger">
            Não foi possível obter a localização. Verifique se o GPS está ligado e se o KeruGás tem permissão.
            <button type="button" onClick={() => geo.request()} className="mt-2 flex items-center gap-1 font-semibold underline"><LocateFixed size={13} /> Tentar outra vez</button>
          </div>
        )}
        {located && (
          <p className="flex items-center gap-2 rounded-xl bg-light p-3 text-dark">
            <MapPin size={16} className="shrink-0 text-primary" aria-hidden="true" />
            Localização encontrada{geo.accuracy !== null ? ` (± ${Math.round(geo.accuracy)} m)` : ''}.
          </p>
        )}
        {tooVague && <p className="text-xs text-amber-700">A localização está pouco precisa. Vá para um sítio mais aberto e tente outra vez.</p>}

        <fieldset>
          <legend className="mb-1.5 text-xs font-medium text-gray">Fica visível durante</legend>
          <div className="flex flex-wrap gap-2">
            {options.map((m) => (
              <button key={m} type="button" aria-pressed={minutes === m} onClick={() => setMinutes(m)} className={`rounded-full border px-3 py-1.5 text-xs font-medium ${minutes === m ? 'border-primary bg-primary text-white' : 'border-gray/20 text-dark'}`}>
                {durationLabel(m)}
              </button>
            ))}
          </div>
        </fieldset>
        <p className="text-xs text-gray">Quem está nesta conversa vê o ponto no mapa até ao fim do prazo. Pode parar antes quando quiser.</p>

        {send.isError && <p role="alert" className="rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{getApiErrorMessage(send.error, 'Não foi possível partilhar.')}</p>}
        <button
          type="button"
          disabled={!located || tooVague || send.isPending}
          onClick={() => send.mutate()}
          className="flex w-full items-center justify-center gap-2 rounded-full bg-primary py-2.5 text-sm font-semibold text-white disabled:opacity-50"
        >
          {send.isPending ? <Loader2 size={15} className="animate-spin" /> : <MapPin size={15} aria-hidden="true" />} Partilhar localização
        </button>
      </div>
    </Sheet>
  )
}

/** Escrever numa conversa: texto, @menções, foto, localização e resposta. */
export function RoomComposer({ overview, replyTo, onCancelReply, onSent, placeholder }: Props) {
  const [text, setText] = useState('')
  const [picked, setPicked] = useState<Array<{ id: number; name: string }>>([])
  const [image, setImage] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [caret, setCaret] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [locationOpen, setLocationOpen] = useState(false)
  const area = useRef<HTMLTextAreaElement>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const { type, id } = overview.room

  const mention = mentionQuery(text, caret)
  const suggestions = useQuery({
    queryKey: ['room-mentionable', type, id, mention?.query ?? ''],
    queryFn: () => roomChatService.mentionable(type, id, mention?.query ?? ''),
    enabled: mention !== null,
    staleTime: 30_000,
  })

  useEffect(() => {
    if (!image) {
      setPreview(null)
      return
    }
    const url = URL.createObjectURL(image)
    setPreview(url)
    return () => URL.revokeObjectURL(url)
  }, [image])

  const send = useMutation({
    mutationFn: () => roomChatService.send(type, id, { body: encodeMentions(text.trim(), picked), reply_to_id: replyTo?.id ?? null, image }),
    onSuccess: (m) => {
      setText('')
      setPicked([])
      setImage(null)
      setError(null)
      onCancelReply()
      onSent(m)
    },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível enviar. Tente de novo.')),
  })

  const choose = (p: RoomPerson) => {
    if (!mention) return
    setText(`${text.slice(0, mention.start)}@${p.name} ${text.slice(caret)}`)
    setPicked((prev) => (prev.some((x) => x.id === p.id) ? prev : [...prev, { id: p.id, name: p.name }]))
    const pos = mention.start + p.name.length + 2
    requestAnimationFrame(() => {
      area.current?.focus()
      area.current?.setSelectionRange(pos, pos)
      setCaret(pos)
    })
  }

  const max = overview.limits.max_body
  const canSend = (text.trim().length > 0 || image) && text.length <= max && !send.isPending

  return (
    <form onSubmit={(e) => { e.preventDefault(); if (canSend) send.mutate() }} className="rounded-2xl border border-gray/20 bg-white p-3 shadow-sm">
      {replyTo && (
        <div className="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-primary/50 bg-light px-2.5 py-1.5 text-xs">
          <CornerUpLeft size={13} className="mt-0.5 shrink-0 text-primary" aria-hidden="true" />
          <span className="min-w-0 flex-1">
            A responder a <strong>{replyTo.author?.name}</strong>
            <span className="block truncate text-gray">{(replyTo.body || (replyTo.location ? 'Localização' : 'Foto')).replace(/@\[\d+\]/g, '@…')}</span>
          </span>
          <button type="button" onClick={onCancelReply} aria-label="Cancelar resposta" className="text-gray"><X size={14} /></button>
        </div>
      )}

      <div className="relative">
        <textarea
          ref={area}
          value={text}
          rows={2}
          maxLength={max + 200}
          placeholder={placeholder ?? 'Escreva… use @ para mencionar alguém'}
          aria-label="Mensagem"
          onChange={(e) => { setText(e.target.value); setCaret(e.target.selectionStart) }}
          onSelect={(e) => setCaret(e.currentTarget.selectionStart)}
          className="w-full resize-none rounded-xl border border-gray/20 px-3 py-2 text-sm outline-none focus:border-primary"
        />
        {mention && suggestions.data && suggestions.data.length > 0 && (
          <ul role="listbox" aria-label="Pessoas para mencionar" className="absolute bottom-full left-0 z-20 mb-1 max-h-56 w-full overflow-y-auto rounded-xl border border-gray/15 bg-white shadow-lg">
            {suggestions.data.map((p) => (
              <li key={p.id} role="option" aria-selected="false">
                <button type="button" onClick={() => choose(p)} className="flex w-full items-center gap-2 px-3 py-2 text-left hover:bg-light">
                  <Avatar name={p.name} url={p.avatar_url} size="xs" zoomable={false} />
                  <span className="min-w-0 flex-1 truncate text-sm text-dark">{p.name}</span>
                  <span className="shrink-0 text-xs text-gray">{[p.type_label, p.municipality].filter(Boolean).join(' · ')}</span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </div>

      {preview && (
        <div className="relative mt-2 inline-block">
          <img src={preview} alt="Pré-visualização da foto a enviar" className="max-h-32 rounded-xl" />
          <button type="button" onClick={() => setImage(null)} aria-label="Tirar a foto" className="absolute -right-2 -top-2 rounded-full bg-dark p-1 text-white"><X size={12} /></button>
        </div>
      )}

      <div className="mt-2 flex flex-wrap items-center gap-2">
        <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => { setImage(e.target.files?.[0] ?? null); e.target.value = '' }} />
        <button type="button" onClick={() => fileInput.current?.click()} className="flex items-center gap-1 rounded-full border border-gray/20 px-2.5 py-1.5 text-xs font-medium text-dark">
          <ImagePlus size={14} aria-hidden="true" /> Foto
        </button>
        <button type="button" onClick={() => setLocationOpen(true)} className="flex items-center gap-1 rounded-full border border-gray/20 px-2.5 py-1.5 text-xs font-medium text-dark">
          <MapPin size={14} aria-hidden="true" /> Localização
        </button>
        <span className={`ml-auto text-xs tabular-nums ${text.length > max ? 'text-danger' : 'text-gray'}`}>{text.length}/{max}</span>
        <button type="submit" disabled={!canSend} className="flex items-center gap-1.5 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          {send.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} aria-hidden="true" />} Enviar
        </button>
      </div>

      {error && <p role="alert" className="mt-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>}
      {locationOpen && <LocationSheet overview={overview} onClose={() => setLocationOpen(false)} onSent={onSent} />}
    </form>
  )
}
