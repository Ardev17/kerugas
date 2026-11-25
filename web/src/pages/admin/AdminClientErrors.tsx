import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bug, CheckCircle2, RotateCcw, Smartphone, Globe } from 'lucide-react'
import { clientErrorService, type ClientErrorItem } from '@/services/clientErrorService'

const fmt = (iso: string) => new Date(iso).toLocaleString('pt-PT', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })

/**
 * Erros do site e do app (Outubro 2026): o que está a falhar nos telemóveis
 * das pessoas, agrupado (o mesmo erro aparece uma vez, com contagem).
 * Marcar como resolvido depois de publicar a correção,se voltar a
 * acontecer, reabre sozinho.
 */
export default function AdminClientErrors() {
  const [status, setStatus] = useState<'open' | 'resolved' | 'all'>('open')
  const [source, setSource] = useState('')
  const [page, setPage] = useState(1)
  const [expanded, setExpanded] = useState<number | null>(null)
  const queryClient = useQueryClient()

  const query = useQuery({
    queryKey: ['client-errors', status, source, page],
    queryFn: () => clientErrorService.list({ status, source: source || undefined, page }),
  })
  const toggle = useMutation({
    mutationFn: (id: number) => clientErrorService.toggleResolved(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['client-errors'] }),
  })

  const items = query.data?.data ?? []
  const meta = query.data?.meta

  return (
    <div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="flex items-center gap-2 text-xl font-bold text-dark"><Bug size={20} className="text-primary" /> Erros da app</h1>
        <div className="flex gap-2">
          <select value={status} onChange={(e) => { setStatus(e.target.value as typeof status); setPage(1) }} aria-label="Estado" className="rounded-lg border border-gray/20 px-3 py-2 text-sm">
            <option value="open">Por resolver</option>
            <option value="resolved">Resolvidos</option>
            <option value="all">Todos</option>
          </select>
          <select value={source} onChange={(e) => { setSource(e.target.value); setPage(1) }} aria-label="Origem" className="rounded-lg border border-gray/20 px-3 py-2 text-sm">
            <option value="">Site e app</option>
            <option value="android">App Android</option>
            <option value="ios">App iPhone</option>
            <option value="web">Site</option>
          </select>
        </div>
      </div>
      <p className="mt-2 text-xs text-gray">Os dados pessoais (emails, telefones, tokens) são retirados antes de guardar. Apagados 90 dias depois da última ocorrência.</p>

      <div className="mt-5 space-y-3">
        {query.isLoading && <p className="text-sm text-gray">A carregar...</p>}
        {query.isSuccess && items.length === 0 && (
          <p className="rounded-xl border border-gray/15 bg-white p-6 text-center text-sm text-gray">Nenhum erro {status === 'open' ? 'por resolver' : ''}. 🎉</p>
        )}
        {items.map((e) => <ErrorRow key={e.id} error={e} open={expanded === e.id} onToggle={() => setExpanded(expanded === e.id ? null : e.id)} onResolve={() => toggle.mutate(e.id)} busy={toggle.isPending} />)}
      </div>

      {meta && meta.last_page > 1 && (
        <div className="mt-4 flex items-center justify-center gap-3 text-sm">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-gray/20 px-3 py-1.5 disabled:opacity-40">Anterior</button>
          <span className="text-gray">{meta.current_page} / {meta.last_page}</span>
          <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} className="rounded-lg border border-gray/20 px-3 py-1.5 disabled:opacity-40">Seguinte</button>
        </div>
      )}
    </div>
  )
}

function ErrorRow({ error: e, open, onToggle, onResolve, busy }: { error: ClientErrorItem; open: boolean; onToggle: () => void; onResolve: () => void; busy: boolean }) {
  const Icon = e.source === 'web' ? Globe : Smartphone
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <button type="button" onClick={onToggle} className="min-w-0 flex-1 text-left">
          <p className="break-words font-medium text-dark">{e.name ? `${e.name}: ` : ''}{e.message}</p>
          <p className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray">
            <span className="inline-flex items-center gap-1"><Icon size={12} /> {e.source === 'web' ? 'Site' : `${e.source === 'ios' ? 'iPhone' : 'Android'} ${e.app_version ?? ''}`}</span>
            <span className="rounded-full bg-danger/10 px-2 py-0.5 font-semibold text-danger">{e.occurrences}×</span>
            {e.url_path && <span>{e.url_path}</span>}
            <span>última: {fmt(e.last_seen_at)}</span>
            <span>primeira: {fmt(e.first_seen_at)}</span>
          </p>
        </button>
        <button
          type="button"
          onClick={onResolve}
          disabled={busy}
          className={`inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium ${e.resolved_at ? 'border border-gray/20 text-dark' : 'bg-success/10 text-success'}`}
        >
          {e.resolved_at ? <><RotateCcw size={13} /> Reabrir</> : <><CheckCircle2 size={13} /> Resolvido</>}
        </button>
      </div>
      {open && (
        <div className="mt-3 space-y-2">
          {e.stack && <pre className="max-h-64 overflow-auto whitespace-pre-wrap rounded-lg bg-light p-3 text-[11px] text-dark">{e.stack}</pre>}
          {e.user_agent && <p className="break-words text-xs text-gray">{e.user_agent}</p>}
        </div>
      )}
    </div>
  )
}
