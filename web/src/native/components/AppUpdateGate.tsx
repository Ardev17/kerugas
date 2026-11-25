import { Download, X } from 'lucide-react'
import { useAppUpdateStore } from '@/stores/appUpdateStore'

/**
 * Atualização do app (Outubro 2026): ecrã bloqueante quando a versão já não
 * é suportada; aviso discreto (que se fecha) quando há uma versão nova.
 */
export function AppUpdateGate() {
  const { required, available, dismissed, storeUrl, dismiss } = useAppUpdateStore()
  const href = storeUrl ?? undefined

  if (required) {
    return (
      <div role="alertdialog" aria-modal="true" aria-labelledby="update-title" className="fixed inset-0 z-[2000] flex flex-col items-center justify-center gap-4 bg-white p-8 text-center dark:bg-dark">
        <span className="flex h-16 w-16 items-center justify-center rounded-full bg-primary/10 text-primary"><Download size={30} /></span>
        <h1 id="update-title" className="text-xl font-bold text-dark">Atualize a KeruGás</h1>
        <p className="max-w-xs text-sm text-gray">Esta versão da app já não é suportada. Instale a versão mais recente na Play Store para continuar.</p>
        <a href={href} target="_blank" rel="noopener noreferrer" className="rounded-xl bg-primary px-6 py-3 font-semibold text-white">
          Atualizar agora
        </a>
      </div>
    )
  }

  if (!available || dismissed) return null

  return (
    <div role="status" className="fixed inset-x-3 bottom-20 z-[1500] flex items-center gap-3 rounded-xl border border-primary/20 bg-white p-3 text-sm shadow-lg dark:bg-dark">
      <Download size={18} className="shrink-0 text-primary" />
      <p className="flex-1 text-dark">Há uma nova versão da KeruGás.</p>
      <a href={href} target="_blank" rel="noopener noreferrer" className="font-semibold text-primary">Atualizar</a>
      <button type="button" onClick={dismiss} aria-label="Fechar aviso de atualização" className="rounded p-1 text-gray"><X size={16} /></button>
    </div>
  )
}
