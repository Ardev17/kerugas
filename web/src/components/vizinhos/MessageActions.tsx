import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Ban, CheckCircle2, Eye, EyeOff, Flag, Loader2, Pencil, Pin, PinOff, Trash2, UserX, VolumeX } from 'lucide-react'
import { getApiErrorMessage } from '@/lib/api'
import { vizinhosService } from '@/services/vizinhosService'
import { Sheet } from './Sheet'
import type { VzMessage, VzOverview } from '@/types/vizinhos'

type Mode = 'menu' | 'report' | 'edit' | 'remove' | 'mute' | 'reports' | 'confirm-delete' | 'confirm-hard'

interface Props {
  message: VzMessage
  overview: VzOverview
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

/** Ações sobre uma mensagem: do autor, de qualquer vizinho e da moderação. */
export function MessageActions({ message: m, overview, onClose, onChanged }: Props) {
  const [mode, setMode] = useState<Mode>('menu')
  const [text, setText] = useState('')
  const [reason, setReason] = useState('spam')
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState<string | null>(null)

  const staff = m.can_moderate
  const maxMute = overview.me.max_mute_hours
  const canMute = staff && !m.mine && m.author !== null && maxMute !== 0
  const muteOptions = MUTE_OPTIONS.filter((o) => (maxMute === null ? true : o.hours !== null && o.hours <= maxMute))

  const run = useMutation({
    mutationFn: async (fn: () => Promise<VzMessage | void>) => fn(),
    onSuccess: (result) => {
      setError(null)
      onChanged(result ?? undefined)
    },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível concluir. Tente de novo.')),
  })

  const doneThen = (message: string, fn: () => Promise<VzMessage | void>) =>
    run.mutate(async () => {
      const r = await fn()
      setDone(message)
      return r
    })

  const reports = useQuery({
    queryKey: ['vz-reports', m.id],
    queryFn: () => vizinhosService.modReports(m.id),
    enabled: mode === 'reports',
  })

  const busy = run.isPending
  const closeOnDone = () => { if (done) onClose() }

  if (done) {
    return (
      <Sheet title="Feito" onClose={closeOnDone}>
        <p className="flex items-center gap-2 text-sm text-dark"><CheckCircle2 size={18} className="text-success" /> {done}</p>
        <button type="button" onClick={onClose} className="mt-4 w-full rounded-xl bg-primary py-2.5 text-sm font-semibold text-white">Fechar</button>
      </Sheet>
    )
  }

  const back = <button type="button" onClick={() => { setMode('menu'); setError(null) }} className="mb-3 text-xs font-medium text-primary">← Voltar</button>
  const errorBox = error && <p role="alert" className="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>

  if (mode === 'report') {
    return (
      <Sheet title="Denunciar mensagem" onClose={onClose}>
        {back}
        <p className="text-xs text-gray">Diga-nos o que se passa. Com 3 denúncias de pessoas diferentes, a mensagem fica escondida até a moderação a rever.</p>
        <fieldset className="mt-3 space-y-1.5">
          {overview.report_reasons.map((r) => (
            <label key={r.key} className="flex items-center gap-2 rounded-lg border border-gray/15 px-3 py-2 text-sm text-dark">
              <input type="radio" name="reason" checked={reason === r.key} onChange={() => setReason(r.key)} /> {r.label}
            </label>
          ))}
        </fieldset>
        <textarea value={text} onChange={(e) => setText(e.target.value)} maxLength={300} rows={2} placeholder="Quer acrescentar algo? (opcional)" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <button type="button" disabled={busy} onClick={() => doneThen('Obrigado! A moderação vai analisar esta mensagem.', () => vizinhosService.report(m.id, reason, text))} className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60">
          {busy && <Loader2 size={14} className="animate-spin" />} Enviar denúncia
        </button>
      </Sheet>
    )
  }

  if (mode === 'edit') {
    return (
      <Sheet title="Editar mensagem" onClose={onClose}>
        {back}
        <p className="text-xs text-gray">Pode editar nos primeiros {overview.limits.edit_minutes} minutos. As menções ficam como estão.</p>
        <textarea value={text} onChange={(e) => setText(e.target.value)} maxLength={overview.limits.max_body + 200} rows={4} aria-label="Texto da mensagem" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <button type="button" disabled={busy || text.trim() === ''} onClick={() => doneThen('Mensagem editada.', () => vizinhosService.edit(m.id, text.trim()))} className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-2.5 text-sm font-semibold text-white disabled:opacity-60">
          {busy && <Loader2 size={14} className="animate-spin" />} Guardar
        </button>
      </Sheet>
    )
  }

  if (mode === 'remove') {
    return (
      <Sheet title="Remover mensagem" onClose={onClose}>
        {back}
        <p className="text-xs text-gray">Os vizinhos passam a ver “Mensagem removida pela moderação”. O autor recebe o motivo. Pode restaurar depois.</p>
        <input value={text} onChange={(e) => setText(e.target.value)} maxLength={200} placeholder="Motivo (obrigatório)" aria-label="Motivo da remoção" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <button type="button" disabled={busy || text.trim().length < 3} onClick={() => doneThen('Mensagem removida.', () => vizinhosService.modRemove(m.id, text.trim()))} className="mt-3 flex w-full items-center justify-center gap-2 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60">
          {busy && <Loader2 size={14} className="animate-spin" />} Remover
        </button>
      </Sheet>
    )
  }

  if (mode === 'mute') {
    return (
      <Sheet title={`Silenciar ${m.author?.name ?? ''}`} onClose={onClose}>
        {back}
        <p className="text-xs text-gray">A pessoa deixa de poder escrever na comunidade durante este tempo, mas continua a ler.</p>
        <input value={text} onChange={(e) => setText(e.target.value)} maxLength={200} placeholder="Motivo (opcional)" aria-label="Motivo do silenciamento" className="mt-3 w-full rounded-xl border border-gray/20 px-3 py-2 text-sm" />
        {errorBox}
        <div className="mt-3 grid grid-cols-2 gap-2">
          {muteOptions.map((o) => (
            <button key={o.label} type="button" disabled={busy} onClick={() => m.author && doneThen(`Silenciado: ${o.label}.`, () => vizinhosService.modMute(m.author!.id, o.hours, text))} className="rounded-xl border border-gray/20 py-2.5 text-sm font-medium text-dark disabled:opacity-60">
              {o.label}
            </button>
          ))}
        </div>
      </Sheet>
    )
  }

  if (mode === 'reports') {
    return (
      <Sheet title="Denúncias" onClose={onClose}>
        {back}
        {reports.isLoading && <Loader2 className="animate-spin text-gray" size={18} />}
        <ul className="space-y-2">
          {reports.data?.map((r) => (
            <li key={r.id} className="rounded-xl border border-gray/15 p-3 text-sm">
              <p className="font-medium text-dark">{overview.report_reasons.find((x) => x.key === r.reason)?.label ?? r.reason}{r.resolved ? ' · resolvida' : ''}</p>
              {r.details && <p className="mt-0.5 text-xs text-gray">{r.details}</p>}
              <p className="mt-0.5 text-xs text-gray">por {r.reporter ?? '—'}</p>
            </li>
          ))}
          {reports.data?.length === 0 && <li className="text-sm text-gray">Sem denúncias.</li>}
        </ul>
      </Sheet>
    )
  }

  if (mode === 'confirm-delete' || mode === 'confirm-hard') {
    const hard = mode === 'confirm-hard'
    return (
      <Sheet title={hard ? 'Apagar de vez?' : 'Apagar a sua mensagem?'} onClose={onClose}>
        {back}
        <p className="text-sm text-dark">
          {hard ? 'A mensagem e a foto são eliminadas para sempre. Isto não se pode desfazer.' : 'A mensagem sai da conversa. A moderação ainda guarda o registo.'}
        </p>
        {errorBox}
        <button type="button" disabled={busy} onClick={() => doneThen(hard ? 'Mensagem apagada de vez.' : 'Mensagem apagada.', () => (hard ? vizinhosService.modHardDelete(m.id) : vizinhosService.remove(m.id)))} className="mt-4 flex w-full items-center justify-center gap-2 rounded-xl bg-danger py-2.5 text-sm font-semibold text-white disabled:opacity-60">
          {busy && <Loader2 size={14} className="animate-spin" />} {hard ? 'Apagar de vez' : 'Apagar'}
        </button>
      </Sheet>
    )
  }

  return (
    <Sheet title="Mensagem" onClose={onClose}>
      <ul>
        {m.can_edit && <li><button type="button" className={item} onClick={() => { setText(m.body ?? ''); setMode('edit') }}><Pencil size={18} /> Editar</button></li>}
        {m.can_delete && <li><button type="button" className={item} onClick={() => setMode('confirm-delete')}><Trash2 size={18} /> Apagar a minha mensagem</button></li>}
        {m.can_report && <li><button type="button" className={item} onClick={() => setMode('report')}><Flag size={18} /> Denunciar</button></li>}
        {m.author && !m.mine && m.status === 'visible' && (
          <li><button type="button" disabled={busy} className={item} onClick={() => doneThen(`Já não vê as mensagens de ${m.author?.name}. Pode desfazer em Perfil.`, () => vizinhosService.block(m.author!.id))}><EyeOff size={18} /> Esconder {m.author.name} para mim</button></li>
        )}

        {staff && (
          <>
            <li className="px-3 pb-1 pt-3 text-[11px] font-semibold uppercase tracking-wide text-gray">Moderação</li>
            {(m.report_count ?? 0) > 0 && <li><button type="button" className={item} onClick={() => setMode('reports')}><Flag size={18} /> Ver denúncias ({m.report_count})</button></li>}
            {m.status === 'visible' && <li><button type="button" className={item} onClick={() => { setText(''); setMode('remove') }}><Ban size={18} /> Remover com motivo</button></li>}
            {(m.status === 'removed' || m.status === 'hidden') && (
              <li><button type="button" disabled={busy} className={item} onClick={() => doneThen('Mensagem restaurada.', () => vizinhosService.modRestore(m.id))}><Eye size={18} /> Restaurar</button></li>
            )}
            {m.status === 'hidden' && (
              <li><button type="button" disabled={busy} className={item} onClick={() => doneThen('Denúncias arquivadas: a mensagem voltou à conversa.', () => vizinhosService.modDismiss(m.id))}><CheckCircle2 size={18} /> As denúncias não procedem</button></li>
            )}
            {m.status === 'visible' && (
              <li>
                <button type="button" disabled={busy} className={item} onClick={() => doneThen(m.pinned ? 'Aviso retirado.' : 'Aviso fixado no topo.', () => vizinhosService.modPin(m.id, !m.pinned))}>
                  {m.pinned ? <PinOff size={18} /> : <Pin size={18} />} {m.pinned ? 'Retirar dos avisos fixados' : 'Fixar como aviso'}
                </button>
              </li>
            )}
            {canMute && <li><button type="button" className={item} onClick={() => { setText(''); setMode('mute') }}><VolumeX size={18} /> Silenciar {m.author?.name}</button></li>}
            {m.can_hard_delete && <li><button type="button" className={`${item} text-danger`} onClick={() => setMode('confirm-hard')}><UserX size={18} /> Apagar de vez</button></li>}
          </>
        )}
      </ul>
      {errorBox}
    </Sheet>
  )
}
