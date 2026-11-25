import { useEffect, useRef, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { CornerUpLeft, ImagePlus, Loader2, MapPin, Send, X } from 'lucide-react'
import { Avatar } from '@/components/Avatar'
import { LocationShareSheet } from '@/components/vizinhos/LocationShareSheet'
import { getApiErrorMessage } from '@/lib/api'
import { encodeMentions, mentionQuery } from '@/lib/vizinhos'
import { vizinhosService } from '@/services/vizinhosService'
import type { VzMessage, VzOverview, VzPerson } from '@/types/vizinhos'

interface Props {
  overview: VzOverview
  municipalityId: number
  replyTo: VzMessage | null
  onCancelReply: () => void
  onSent: (m: VzMessage) => void
}

/** Escrever na comunidade: texto, @menções, tema, bairro, foto, resposta e localização. */
export function Composer({ overview, municipalityId, replyTo, onCancelReply, onSent }: Props) {
  const [text, setText] = useState('')
  const [picked, setPicked] = useState<Array<{ id: number; name: string }>>([])
  const [topic, setTopic] = useState('')
  const [onlyMyNeighborhood, setOnlyMyNeighborhood] = useState(false)
  const [image, setImage] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [caret, setCaret] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const [locationOpen, setLocationOpen] = useState(false)
  const area = useRef<HTMLTextAreaElement>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const mention = mentionQuery(text, caret)
  const suggestions = useQuery({
    queryKey: ['vz-mentionable', municipalityId, mention?.query ?? ''],
    queryFn: () => vizinhosService.mentionable(municipalityId, mention?.query ?? ''),
    enabled: mention !== null && !/^\+?\d/.test(mention.query),
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
    mutationFn: () =>
      vizinhosService.send(municipalityId, {
        body: encodeMentions(text.trim(), picked),
        topic: topic || null,
        neighborhood_id: onlyMyNeighborhood ? overview.me.neighborhood_id : null,
        reply_to_id: replyTo?.id ?? null,
        image,
      }),
    onSuccess: (m) => {
      setText('')
      setPicked([])
      setImage(null)
      setTopic('')
      setError(null)
      onCancelReply()
      onSent(m)
    },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível enviar. Tente de novo.')),
  })

  const choose = (p: VzPerson) => {
    if (!mention) return
    const next = `${text.slice(0, mention.start)}@${p.name} ${text.slice(caret)}`
    setText(next)
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
    <form
      onSubmit={(e) => { e.preventDefault(); if (canSend) send.mutate() }}
      className="rounded-2xl border border-gray/20 bg-white p-3 shadow-sm"
    >
      {replyTo && (
        <div className="mb-2 flex items-start gap-2 rounded-lg border-l-4 border-primary/50 bg-light px-2.5 py-1.5 text-xs">
          <CornerUpLeft size={13} className="mt-0.5 shrink-0 text-primary" aria-hidden="true" />
          <span className="min-w-0 flex-1">
            A responder a <strong>{replyTo.author?.name}</strong>
            <span className="block truncate text-gray">{(replyTo.body ?? 'Foto').replace(/@\[\d+\]/g, '@…')}</span>
          </span>
          <button type="button" onClick={onCancelReply} aria-label="Cancelar resposta" className="text-gray"><X size={14} /></button>
        </div>
      )}

      {overview.me.writes_as_team && (
        <p className="mb-2 rounded-lg bg-primary/10 px-2.5 py-1.5 text-xs text-primary">Vai escrever como <strong>Equipa KeruGás</strong> neste município.</p>
      )}

      <div className="relative">
        <textarea
          ref={area}
          value={text}
          rows={2}
          maxLength={max + 200}
          placeholder="Escreva aos seus vizinhos… use @ para mencionar alguém"
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
                  {p.neighborhood && <span className="shrink-0 text-xs text-gray">{p.neighborhood}</span>}
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
        <input
          ref={fileInput}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="hidden"
          onChange={(e) => { setImage(e.target.files?.[0] ?? null); e.target.value = '' }}
        />
        <button type="button" onClick={() => fileInput.current?.click()} className="flex items-center gap-1 rounded-full border border-gray/20 px-2.5 py-1.5 text-xs font-medium text-dark">
          <ImagePlus size={14} aria-hidden="true" /> Foto
        </button>
        {/* Partilhar a localização (Outubro 2026): só das 08h às 18h. */}
        <button
          type="button"
          onClick={() => setLocationOpen(true)}
          title={overview.location_share?.open ? 'Partilhar a minha localização' : `Disponível das ${overview.location_share?.opens_at ?? '08:00'} às ${overview.location_share?.closes_at ?? '18:00'}`}
          className={`flex items-center gap-1 rounded-full border px-2.5 py-1.5 text-xs font-medium ${overview.location_share?.open ? 'border-primary/40 text-primary' : 'border-gray/20 text-gray'}`}
        >
          <MapPin size={14} aria-hidden="true" /> Localização
        </button>
        <select value={topic} onChange={(e) => setTopic(e.target.value)} aria-label="Tema" className="rounded-full border border-gray/20 bg-white px-2.5 py-1.5 text-xs text-dark">
          <option value="">Sem tema</option>
          {overview.topics.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
        </select>
        {overview.me.neighborhood_id && !overview.me.writes_as_team && (
          <label className="flex items-center gap-1.5 text-xs text-gray">
            <input type="checkbox" checked={onlyMyNeighborhood} onChange={(e) => setOnlyMyNeighborhood(e.target.checked)} />
            Para o meu bairro
          </label>
        )}
        <span className={`ml-auto text-xs tabular-nums ${text.length > max ? 'text-danger' : 'text-gray'}`}>{text.length}/{max}</span>
        <button type="submit" disabled={!canSend} className="flex items-center gap-1.5 rounded-full bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">
          {send.isPending ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} aria-hidden="true" />} Enviar
        </button>
      </div>

      {error && <p role="alert" className="mt-2 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>}
      {locationOpen && <LocationShareSheet overview={overview} municipalityId={municipalityId} onClose={() => setLocationOpen(false)} onSent={onSent} />}
    </form>
  )
}
