import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, CheckCircle2, Clock, DatabaseBackup, Download, HardDrive, Image, Loader2, RotateCcw, ShieldAlert, Trash2, Upload, XCircle } from 'lucide-react'
import { backupService, type BackupItem } from '@/services/backupService'
import { getApiErrorMessage } from '@/lib/api'
import { isNativeApp } from '@/native/platform'
import { formatBytes } from '@/lib/bytes'
import { VaultArt } from '@/components/illustrations/Admin'
import { RestoreWizard, type RestoreSource } from '@/components/admin/RestoreWizard'

const fmtDate = (iso: string | null) =>
  iso ? new Date(iso).toLocaleString('pt-PT', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'

/**
 * Cópias de segurança (Outubro 2026),só o Super Admin geral. Um botão faz
 * a cópia da base de dados (e das fotos) em segundo plano; o histórico
 * mostra o estado e permite descarregar cada cópia por um link temporário.
 */
export default function AdminBackups() {
  const queryClient = useQueryClient()
  const [includeFiles, setIncludeFiles] = useState(true)
  const [message, setMessage] = useState<{ kind: 'ok' | 'error'; text: string } | null>(null)
  const [restore, setRestore] = useState<RestoreSource | null>(null)

  const query = useQuery({
    queryKey: ['backups'],
    queryFn: backupService.list,
    // Enquanto uma cópia decorre, o estado atualiza sozinho.
    refetchInterval: (q) => (q.state.data?.meta.busy ? 4000 : false),
  })
  const items = query.data?.data ?? []
  const meta = query.data?.meta
  const busy = meta?.busy ?? false

  const start = useMutation({
    mutationFn: () => backupService.start(includeFiles),
    onSuccess: () => {
      setMessage({ kind: 'ok', text: 'Cópia iniciada. Pode demorar alguns minutos,pode sair desta página, ela continua em segundo plano.' })
      void queryClient.invalidateQueries({ queryKey: ['backups'] })
    },
    onError: (error) => setMessage({ kind: 'error', text: getApiErrorMessage(error, 'Não foi possível iniciar a cópia.') }),
  })

  const download = useMutation({
    mutationFn: async ({ id, kind }: { id: number; kind: 'db' | 'files' }) => backupService.link(id, kind),
    onSuccess: (url) => window.location.assign(url),
    onError: (error) => setMessage({ kind: 'error', text: getApiErrorMessage(error, 'Este ficheiro já não está disponível.') }),
  })

  const remove = useMutation({
    mutationFn: (id: number) => backupService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['backups'] }),
    onError: (error) => setMessage({ kind: 'error', text: getApiErrorMessage(error, 'Não foi possível apagar a cópia.') }),
  })

  const confirmRemove = (item: BackupItem) => {
    if (window.confirm(`Apagar do servidor a cópia de ${fmtDate(item.created_at)}? Esta ação não se pode desfazer.`)) remove.mutate(item.id)
  }

  return (
    <div>
      <div className="flex items-center gap-4">
        <div className="min-w-0 flex-1">
          <h1 className="flex items-center gap-2 text-xl font-bold text-dark">
            <DatabaseBackup size={20} className="text-primary" /> Cópias de segurança
          </h1>
          <p className="mt-2 text-sm text-gray">
            Uma cópia completa dos dados do KeruGás (contas, pedidos, postos, mensagens…) e das fotos. Faça uma antes de qualquer alteração importante e guarde
            uma cópia fora do servidor.
          </p>
        </div>
        {/* Cofre: o disco gira enquanto uma cópia está a ser feita. */}
        <VaultArt busy={busy} className="hidden h-24 w-auto shrink-0 sm:block" />
      </div>

      <section className="mt-5 rounded-2xl border border-gray/15 bg-white p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div>
            <p className="font-semibold text-dark">Fazer backup agora</p>
            <label className="mt-2 flex cursor-pointer items-center gap-2 text-sm text-dark">
              <input type="checkbox" checked={includeFiles} onChange={(e) => setIncludeFiles(e.target.checked)} disabled={busy} className="h-4 w-4 accent-primary" />
              Incluir as fotos (perfil e Vizinhos)
            </label>
          </div>
          <button
            type="button"
            onClick={() => {
              setMessage(null)
              start.mutate()
            }}
            disabled={busy || start.isPending}
            className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-gradient-to-r from-primary to-secondary px-5 py-2.5 text-sm font-semibold text-white shadow-sm shadow-primary/30 transition hover:opacity-90 active:scale-95 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {busy || start.isPending ? <Loader2 size={16} className="animate-spin" /> : <DatabaseBackup size={16} />}
            {busy || start.isPending ? 'A fazer a cópia…' : 'Fazer backup'}
          </button>
        </div>

        {message && (
          <p role={message.kind === 'error' ? 'alert' : 'status'} className={`mt-4 rounded-lg px-3 py-2 text-sm ${message.kind === 'error' ? 'bg-danger/10 text-danger' : 'bg-success/10 text-success'}`}>
            {message.text}
          </p>
        )}

        {meta && (
          <dl className="mt-4 grid gap-x-6 gap-y-1 border-t border-gray/10 pt-3 text-xs text-gray sm:grid-cols-3">
            <div className="flex items-center gap-1.5"><HardDrive size={13} /> Espaço livre: <strong className="text-dark">{formatBytes(meta.free_bytes)}</strong></div>
            <div className="flex items-center gap-1.5"><Clock size={13} /> Guarda as últimas <strong className="text-dark">{meta.keep || 'todas as'}</strong> cópias</div>
            <div className="flex items-center gap-1.5"><Clock size={13} /> Automático: <strong className="text-dark">{meta.auto_enabled ? `todas as noites às ${meta.auto_time}` : 'desligado'}</strong></div>
          </dl>
        )}
      </section>

      <p className="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800">
        <ShieldAlert size={14} className="mt-0.5 shrink-0" />
        <span>Uma cópia contém dados pessoais de todos os utilizadores. Descarregue só para um sítio seguro, nunca a envie por email nem a deixe em computadores partilhados. Cada descarga fica registada.</span>
      </p>
      {isNativeApp && <p className="mt-2 text-xs text-gray">Para descarregar uma cópia, abra o painel no computador.</p>}

      {/* Restaurar (Outubro 2026): repor os dados de uma cópia,enviada daqui ou já no servidor. */}
      <section className="mt-5 rounded-2xl border border-danger/25 bg-white p-4 sm:p-5">
        <div className="flex flex-wrap items-center justify-between gap-4">
          <div className="min-w-0 max-w-xl">
            <p className="flex items-center gap-2 font-semibold text-dark"><RotateCcw size={16} className="text-danger" /> Restaurar uma cópia</p>
            <p className="mt-1 text-xs text-gray">
              Envie os ficheiros de uma cópia (a base de dados .sql.gz e as fotos: o .zip ou as pastas avatars e community) para repor os dados.
              Apaga os dados atuais,o estado atual é guardado antes e, se algo falhar, é reposto sozinho.
            </p>
          </div>
          <button
            type="button"
            onClick={() => setRestore({ kind: 'upload' })}
            disabled={busy}
            className="inline-flex min-h-11 items-center gap-2 rounded-xl border-2 border-danger/40 px-5 py-2.5 text-sm font-semibold text-danger transition hover:bg-danger/5 active:scale-95 disabled:opacity-50"
          >
            <Upload size={16} /> Carregar cópia
          </button>
        </div>
      </section>

      <h2 className="mt-6 text-sm font-semibold text-dark">Histórico</h2>
      <div className="mt-2 space-y-3">
        {query.isLoading && <p className="text-sm text-gray">A carregar…</p>}
        {query.isError && <p className="text-sm text-danger">Não foi possível carregar o histórico.</p>}
        {query.isSuccess && items.length === 0 && <p className="rounded-xl border border-gray/15 bg-white p-6 text-center text-sm text-gray">Ainda não foi feita nenhuma cópia.</p>}
        {items.map((item) => (
          <BackupRow
            key={item.id}
            item={item}
            onDownload={(kind) => download.mutate({ id: item.id, kind })}
            onRemove={() => confirmRemove(item)}
            onRestore={() => setRestore({ kind: 'backup', id: item.id, label: `${fmtDate(item.created_at)} · ${formatBytes(item.db_bytes)}`, hasFiles: item.has_files })}
            busy={download.isPending || remove.isPending || busy}
          />
        ))}
      </div>

      {restore && (
        <RestoreWizard
          source={restore}
          onClose={() => {
            setRestore(null)
            void queryClient.invalidateQueries({ queryKey: ['backups'] })
          }}
        />
      )}
    </div>
  )
}

function Status({ item }: { item: BackupItem }) {
  if (item.status === 'completed') {
    return <span className="inline-flex items-center gap-1 rounded-full bg-success/10 px-2 py-0.5 text-xs font-semibold text-success"><CheckCircle2 size={12} /> {item.purged ? 'Concluída (apagada)' : 'Concluída'}</span>
  }
  if (item.status === 'failed') {
    return <span className="inline-flex items-center gap-1 rounded-full bg-danger/10 px-2 py-0.5 text-xs font-semibold text-danger"><XCircle size={12} /> Falhou</span>
  }
  return <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"><Loader2 size={12} className="animate-spin" /> {item.status === 'pending' ? 'Na fila' : 'A decorrer'}</span>
}

function BackupRow({ item, onDownload, onRemove, onRestore, busy }: { item: BackupItem; onDownload: (kind: 'db' | 'files') => void; onRemove: () => void; onRestore: () => void; busy: boolean }) {
  const canRemove = item.status !== 'pending' && item.status !== 'running' && !item.purged
  return (
    <article className="rounded-xl border border-gray/15 bg-white p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <Status item={item} />
            <span className="text-sm font-medium text-dark">{fmtDate(item.created_at)}</span>
          </div>
          <p className="mt-1 text-xs text-gray">
            {item.trigger === 'scheduled' ? 'Automática' : item.trigger === 'pre_restore' ? 'Estado antes de um restauro (guardado sozinho)' : `Pedida por ${item.requested_by ?? '—'}`}
            {item.finished_at && item.status === 'completed' ? ` · terminou ${fmtDate(item.finished_at)}` : ''}
          </p>
          {item.status === 'completed' && (
            <p className="mt-1 text-xs text-gray">
              Base de dados: <strong className="text-dark">{formatBytes(item.db_bytes)}</strong>
              {item.include_files && (item.files_count ? <> · Fotos: <strong className="text-dark">{formatBytes(item.files_bytes)}</strong> ({item.files_count})</> : ' · sem fotos')}
            </p>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {item.downloadable && !isNativeApp && (
            <>
              <button type="button" onClick={() => onDownload('db')} disabled={busy} className="inline-flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark hover:bg-light disabled:opacity-50">
                <Download size={13} /> Base de dados
              </button>
              {item.has_files && (
                <button type="button" onClick={() => onDownload('files')} disabled={busy} className="inline-flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark hover:bg-light disabled:opacity-50">
                  <Image size={13} /> Fotos
                </button>
              )}
            </>
          )}
          {item.downloadable && (
            <button type="button" onClick={onRestore} disabled={busy} className="inline-flex items-center gap-1.5 rounded-lg border border-danger/30 px-3 py-1.5 text-xs font-medium text-danger hover:bg-danger/5 disabled:opacity-50">
              <RotateCcw size={13} /> Restaurar
            </button>
          )}
          {canRemove && (
            <button type="button" onClick={onRemove} disabled={busy} aria-label="Apagar cópia" className="rounded-lg p-1.5 text-gray hover:bg-danger/10 hover:text-danger disabled:opacity-50">
              <Trash2 size={15} />
            </button>
          )}
        </div>
      </div>

      {item.error && <p className="mt-3 break-words rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{item.error}</p>}
      {item.warning && (
        <p className="mt-3 flex items-start gap-1.5 break-words rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          <AlertTriangle size={13} className="mt-0.5 shrink-0" /> {item.warning}
        </p>
      )}
    </article>
  )
}
