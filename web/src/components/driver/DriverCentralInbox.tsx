import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { MessageSquareText, PhoneCall, ShieldAlert, X } from 'lucide-react'
import { driverService, type DriverStockOverview } from '@/services/driverService'
import { getApiErrorMessage } from '@/lib/api'
import { CENTRAL_CONTACT_NUMBERS } from '@/constants/contact'

const CHANNEL_LABEL: Record<string, string> = { PHONE: 'telefonema', IN_PERSON: 'presencial', SMS: 'SMS', OTHER: 'outro' }

/**
 * Carregamentos registados pela central em nome do condutor (confirmar ou
 * contestar) + o PIN para registar por SMS (Outubro 2026).
 */
export function DriverCentralInbox({ data }: { data: DriverStockOverview }) {
  const queryClient = useQueryClient()
  const refresh = () => void queryClient.invalidateQueries({ queryKey: ['driver-stock'] })
  const [disputing, setDisputing] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const [pinOpen, setPinOpen] = useState(false)

  const confirm = useMutation({ mutationFn: (id: number) => driverService.confirmCentralPickup(id), onSuccess: refresh })
  const dispute = useMutation({
    mutationFn: ({ id, text }: { id: number; text: string }) => driverService.disputeCentralPickup(id, text),
    onSuccess: () => { setDisputing(null); setNote(''); refresh() },
  })

  const waiting = data.pickups.filter((p) => p.awaiting_confirmation)
  const central = data.central_pickup?.active
  const sms = CENTRAL_CONTACT_NUMBERS[0]

  return (
    <div className="mt-3 space-y-3">
      {central && (
        <p className="flex items-start gap-2 rounded-lg bg-amber-50 p-2.5 text-xs text-amber-800">
          <PhoneCall size={14} className="mt-0.5 shrink-0" aria-hidden="true" />
          <span>
            A central pode registar os seus carregamentos{data.central_pickup?.expires_at ? ` até ${new Date(data.central_pickup.expires_at).toLocaleDateString('pt-PT')}` : ''}.
            Quando voltar a registar pela app, isto desliga-se sozinho.
          </span>
        </p>
      )}

      {waiting.map((p) => (
        <div key={p.id} className="rounded-lg border border-amber-200 bg-amber-50/60 p-3 text-sm" data-testid="central-pickup">
          <p className="flex items-center gap-1.5 font-semibold text-dark"><ShieldAlert size={15} className="text-amber-700" aria-hidden="true" /> {p.source === 'SMS' ? 'Pedido por SMS com o PIN provisório' : 'A central registou por si'}</p>
          <p className="mt-1 text-dark">
            <strong>{p.quantity}</strong> garrafa(s) · {p.station_name}
            <span className="text-xs text-gray"> · {p.registered_at ? new Date(p.registered_at).toLocaleString('pt-PT') : ''}</span>
          </p>
          <p className="text-xs text-gray">
            {p.source === 'SMS'
              ? `Registado pela central (${p.registered_by_name ?? '—'}). Confirme só se foi você que enviou o SMS.`
              : <>{p.registered_by_name ?? 'A central'}{p.central_channel ? ` (${CHANNEL_LABEL[p.central_channel] ?? p.central_channel})` : ''}: {p.central_reason}</>}
          </p>
          {disputing === p.id ? (
            <div className="mt-2">
              <label htmlFor={`dc-${p.id}`} className="block text-xs font-medium text-gray">Porque não é verdade?</label>
              <input id={`dc-${p.id}`} value={note} onChange={(e) => setNote(e.target.value)} maxLength={255} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
              {dispute.isError && <p role="alert" className="mt-1 text-xs text-danger">{getApiErrorMessage(dispute.error, 'Não foi possível contestar.')}</p>}
              <div className="mt-2 flex gap-2">
                <button type="button" onClick={() => dispute.mutate({ id: p.id, text: note.trim() })} disabled={note.trim().length < 3 || dispute.isPending} className="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">Contestar</button>
                <button type="button" onClick={() => setDisputing(null)} className="text-xs text-gray">Cancelar</button>
              </div>
            </div>
          ) : (
            <div className="mt-2 flex gap-2">
              <button type="button" onClick={() => confirm.mutate(p.id)} disabled={confirm.isPending} aria-label={`Confirmar ${p.quantity} garrafas registadas pela central`} className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">Confirmar</button>
              <button type="button" onClick={() => { setDisputing(p.id); setNote('') }} className="rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark">Não é verdade</button>
            </div>
          )}
          {confirm.isError && <p role="alert" className="mt-1 text-xs text-danger">{getApiErrorMessage(confirm.error, 'Não foi possível confirmar.')}</p>}
        </div>
      ))}

      <div className="rounded-lg border border-gray/15 p-3 text-xs text-gray">
        <p className="flex items-center gap-1.5 text-sm font-semibold text-dark"><MessageSquareText size={15} className="text-primary" aria-hidden="true" /> Sem internet? Registe por SMS</p>
        {data.sms_pin_set ? (
          <>
            <p className="mt-1">
              Do seu telemóvel, envie um SMS para a central, <strong className="text-dark">{sms.display}</strong>:
            </p>
            <p className="mt-1 rounded bg-light px-2 py-1 font-mono text-[13px] text-dark">CARREGAR 10 00023 O-SEU-PIN</p>
            <p className="mt-1">Quantidade, <strong>número do posto</strong> (5 dígitos, veja na lista de postos) e o seu PIN. A central regista por si e o posto confirma; vai receber um aviso na app.</p>
            {data.sms_pin_temporary && (
              <p className="mt-2 rounded bg-amber-50 p-2 text-amber-800" role="status">
                O seu PIN foi definido por um administrador. <strong>Mude-o agora</strong>: enquanto não o mudar, os registos por SMS têm de ser confirmados por si ou pelo posto.
              </p>
            )}
            <button type="button" onClick={() => setPinOpen(true)} className="mt-2 font-semibold text-primary">{data.sms_pin_temporary ? 'Mudar o PIN agora' : 'Mudar o PIN'}</button>
          </>
        ) : (
          <>
            <p className="mt-1">Defina um PIN pessoal. Depois, mesmo sem internet, envia um SMS à central e ela regista o carregamento por si.</p>
            <button type="button" onClick={() => setPinOpen(true)} className="mt-2 rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white">Definir PIN</button>
          </>
        )}
      </div>

      {pinOpen && <PinModal onClose={() => setPinOpen(false)} onDone={() => { setPinOpen(false); refresh() }} />}
    </div>
  )
}

function PinModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [password, setPassword] = useState('')
  const [pin, setPin] = useState('')
  const mutation = useMutation({ mutationFn: () => driverService.setSmsPin(password, pin), onSuccess: onDone })
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label="PIN de SMS">
      <div className="w-full max-w-sm rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">PIN para registar por SMS</h3>
          <button type="button" onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        <p className="mt-2 text-sm text-gray">4 a 6 números. Não o partilhe: quem o souber e tiver o seu telemóvel pode registar por si. Após 5 erros fica bloqueado 1 hora.</p>
        <label htmlFor="sp-pin" className="mt-3 block text-xs font-medium text-gray">Novo PIN</label>
        <input id="sp-pin" type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
        <label htmlFor="sp-pass" className="mt-3 block text-xs font-medium text-gray">A sua palavra-passe</label>
        <input id="sp-pass" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
        {mutation.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível guardar o PIN.')}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm">Cancelar</button>
          <button type="button" onClick={() => mutation.mutate()} disabled={pin.length < 4 || !password || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">Guardar</button>
        </div>
      </div>
    </div>
  )
}
