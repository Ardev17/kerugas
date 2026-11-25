import { useEffect, useMemo, useRef, useState, type InputHTMLAttributes, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { CheckCircle2, Database, FileArchive, FolderOpen, Loader2, RotateCcw, ShieldAlert, Upload, X, XCircle } from 'lucide-react'
import { restoreService, type RestoreStatus, type RestoreSummary } from '@/services/restoreService'
import { photoRelativePath, uploadInChunks } from '@/lib/restoreUpload'
import { getApiErrorMessage } from '@/lib/api'
import { formatBytes } from '@/lib/bytes'
import { useAuthStore } from '@/stores/authStore'

const CONFIRM_WORD = 'RESTAURAR'

/** Fonte do restauro: ficheiros a enviar, ou uma cópia que já está no servidor. */
export type RestoreSource = { kind: 'upload' } | { kind: 'backup'; id: number; label: string; hasFiles: boolean }

interface Props {
  source: RestoreSource
  onClose: () => void
}

type Step = 'pick' | 'upload' | 'confirm' | 'progress'

interface PhotoFile {
  file: File
  path: string
}

const STAGES: { key: string; label: string }[] = [
  { key: 'verify', label: 'Verificar a cópia' },
  { key: 'safety', label: 'Guardar o estado atual' },
  { key: 'maintenance', label: 'Pôr o site em manutenção' },
  { key: 'import', label: 'Apagar e repor os dados' },
  { key: 'photos', label: 'Repor as fotos' },
  { key: 'finish', label: 'Atualizar e reabrir o site' },
]

/**
 * Assistente de restauro (Outubro 2026). Passos: escolher a cópia (o .sql.gz
 * e as fotos,o .zip da cópia OU as pastas avatars/community) → enviar
 * (partes de 1 MB, retoma se a ligação cair) → verificar → confirmar com a
 * palavra-passe e a palavra RESTAURAR → acompanhar. Nada é apagado antes da
 * confirmação; se o restauro falhar a meio, o servidor repõe sozinho o estado
 * anterior.
 */
export function RestoreWizard({ source, onClose }: Props) {
  const navigate = useNavigate()
  const clearSession = useAuthStore((s) => s.clearSession)
  const [step, setStep] = useState<Step>(source.kind === 'upload' ? 'pick' : 'confirm')
  const [error, setError] = useState<string | null>(null)

  // Escolha de ficheiros
  const [sqlFile, setSqlFile] = useState<File | null>(null)
  const [zipFile, setZipFile] = useState<File | null>(null)
  const [photos, setPhotos] = useState<PhotoFile[]>([])
  const [ignored, setIgnored] = useState(0)

  // Envio
  const [session, setSession] = useState<string | null>(null)
  const [sent, setSent] = useState(0)
  const [uploadLabel, setUploadLabel] = useState('')
  const cancelRef = useRef({ cancelled: false })

  // Confirmação
  const [summary, setSummary] = useState<RestoreSummary | null>(null)
  const [password, setPassword] = useState('')
  const [confirmText, setConfirmText] = useState('')
  const [withFiles, setWithFiles] = useState(source.kind === 'backup' ? source.hasFiles : true)
  const [starting, setStarting] = useState(false)

  // Progresso
  const [statusUrl, setStatusUrl] = useState<string | null>(null)
  const [status, setStatus] = useState<RestoreStatus | null>(null)

  const totalBytes = useMemo(
    () => (sqlFile?.size ?? 0) + (zipFile?.size ?? 0) + photos.reduce((n, p) => n + p.file.size, 0),
    [sqlFile, zipFile, photos],
  )
  const running = step === 'progress' && (!status || ['queued', 'running'].includes(status.state))

  // Acompanha o restauro pelo link assinado.
  useEffect(() => {
    if (!statusUrl) return
    let stop = false
    let timer = 0
    const tick = async () => {
      try {
        const s = await restoreService.status(statusUrl)
        if (stop) return
        setStatus(s)
        if (s.state === 'done' || s.state === 'failed') return
      } catch {
        // site a reiniciar: tenta de novo
      }
      if (!stop) timer = window.setTimeout(tick, 2000)
    }
    void tick()
    return () => {
      stop = true
      window.clearTimeout(timer)
    }
  }, [statusUrl])

  // Fechar a janela a meio de um envio cancela-o (e apaga o que já foi).
  const close = () => {
    if (running) return
    cancelRef.current.cancelled = true
    if (session && step !== 'progress') void restoreService.cancel(session).catch(() => {})
    onClose()
  }

  const addFolder = (list: FileList | null) => {
    if (!list) return
    const next: PhotoFile[] = []
    let skip = 0
    for (const file of Array.from(list)) {
      const rel = photoRelativePath((file as File & { webkitRelativePath?: string }).webkitRelativePath || file.name)
      if (rel) next.push({ file, path: rel })
      else skip++
    }
    setPhotos((prev) => {
      const seen = new Set(prev.map((p) => p.path))
      return [...prev, ...next.filter((p) => !seen.has(p.path))]
    })
    setIgnored((n) => n + skip)
    if (next.length) setZipFile(null)
  }

  const upload = async () => {
    if (!sqlFile) return
    setError(null)
    setStep('upload')
    cancelRef.current = { cancelled: false }
    try {
      const created = await restoreService.create()
      setSession(created.id)
      let base = 0
      const progress = (n: number) => setSent(base + n)

      setUploadLabel('Base de dados')
      await uploadInChunks(created.id, 'sql', sqlFile, sqlFile.name, created.chunk_bytes, progress, cancelRef.current)
      base += sqlFile.size

      if (zipFile) {
        setUploadLabel('Fotos (.zip)')
        await uploadInChunks(created.id, 'zip', zipFile, zipFile.name, created.chunk_bytes, progress, cancelRef.current)
        base += zipFile.size
      } else if (photos.length) {
        // Fotos pequenas: 4 de cada vez.
        let done = 0
        let cursor = 0
        const worker = async () => {
          while (cursor < photos.length) {
            const p = photos[cursor++]
            await uploadInChunks(created.id, 'photo', p.file, p.path, created.chunk_bytes, () => {}, cancelRef.current)
            done += p.file.size
            setSent(base + done)
            setUploadLabel(`Fotos (${Math.min(cursor, photos.length)} de ${photos.length})`)
          }
        }
        await Promise.all([worker(), worker(), worker(), worker()])
      }

      setUploadLabel('A verificar a cópia…')
      setSummary(await restoreService.verify(created.id))
      setStep('confirm')
    } catch (e) {
      if (cancelRef.current.cancelled) return
      setError(getApiErrorMessage(e, 'O envio falhou. Verifique a ligação e tente de novo.'))
      setStep('pick')
    }
  }

  const start = async () => {
    setError(null)
    setStarting(true)
    try {
      const url =
        source.kind === 'backup'
          ? await restoreService.fromBackup(source.id, password, confirmText, withFiles)
          : await restoreService.start(session as string, password, confirmText)
      setStatusUrl(url)
      setStep('progress')
    } catch (e) {
      setError(getApiErrorMessage(e, 'Não foi possível começar o restauro.'))
    } finally {
      setStarting(false)
    }
  }

  const loginAgain = () => {
    clearSession()
    navigate('/login', { replace: true })
  }

  const folderProps = { webkitdirectory: '', directory: '' } as unknown as InputHTMLAttributes<HTMLInputElement>
  const pct = totalBytes ? Math.min(100, Math.round((sent / totalBytes) * 100)) : 0
  const stageIndex = STAGES.findIndex((s) => s.key === status?.stage)

  return (
    <div className="fx-backdrop-in fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-0 backdrop-blur-sm sm:items-center sm:p-4" role="dialog" aria-modal="true" aria-label="Restaurar uma cópia">
      <div className="fx-modal-in max-h-[92dvh] w-full max-w-lg overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl sm:p-6">
        <div className="flex items-start justify-between gap-3">
          <h2 className="flex items-center gap-2 text-lg font-bold text-dark">
            <RotateCcw size={19} className="text-danger" /> Restaurar uma cópia
          </h2>
          {!running && (
            <button type="button" onClick={close} aria-label="Fechar" className="rounded-full p-1 text-gray hover:bg-light">
              <X size={18} />
            </button>
          )}
        </div>

        {error && <p role="alert" className="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-sm text-danger">{error}</p>}

        {/* ---------------- 1. Escolher ---------------- */}
        {step === 'pick' && (
          <div className="mt-4 space-y-4">
            <p className="text-sm text-gray">
              Escolha os ficheiros de uma cópia feita pelo botão <strong className="text-dark">Fazer backup</strong>. Nada é apagado até confirmar no fim.
            </p>

            <FileSlot
              icon={<Database size={18} />}
              title="Base de dados (obrigatório)"
              hint=".sql.gz ou .sql,o ficheiro “Base de dados” da cópia"
              file={sqlFile}
              accept=".gz,.sql"
              onPick={(f) => setSqlFile(f)}
            />

            <div className="rounded-2xl border border-gray/15 p-3">
              <p className="text-sm font-semibold text-dark">Fotos (opcional)</p>
              <p className="mt-0.5 text-xs text-gray">O ficheiro “Fotos” (.zip) da cópia, ou as pastas <code>avatars</code> e <code>community</code>.</p>
              <div className="mt-3 flex flex-wrap gap-2">
                <label className="fx-btn-ghost inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-gray/20 px-3 py-2 text-xs font-medium text-dark">
                  <FileArchive size={14} /> Ficheiro .zip
                  <input type="file" accept=".zip" className="sr-only" onChange={(e) => { const f = e.target.files?.[0] ?? null; setZipFile(f); if (f) { setPhotos([]); setIgnored(0) } }} />
                </label>
                <label className="fx-btn-ghost inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-gray/20 px-3 py-2 text-xs font-medium text-dark">
                  <FolderOpen size={14} /> Pasta avatars
                  <input type="file" multiple className="sr-only" {...folderProps} onChange={(e) => addFolder(e.target.files)} />
                </label>
                <label className="fx-btn-ghost inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-gray/20 px-3 py-2 text-xs font-medium text-dark">
                  <FolderOpen size={14} /> Pasta community
                  <input type="file" multiple className="sr-only" {...folderProps} onChange={(e) => addFolder(e.target.files)} />
                </label>
              </div>
              {zipFile && <p className="mt-2 text-xs text-dark">✓ {zipFile.name} ({formatBytes(zipFile.size)})</p>}
              {photos.length > 0 && (
                <p className="mt-2 text-xs text-dark">
                  ✓ {photos.length} fotos ({formatBytes(photos.reduce((n, p) => n + p.file.size, 0))})
                  {ignored > 0 && <span className="text-gray"> · {ignored} ficheiros ignorados (não são fotos de avatars/community)</span>}
                </p>
              )}
            </div>

            <div className="flex justify-end gap-2 pt-1">
              <button type="button" onClick={close} className="rounded-xl px-4 py-2.5 text-sm font-medium text-gray hover:bg-light">Cancelar</button>
              <button type="button" onClick={() => void upload()} disabled={!sqlFile} className="fx-btn-fire inline-flex items-center gap-1.5 rounded-xl px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50">
                <Upload size={15} /> Enviar e verificar
              </button>
            </div>
          </div>
        )}

        {/* ---------------- 2. Enviar ---------------- */}
        {step === 'upload' && (
          <div className="mt-5" role="status">
            <p className="flex items-center gap-2 text-sm font-medium text-dark">
              <Loader2 size={16} className="animate-spin text-primary" /> {uploadLabel || 'A enviar…'}
            </p>
            <div className="fx-bar mt-3 h-2.5" aria-hidden="true">
              <i style={{ width: `${pct}%`, animation: 'none', transition: 'width 0.3s' }} />
            </div>
            <p className="mt-2 text-xs text-gray">
              {formatBytes(sent)} de {formatBytes(totalBytes)} ({pct}%). Se a ligação cair, o envio continua de onde ficou.
            </p>
            <div className="mt-4 flex justify-end">
              <button type="button" onClick={close} className="rounded-xl px-4 py-2 text-sm font-medium text-gray hover:bg-light">Cancelar envio</button>
            </div>
          </div>
        )}

        {/* ---------------- 3. Confirmar ---------------- */}
        {step === 'confirm' && (
          <div className="mt-4 space-y-4">
            <div className="flex items-start gap-2 rounded-2xl bg-danger/10 p-3 text-sm text-danger">
              <ShieldAlert size={18} className="mt-0.5 shrink-0" />
              <div>
                <p className="font-bold">Isto APAGA todos os dados atuais e repõe os da cópia.</p>
                <p className="mt-1 text-xs">
                  Tudo o que aconteceu depois da cópia (pedidos, mensagens, contas novas) perde-se. O estado atual é guardado antes,
                  para poder voltar atrás. O site fica em manutenção durante o restauro e no fim terá de entrar de novo.
                </p>
              </div>
            </div>

            <dl className="space-y-1 rounded-2xl border border-gray/15 p-3 text-sm">
              {source.kind === 'backup' ? (
                <Row label="Cópia" value={source.label} />
              ) : (
                summary && (
                  <>
                    <Row label="Cópia" value={`${summary.db_file} · ${formatBytes(summary.db_bytes)} · íntegra ✓`} />
                    <Row label="Fotos" value={summary.photos_mode ? `${summary.photos} (${summary.photos_mode === 'zip' ? '.zip' : 'pastas'})` : 'não repõe fotos'} />
                    <Row label="Base de dados" value={summary.database} />
                    <Row label="Estado atual" value={summary.current.tables != null ? `${summary.current.tables} tabelas, ${summary.current.users} utilizadores` : 'vazia ou ilegível'} />
                  </>
                )
              )}
            </dl>

            {source.kind === 'backup' && source.hasFiles && (
              <label className="flex items-center gap-2 text-sm text-dark">
                <input type="checkbox" checked={withFiles} onChange={(e) => setWithFiles(e.target.checked)} className="h-4 w-4 accent-primary" />
                Repor também as fotos
              </label>
            )}

            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">A sua palavra-passe</span>
              <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="current-password" className="fx-input w-full rounded-xl border border-gray/20 px-3 py-2.5 text-sm" />
            </label>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">
                Escreva <strong className="text-danger">{CONFIRM_WORD}</strong> para confirmar
              </span>
              <input value={confirmText} onChange={(e) => setConfirmText(e.target.value)} autoComplete="off" spellCheck={false} className="fx-input w-full rounded-xl border border-gray/20 px-3 py-2.5 text-sm tracking-widest" />
            </label>

            <div className="flex justify-end gap-2">
              <button type="button" onClick={close} className="rounded-xl px-4 py-2.5 text-sm font-medium text-gray hover:bg-light">Cancelar</button>
              <button
                type="button"
                onClick={() => void start()}
                disabled={starting || !password || confirmText.trim() !== CONFIRM_WORD}
                className="inline-flex items-center gap-1.5 rounded-xl bg-danger px-5 py-2.5 text-sm font-semibold text-white shadow-lg shadow-danger/30 transition active:scale-95 disabled:opacity-50"
              >
                {starting ? <Loader2 size={15} className="animate-spin" /> : <RotateCcw size={15} />} Restaurar agora
              </button>
            </div>
          </div>
        )}

        {/* ---------------- 4. Progresso ---------------- */}
        {step === 'progress' && (
          <div className="mt-4">
            <ol className="space-y-2" aria-label="Progresso do restauro">
              {STAGES.map((s, i) => {
                const done = status?.state === 'done' || stageIndex > i
                const active = stageIndex === i && running
                return (
                  <li key={s.key} className={`flex items-center gap-2.5 text-sm ${done || active ? 'text-dark' : 'text-gray'}`}>
                    {done ? <CheckCircle2 size={17} className="text-success" /> : active ? <Loader2 size={17} className="animate-spin text-primary" /> : <span className="h-[17px] w-[17px] rounded-full border-2 border-gray/25" />}
                    <span className={active ? 'font-semibold' : ''}>{s.label}</span>
                  </li>
                )
              })}
            </ol>

            {status?.message && running && <p className="mt-3 text-xs text-gray" role="status">{status.message}</p>}
            {running && <p className="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Não feche esta janela. O site está em manutenção até terminar.</p>}

            {status?.state === 'done' && (
              <div className="mt-4 rounded-2xl bg-success/10 p-4 text-sm text-success">
                <p className="flex items-center gap-2 font-bold"><CheckCircle2 size={18} /> {status.message}</p>
                <p className="mt-1 text-xs text-dark">
                  O estado anterior ficou guardado ({status.safety_file}) e aparece no histórico. As sessões foram substituídas pelas da cópia: entre de novo.
                </p>
                <button type="button" onClick={loginAgain} className="fx-btn-fire mt-3 rounded-xl px-5 py-2.5 text-sm font-semibold text-white">Entrar de novo</button>
              </div>
            )}

            {status?.state === 'failed' && (
              <div className="mt-4 rounded-2xl bg-danger/10 p-4 text-sm text-danger">
                <p className="flex items-center gap-2 font-bold"><XCircle size={18} /> {status.message}</p>
                {status.error && <p className="mt-2 break-words text-xs">{status.error}</p>}
                <button type="button" onClick={onClose} className="mt-3 rounded-xl border border-danger/30 px-4 py-2 text-sm font-medium">Fechar</button>
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-gray">{label}</dt>
      <dd className="text-right font-medium text-dark">{value}</dd>
    </div>
  )
}

function FileSlot({ icon, title, hint, file, accept, onPick }: { icon: ReactNode; title: string; hint: string; file: File | null; accept: string; onPick: (f: File | null) => void }) {
  return (
    <label className={`flex cursor-pointer items-center gap-3 rounded-2xl border-2 border-dashed p-3 transition-colors ${file ? 'border-success/50 bg-success/5' : 'border-gray/25 hover:border-primary/50'}`}>
      <span className="fx-bubble h-10 w-10 shrink-0" aria-hidden="true">{icon}</span>
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold text-dark">{title}</span>
        <span className="block truncate text-xs text-gray">{file ? `✓ ${file.name} (${formatBytes(file.size)})` : hint}</span>
      </span>
      <input type="file" accept={accept} className="sr-only" onChange={(e) => onPick(e.target.files?.[0] ?? null)} />
    </label>
  )
}
