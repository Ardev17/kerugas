import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { Ban, CheckCircle2, Eye, Loader2, Pencil, Pin, PinOff, Trash2, UserX, VolumeX } from 'lucide-react'
import { getApiErrorMessage } from '@/lib/api'
import { Sheet } from '@/components/vizinhos/Sheet'
import { staffChatService } from '@/services/staffChatService'
import type { StaffOverview } from '@/types/staffChat'
import type { VzMessage } from '@/types/vizinhos'

type Mode = 'menu' | 'edit' | 'remove' | 'mute' | 'confirm-delete' | 'confirm-hard'

interface Props {
  message: VzMessage
  overview: StaffOverview
  onClose: () => void
  /** Algo mudou (recarregar a janela). */
  onChanged: (updated?: VzMessage) => void
}

const MUTE_OPTIONS = [
  { label: '1 hora', hours: 1 },
  { label: '24 horas', hours: 24 },
  { label: '7 dias', hours: 168 },
  { label: 'Permanente', hours: null },
]

const item = 'flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-dark hover:bg-light disabled:opacity-60'

/** Ações sobre uma mensagem do Chat da Equipa: do autor e do Admin Geral. */
export function StaffActions({ message: m, overview, onClose, onChanged }: Props) {
  const [mode, setMode] = useState<Mode>('menu')
  const [text, setText] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const isSuper = overview.me.is_super && m.can_moderate

  const run = useMutation({
    mutationFn: async (fn: () => Promise<VzMessage | void>) => fn(),
    onSuccess: (result) => {
      setError(null)
      onChanged(result ?? undefined)
    },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível concluir. Tente de novo.')),
  })
  const busy = run.isPending
  const doneThen = (message: string, fn: () => Promise<VzMessage | void>) =>
    run.mutate(async () => {
      const r = await fn()
      setDone(message)
      return r
    })

  if (done) {
    return (
      <Sheet title="Feito" onClose={onClose}>
        <p className="flex items-center gap-2 text-sm text-dark"><CheckCircle2 size={18} className="text-success" /> {done}</p>
        <button type="button" onClick={onClose} className="mt-4 w-full rounded-xl bg-primary py-2.5 text-sm font-semibold text-white">Fechar</button>
      </Sheet>
    )
  }

  const back = <button type="button" onClick={() => { setMode('menu'); setError(null) }} className="mb-3 text-xs font-medium text-primary">← Voltar</button>
  const errorBox = error && <p role="alert" className="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>
  const submit = 'mt-3 flex w-full items-center justify-center gap-2 rounded-xl py-2.5 text-sm font-semibold text-white disabled:opacity-60'

  if (mode === 'edit') {
    return (
      <Sheet title="Editar mensagem" onClose={onClose}>
        {back}
        <p className="text-xs text-gray">Pode editar nos primeiros {overview.limits.edit_minutes} minutos.</p>
        <textarea value={text} onChange={(e) => setText(e.target.value)} maxLength={overview.limits.max_body + 200} rows={4} aria-label="Texto da mensagem" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <button type="button" disabled={busy || text.trim() === ''} onClick={() => doneThen('Mensagem editada.', () => staffChatService.edit(m.id, text.trim()))} className={`${submit} bg-primary`}>
          {busy && <Loader2 size={14} className="animate-spin" />} Guardar
        </button>
      </Sheet>
    )
  }

  if (mode === 'remove') {
    return (
      <Sheet title="Remover mensagem" onClose={onClose}>
        {back}
        <p className="text-xs text-gray">A equipa passa a ver “Mensagem removida”. O autor recebe o motivo. Pode restaurar depois.</p>
        <input value={text} onChange={(e) => setText(e.target.value)} maxLength={200} placeholder="Motivo (obrigatório)" aria-label="Motivo da remoção" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <button type="button" disabled={busy || text.trim().length < 3} onClick={() => doneThen('Mensagem removida.', () => staffChatService.modRemove(m.id, text.trim()))} className={`${submit} bg-danger`}>
          {busy && <Loader2 size={14} className="animate-spin" />} Remover
        </button>
      </Sheet>
    )
  }

  if (mode === 'mute') {
    return (
      <Sheet title={`Silenciar ${m.author?.name ?? ''}`} onClose={onClose}>
        {back}
        <p className="text-xs text-gray">A pessoa deixa de poder escrever neste chat durante este tempo, mas continua a ler.</p>
        <input value={text} onChange={(e) => setText(e.target.value)} maxLength={200} placeholder="Motivo (opcional)" aria-label="Motivo do silenciamento" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <div className="mt-3 grid grid-cols-2 gap-2">
          {MUTE_OPTIONS.map((o) => (
            <button key={o.label} type="button" disabled={busy} onClick={() => m.author && doneThen(`Silenciado: ${o.label}.`, () => staffChatService.mute(m.author!.id, o.hours, text))} className="rounded-xl border border-gray/20 py-2.5 text-sm font-medium text-dark disabled:opacity-60">
              {o.label}
            </button>
          ))}
        </div>
      </Sheet>
    )
  }

  if (mode === 'confirm-delete' || mode === 'confirm-hard') {
    const hard = mode === 'confirm-hard'
    return (
      <Sheet title={hard ? 'Apagar de vez?' : 'Apagar a sua mensagem?'} onClose={onClose}>
        {back}
        <p className="text-sm text-dark">{hard ? 'A mensagem e a foto são eliminadas para sempre. Isto não se pode desfazer.' : 'A mensagem sai da conversa. O Admin Geral ainda guarda o registo.'}</p>
        {errorBox}
        <button type="button" disabled={busy} onClick={() => doneThen(hard ? 'Mensagem apagada de vez.' : 'Mensagem apagada.', () => (hard ? staffChatService.purge(m.id) : staffChatService.remove(m.id)))} className={`mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60`}>
          {busy && <Loader2 size={14} className="animate-spin" />} {hard ? 'Apagar de vez' : 'Apagar'}
        </button>
      </Sheet>
    )
  }

  const nothing = !m.can_edit && !m.can_delete && !isSuper
  return (
    <Sheet title="Mensagem" onClose={onClose}>
      <ul>
        {m.can_edit && <li><button type="button" className={item} onClick={() => { setText(m.body ?? ''); setMode('edit') }}><Pencil size={18} /> Editar</button></li>}
        {m.can_delete && <li><button type="button" className={item} onClick={() => setMode('confirm-delete')}><Trash2 size={18} /> Apagar a minha mensagem</button></li>}
        {isSuper && (
          <>
            <li className="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-gray">Admin Geral</li>
            {m.status === 'visible' && <li><button type="button" className={item} onClick={() => { setText(''); setMode('remove') }}><Ban size={18} /> Remover com motivo</button></li>}
            {m.status === 'removed' && <li><button type="button" disabled={busy} className={item} onClick={() => doneThen('Mensagem restaurada.', () => staffChatService.modRestore(m.id))}><Eye size={18} /> Restaurar</button></li>}
            {m.status === 'visible' && (
              <li>
                <button type="button" disabled={busy} className={item} onClick={() => doneThen(m.pinned ? 'Aviso retirado.' : 'Aviso fixado no topo.', () => staffChatService.pin(m.id, !m.pinned))}>
                  {m.pinned ? <PinOff size={18} /> : <Pin size={18} />} {m.pinned ? 'Retirar dos avisos fixados' : 'Fixar como aviso'}
                </button>
              </li>
            )}
            {!m.mine && m.author && m.author.role !== 'SUPER_ADMIN' && <li><button type="button" className={item} onClick={() => { setText(''); setMode('mute') }}><VolumeX size={18} /> Silenciar {m.author.name}</button></li>}
            {m.can_hard_delete && <li><button type="button" className={`${item} text-danger`} onClick={() => setMode('confirm-hard')}><UserX size={18} /> Apagar de vez</button></li>}
          </>
        )}
        {nothing && <li className="px-3 py-3 text-sm text-gray">Sem ações disponíveis para esta mensagem.</li>}
      </ul>
      {errorBox}
    </Sheet>
  )
}
