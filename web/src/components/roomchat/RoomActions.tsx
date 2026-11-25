import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { CheckCircle2, Copy, CornerUpLeft, Loader2, Pencil, ShieldAlert, Trash2 } from 'lucide-react'
import { Sheet } from '@/components/vizinhos/Sheet'
import { getApiErrorMessage } from '@/lib/api'
import { roomChatService } from '@/services/roomChatService'
import type { VzMessage } from '@/types/vizinhos'

type Mode = 'menu' | 'edit' | 'confirm-delete' | 'remove'

interface Props {
  message: VzMessage
  editMinutes: number
  onClose: () => void
  onReply: (m: VzMessage) => void
  /** Algo mudou: a mensagem atualizada ou nada (recarregar). */
  onChanged: (updated?: VzMessage) => void
}

const item = 'flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium text-dark hover:bg-light disabled:opacity-60'

/** Ações sobre uma mensagem: responder, copiar, editar, apagar (e remover, para os admins). */
export function RoomActions({ message: m, editMinutes, onClose, onReply, onChanged }: Props) {
  const [mode, setMode] = useState<Mode>('menu')
  const [text, setText] = useState(m.body ?? '')
  const [reason, setReason] = useState('')
  const [done, setDone] = useState<string | null>(null)

  const run = useMutation({
    mutationFn: async (fn: () => Promise<VzMessage | void>) => fn(),
    onSuccess: (result) => onChanged(result ?? undefined),
  })
  const doneThen = (message: string, fn: () => Promise<VzMessage | void>) =>
    run.mutate(async () => {
      const r = await fn()
      setDone(message)
      return r
    })
  const error = run.isError ? getApiErrorMessage(run.error, 'Não foi possível concluir. Tente de novo.') : null
  const plain = (m.body ?? '').replace(/@\[(\d+)\]/g, (_x, id: string) => `@${m.mentions[id] ?? 'colega'}`)

  if (done) {
    return (
      <Sheet title="Feito" onClose={onClose}>
        <p className="flex items-center gap-2 text-sm text-dark"><CheckCircle2 size={18} className="text-success" /> {done}</p>
        <button type="button" onClick={onClose} className="mt-4 w-full rounded-xl bg-primary py-2.5 text-sm font-semibold text-white">Fechar</button>
      </Sheet>
    )
  }

  if (mode === 'edit') {
    return (
      <Sheet title="Editar mensagem" onClose={onClose}>
        <textarea value={text} onChange={(e) => setText(e.target.value)} rows={4} aria-label="Texto da mensagem" className="w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        <p className="mt-1 text-xs text-gray">Só pode editar durante {editMinutes} minutos depois de enviar.</p>
        {error && <p role="alert" className="mt-2 text-xs text-danger">{error}</p>}
        <button type="button" disabled={run.isPending || !text.trim()} onClick={() => doneThen('Mensagem editada.', () => roomChatService.edit(m.id, text))} className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-2.5 text-sm font-semibold text-white disabled:opacity-60">
          {run.isPending && <Loader2 size={15} className="animate-spin" />} Guardar
        </button>
      </Sheet>
    )
  }

  if (mode === 'confirm-delete') {
    return (
      <Sheet title="Apagar mensagem?" onClose={onClose}>
        <p className="text-sm text-gray">A mensagem deixa de aparecer na conversa.</p>
        {error && <p role="alert" className="mt-2 text-xs text-danger">{error}</p>}
        <div className="mt-4 flex gap-2">
          <button type="button" onClick={() => setMode('menu')} className="flex-1 rounded-xl border border-gray/20 py-2.5 text-sm font-medium text-dark">Cancelar</button>
          <button type="button" disabled={run.isPending} onClick={() => doneThen('Mensagem apagada.', () => roomChatService.remove(m.id))} className="flex-1 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60">Apagar</button>
        </div>
      </Sheet>
    )
  }

  if (mode === 'remove') {
    return (
      <Sheet title="Remover mensagem" onClose={onClose}>
        <p className="text-sm text-gray">A mensagem de {m.author?.name ?? 'outra pessoa'} deixa de aparecer para todos. Fica registado em seu nome.</p>
        <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={200} placeholder="Motivo (opcional)" aria-label="Motivo" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {error && <p role="alert" className="mt-2 text-xs text-danger">{error}</p>}
        <div className="mt-4 flex gap-2">
          <button type="button" onClick={() => setMode('menu')} className="flex-1 rounded-xl border border-gray/20 py-2.5 text-sm font-medium text-dark">Cancelar</button>
          <button type="button" disabled={run.isPending} onClick={() => doneThen('Mensagem removida.', () => roomChatService.remove(m.id, reason.trim() || undefined))} className="flex-1 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60">Remover</button>
        </div>
      </Sheet>
    )
  }

  return (
    <Sheet title="Mensagem" onClose={onClose}>
      <div className="space-y-1">
        {m.status === 'visible' && (
          <button type="button" className={item} onClick={() => { onReply(m); onClose() }}><CornerUpLeft size={17} className="text-primary" /> Responder</button>
        )}
        {plain && (
          <button type="button" className={item} onClick={() => { void navigator.clipboard?.writeText(plain); setDone('Texto copiado.') }}><Copy size={17} className="text-gray" /> Copiar o texto</button>
        )}
        {m.can_edit && <button type="button" className={item} onClick={() => setMode('edit')}><Pencil size={17} className="text-gray" /> Editar</button>}
        {m.can_delete && m.mine && <button type="button" className={`${item} text-danger`} onClick={() => setMode('confirm-delete')}><Trash2 size={17} /> Apagar</button>}
        {m.can_delete && !m.mine && <button type="button" className={`${item} text-danger`} onClick={() => setMode('remove')}><ShieldAlert size={17} /> Remover (admin)</button>}
      </div>
    </Sheet>
  )
}
