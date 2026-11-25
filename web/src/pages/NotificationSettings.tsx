import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Flame, MapPin, ShieldCheck, Sparkles, MessageSquareText, MessagesSquare, Monitor, Loader2, type LucideIcon } from 'lucide-react'
import { notificationSettingsService, type NotificationPreferences } from '@/services/notificationSettingsService'
import { disableWebPush, enableWebPush, getWebPushStatus, isWebPushSupported, type WebPushStatus } from '@/lib/webPush'
import { isNativeApp } from '@/native/platform'
import { tapFeedback } from '@/native/haptics'

const OPTIONS: Array<{ key: keyof NotificationPreferences; icon: LucideIcon; title: string; description: string }> = [
  { key: 'reminders', icon: Flame, title: 'Lembretes de gás', description: 'Quando o seu gás deve estar a acabar, com base nos seus pedidos.' },
  { key: 'alerts', icon: MapPin, title: 'Gás perto de si', description: 'Um posto da sua zona voltou a ter gás, ou a fila do seu favorito ficou curta.' },
  { key: 'tips', icon: ShieldCheck, title: 'Dicas de segurança', description: 'Uma dica por mês para usar o gás em segurança em casa.' },
  { key: 'promos', icon: Sparkles, title: 'Novidades e datas especiais', description: 'Fim de semana, festas e novidades do KeruGás (no máximo 2 por semana).' },
  { key: 'community', icon: MessagesSquare, title: 'Vizinhos KeruGás', description: 'Quando alguém o menciona ou responde a uma mensagem sua na comunidade.' },
  { key: 'sms', icon: MessageSquareText, title: 'Receber também por SMS', description: 'Alguns destes avisos podem chegar por SMS, mesmo sem internet.' },
]

/**
 * Preferências de notificações (Setembro 2026). Os avisos dos seus
 * pedidos e da segurança da conta chegam sempre,só estes se desligam.
 */
export default function NotificationSettings() {
  const queryClient = useQueryClient()
  const prefsQuery = useQuery({ queryKey: ['notification-preferences'], queryFn: notificationSettingsService.get })

  const mutation = useMutation({
    mutationFn: (changes: Partial<NotificationPreferences>) => notificationSettingsService.update(changes),
    onMutate: async (changes) => {
      // Muda logo no ecrã; se o servidor recusar, volta atrás.
      const previous = queryClient.getQueryData<NotificationPreferences>(['notification-preferences'])
      queryClient.setQueryData(['notification-preferences'], { ...previous, ...changes })
      return { previous }
    },
    onError: (_e, _c, context) => queryClient.setQueryData(['notification-preferences'], context?.previous),
    onSuccess: (data) => queryClient.setQueryData(['notification-preferences'], data),
  })

  const prefs = prefsQuery.data

  return (
    <div className="mx-auto max-w-lg px-4 py-6">
      <h1 className="flex items-center gap-2 text-xl font-bold text-dark">
        <BellRing size={22} className="text-primary" /> Notificações
      </h1>
      <p className="mt-1 text-sm text-gray">
        Escolha o que quer receber. Os avisos sobre os seus pedidos chegam sempre.
      </p>

      {!isNativeApp && isWebPushSupported() && <BrowserPushCard />}

      {prefsQuery.isLoading && (
        <div className="flex justify-center py-12 text-gray"><Loader2 className="animate-spin" size={24} /></div>
      )}

      {prefs && (
        <ul className="mt-5 divide-y divide-gray/10 overflow-hidden rounded-xl border border-gray/15 bg-white">
          {OPTIONS.map(({ key, icon: Icon, title, description }) => (
            <li key={key}>
              <button
                role="switch"
                aria-checked={prefs[key]}
                onClick={() => { void tapFeedback(); mutation.mutate({ [key]: !prefs[key] }) }}
                className="flex w-full items-start gap-3 px-4 py-4 text-left active:bg-light"
              >
                <Icon size={20} className="mt-0.5 shrink-0 text-gray" />
                <span className="min-w-0 flex-1">
                  <span className="block text-sm font-medium text-dark">{title}</span>
                  <span className="mt-0.5 block text-xs text-gray">{description}</span>
                </span>
                <span
                  aria-hidden="true"
                  className={`relative mt-0.5 h-6 w-11 shrink-0 rounded-full transition-colors ${prefs[key] ? 'bg-primary' : 'bg-gray/30'}`}
                >
                  <span className={`absolute left-0 top-0.5 h-5 w-5 rounded-full bg-[#ffffff] shadow transition-transform ${prefs[key] ? 'translate-x-5' : 'translate-x-0.5'}`} />
                </span>
              </button>
            </li>
          ))}
        </ul>
      )}

      {isNativeApp && (
        <p className="mt-4 text-xs text-gray">
          Também pode escolher o som e a importância de cada tipo em Definições › Apps › KeruGás › Notificações.
        </p>
      )}
    </div>
  )
}

/** Ativar/desativar as notificações do KeruGás neste navegador (Web Push). */
function BrowserPushCard() {
  const [status, setStatus] = useState<WebPushStatus | 'loading'>('loading')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    void getWebPushStatus().then(setStatus).catch(() => setStatus('unavailable'))
  }, [])

  if (status === 'loading' || status === 'unsupported' || status === 'unavailable') {
    return null
  }

  const toggle = async () => {
    setBusy(true)
    try {
      if (status === 'enabled') {
        await disableWebPush()
        setStatus('available')
      } else {
        setStatus(await enableWebPush())
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="mt-5 rounded-xl border border-primary/20 bg-primary/5 p-4">
      <div className="flex items-start gap-3">
        <Monitor size={20} className="mt-0.5 shrink-0 text-primary" />
        <div className="min-w-0 flex-1">
          <p className="text-sm font-semibold text-dark">Notificações neste navegador</p>
          <p className="mt-0.5 text-xs text-gray">
            {status === 'denied'
              ? 'Bloqueou as notificações do KeruGás neste navegador. Pode desbloqueá-las no cadeado ao lado do endereço do site.'
              : 'Receba os avisos no computador ou telemóvel, mesmo com o site fechado.'}
          </p>
        </div>
      </div>
      {status !== 'denied' && (
        <button
          onClick={() => void toggle()}
          disabled={busy}
          className={`mt-3 w-full rounded-lg px-4 py-2.5 text-sm font-semibold disabled:opacity-60 ${
            status === 'enabled' ? 'border border-gray/20 bg-white text-dark' : 'bg-primary text-white'
          }`}
        >
          {busy ? 'Um momento...' : status === 'enabled' ? 'Desativar neste navegador' : 'Ativar notificações'}
        </button>
      )}
    </div>
  )
}
