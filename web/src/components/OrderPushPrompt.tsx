import { useEffect, useState } from 'react'
import { Bell, BellRing, Loader2 } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import {
  disableOrderPush,
  disableWebPush,
  enableOrderPush,
  enableWebPush,
  getOrderPushStatus,
  getWebPushStatus,
  isWebPushSupported,
  type WebPushStatus,
} from '@/lib/webPush'

/**
 * "Receber avisos deste pedido" (Outubro 2026).
 *
 * - Com conta: liga os avisos da conta neste navegador (os mesmos das
 *   Notificações),o servidor já avisa o cliente em cada passo.
 * - Sem conta: subscreve só ESTE pedido, com o telefone dele como prova.
 *
 * Só aparece quando faz sentido: navegador com suporte, servidor com Web
 * Push configurado e permissão ainda não recusada. No app Android os
 * avisos de quem tem conta já chegam pelo Firebase e não se mostra nada.
 */
export function OrderPushPrompt({ reference, phone }: { reference: string; phone: string | null }) {
  const { isAuthenticated } = useAuth()
  const [status, setStatus] = useState<WebPushStatus | 'loading'>('loading')
  const [busy, setBusy] = useState(false)
  const [failed, setFailed] = useState(false)

  const usable = isWebPushSupported() && (isAuthenticated || !!phone)

  useEffect(() => {
    if (!usable) return
    let cancelled = false
    const load = isAuthenticated ? getWebPushStatus() : getOrderPushStatus(reference)
    load.then((s) => !cancelled && setStatus(s)).catch(() => !cancelled && setStatus('unavailable'))
    return () => {
      cancelled = true
    }
  }, [usable, isAuthenticated, reference])

  if (!usable || status === 'loading' || status === 'unsupported' || status === 'unavailable') return null

  const toggle = async () => {
    setBusy(true)
    setFailed(false)
    try {
      if (status === 'enabled') {
        if (isAuthenticated) await disableWebPush()
        else await disableOrderPush(reference, phone as string)
        setStatus('available')
      } else {
        setStatus(isAuthenticated ? await enableWebPush() : await enableOrderPush(reference, phone as string))
      }
    } catch {
      setFailed(true)
    } finally {
      setBusy(false)
    }
  }

  if (status === 'denied') {
    return (
      <p className="mt-4 rounded-xl border border-gray/15 bg-light px-4 py-3 text-sm text-gray">
        As notificações estão bloqueadas neste navegador. Pode permiti-las nas definições do site para receber avisos do pedido.
      </p>
    )
  }

  const enabled = status === 'enabled'

  return (
    <div className="mt-4 rounded-xl border border-gray/15 bg-white px-4 py-3 text-left">
      <button
        type="button"
        onClick={toggle}
        disabled={busy}
        className={`flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold disabled:opacity-60 ${
          enabled ? 'bg-success/10 text-success' : 'bg-primary/10 text-primary'
        }`}
      >
        {busy ? <Loader2 size={16} className="animate-spin" /> : enabled ? <BellRing size={16} /> : <Bell size={16} />}
        {enabled ? 'Avisos ativados · desativar' : 'Receber avisos deste pedido'}
      </button>
      <p className="mt-2 text-center text-xs text-gray">
        {enabled
          ? 'Avisamos quando o pedido for confirmado, estiver a caminho e for entregue.'
          : 'Uma notificação quando o pedido for confirmado, estiver a caminho e for entregue.'}
      </p>
      {failed && (
        <p role="alert" className="mt-2 text-center text-xs text-danger">
          Não foi possível alterar os avisos agora. Tente novamente.
        </p>
      )}
    </div>
  )
}
