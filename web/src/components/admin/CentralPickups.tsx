import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { PhoneCall, Power, Plus, X, ShieldCheck, MessageSquareText, Eye, EyeOff } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { getApiErrorMessage } from '@/lib/api'
import { parseKz } from '@/lib/kz'
import { hasSuperPowers } from '@/lib/roles'
import { useAuth } from '@/hooks/useAuth'
import type { CentralDriver, CentralPickupPayload } from '@/types'

const CHANNELS: Array<{ key: CentralPickupPayload['channel']; label: string }> = [
  { key: 'PHONE', label: 'Telefonema' },
  { key: 'IN_PERSON', label: 'Presencial' },
  { key: 'SMS', label: 'SMS' },
  { key: 'OTHER', label: 'Outro' },
]

/**
 * Registo de carregamentos pela central (Outubro 2026).
 * Para o condutor sem telemóvel/rede: o Super Admin (ou Provincial) LIGA o
 * registo para esse condutor; depois o Admin da província regista com motivo.
 * Só conta para pagar ao posto depois de confirmado (Super Admin, condutor ou posto).
 */
export function CentralPickups() {
  const { user } = useAuth()
  const isSuper = hasSuperPowers(user)
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [enabling, setEnabling] = useState<CentralDriver | null>(null)
  const [pinFor, setPinFor] = useState<CentralDriver | null>(null)
  const [registering, setRegistering] = useState(false)
  const [smsOpen, setSmsOpen] = useState(false)
  // PIN de carregamento à vista (Outubro 2026),só Super Admin Geral/Provincial.
  const [shownPins, setShownPins] = useState<Record<number, { pin: string | null; message: string | null }>>({})
  const showPin = useMutation({
    mutationFn: (driverId: number) => adminService.showSmsPin(driverId),
    onSuccess: (r, driverId) => setShownPins((m) => ({ ...m, [driverId]: { pin: r.pin, message: r.message } })),
  })
  const hidePin = (driverId: number) => setShownPins((m) => { const n = { ...m }; delete n[driverId]; return n })

  const drivers = useQuery({ queryKey: ['admin-central-drivers', search], queryFn: () => adminService.centralDrivers({ search }) })
  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin-central-drivers'] })
    void queryClient.invalidateQueries({ queryKey: ['admin-pickups'] })
  }

  const disable = useMutation({
    mutationFn: (d: CentralDriver) => adminService.setCentralSwitch(d.id, { enabled: false }),
    onSuccess: refresh,
  })

  const enabled = (drivers.data ?? []).filter((d) => d.central_active)

  return (
    <section aria-label="Registo pela central" className="rounded-xl border border-gray/15 bg-white p-5">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="flex items-center gap-2 font-semibold text-dark"><PhoneCall size={16} className="text-primary" aria-hidden="true" /> Registo pela central</p>
          <p className="mt-1 max-w-xl text-xs text-gray">
            Para o condutor que não consegue registar (sem telemóvel ou sem rede). {isSuper
              ? 'Ligue o registo para esse condutor; depois o Admin da província regista por ele.'
              : 'O Super Admin liga o registo para o condutor; depois pode registar aqui.'}
            {' '}Só conta para pagar ao posto depois de confirmado pelo Super Admin, pelo condutor ou pelo posto.
          </p>
        </div>
        <button
          type="button"
          onClick={() => setSmsOpen(true)}
          className="flex items-center gap-1.5 rounded-lg border border-primary/40 px-3 py-2 text-sm font-semibold text-primary"
        >
          <MessageSquareText size={15} aria-hidden="true" /> Registar SMS recebido
        </button>
        <button
          type="button"
          onClick={() => setRegistering(true)}
          disabled={enabled.length === 0}
          title={enabled.length === 0 ? 'Nenhum condutor com o registo ligado' : undefined}
          className="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
        >
          <Plus size={15} aria-hidden="true" /> Registar carregamento
        </button>
      </div>

      <input
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        aria-label="Procurar condutor"
        placeholder="Procurar condutor (nome ou telefone)…"
        className="mt-4 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
      />

      {drivers.isLoading ? (
        <p className="mt-3 text-sm text-gray">A carregar condutores…</p>
      ) : (drivers.data ?? []).length === 0 ? (
        <p className="mt-3 text-sm text-gray">Nenhum condutor encontrado.</p>
      ) : (
        <ul className="mt-3 divide-y divide-gray/10">
          {(drivers.data ?? []).map((d) => (
            <li key={d.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
              <div className="min-w-0">
                <p className="font-medium text-dark">{d.name} <span className="text-xs font-normal text-gray">{d.phone}</span></p>
                {d.central_active ? (
                  <p className="text-xs text-amber-800">
                    Ligado{d.central_enabled_by ? ` por ${d.central_enabled_by}` : ''} até {d.central_expires_at ? new Date(d.central_expires_at).toLocaleDateString('pt-PT') : '—'}
                    {d.central_reason ? ` · ${d.central_reason}` : ''}
                  </p>
                ) : (
                  <p className="text-xs text-gray">{d.province ?? ''}{d.sms_pin_set ? (d.sms_pin_temporary ? ' · PIN de SMS provisório' : ' · PIN de SMS definido') : ' · sem PIN de SMS'}</p>
                )}
                {shownPins[d.id] && (
                  <p className="mt-0.5 text-xs" aria-live="polite">
                    {shownPins[d.id].pin
                      ? <>PIN de carregamento: <strong className="font-mono text-sm tracking-widest text-dark">{shownPins[d.id].pin}</strong></>
                      : <span className="text-amber-800">{shownPins[d.id].message}</span>}
                  </p>
                )}
              </div>
              {isSuper && d.sms_pin_set && (
                shownPins[d.id] ? (
                  <button type="button" onClick={() => hidePin(d.id)} aria-label={`Esconder o PIN de ${d.name}`} className="flex items-center gap-1 rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-medium text-dark">
                    <EyeOff size={12} aria-hidden="true" /> Esconder
                  </button>
                ) : (
                  <button type="button" onClick={() => showPin.mutate(d.id)} disabled={showPin.isPending} aria-label={`Ver o PIN de carregamento de ${d.name}`} className="flex items-center gap-1 rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-medium text-dark">
                    <Eye size={12} aria-hidden="true" /> Ver PIN
                  </button>
                )
              )}
              {isSuper && (
                <button type="button" onClick={() => setPinFor(d)} aria-label={`Redefinir o PIN de SMS de ${d.name}`} className="rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-medium text-dark">Novo PIN</button>
              )}
              {isSuper && (
                d.central_active ? (
                  <button type="button" onClick={() => disable.mutate(d)} disabled={disable.isPending} aria-label={`Desligar o registo pela central de ${d.name}`} className="flex items-center gap-1 rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-medium text-dark">
                    <Power size={12} aria-hidden="true" /> Desligar
                  </button>
                ) : (
                  <button type="button" onClick={() => setEnabling(d)} aria-label={`Ligar o registo pela central de ${d.name}`} className="flex items-center gap-1 rounded-lg bg-secondary/10 px-2.5 py-1 text-xs font-semibold text-secondary">
                    <Power size={12} aria-hidden="true" /> Ligar
                  </button>
                )
              )}
              {!isSuper && d.central_active && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Ligado</span>}
            </li>
          ))}
        </ul>
      )}
      {showPin.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(showPin.error, 'Não foi possível ver o PIN.')}</p>}
      {disable.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(disable.error, 'Não foi possível desligar.')}</p>}

      {pinFor && <ResetPinModal driver={pinFor} onClose={() => setPinFor(null)} onDone={() => { hidePin(pinFor.id); setPinFor(null); refresh() }} />}
      {smsOpen && <SmsModal onClose={() => setSmsOpen(false)} onDone={() => { setSmsOpen(false); refresh() }} />}
      {enabling && <EnableModal driver={enabling} onClose={() => setEnabling(null)} onDone={() => { setEnabling(null); refresh() }} />}
      {registering && <RegisterModal drivers={enabled} onClose={() => setRegistering(false)} onDone={() => { setRegistering(false); refresh() }} />}
    </section>
  )
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: React.ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-label={title}>
      <div className="max-h-[92vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">{title}</h3>
          <button type="button" onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        {children}
      </div>
    </div>
  )
}

function EnableModal({ driver, onClose, onDone }: { driver: CentralDriver; onClose: () => void; onDone: () => void }) {
  const [reason, setReason] = useState('')
  const [days, setDays] = useState(7)
  const mutation = useMutation({
    mutationFn: () => adminService.setCentralSwitch(driver.id, { enabled: true, reason: reason.trim(), days }),
    onSuccess: onDone,
  })
  return (
    <Modal title="Ligar o registo pela central" onClose={onClose}>
      <p className="mt-2 text-sm text-gray">
        A central vai poder registar carregamentos por <strong className="text-dark">{driver.name}</strong>. Fica tudo registado e o condutor é avisado de cada registo.
      </p>
      <label htmlFor="cp-reason" className="mt-3 block text-xs font-medium text-gray">Motivo</label>
      <input id="cp-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={250} placeholder="Ex.: o condutor está sem telemóvel" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      <label htmlFor="cp-days" className="mt-3 block text-xs font-medium text-gray">Durante quanto tempo</label>
      <select id="cp-days" value={days} onChange={(e) => setDays(Number(e.target.value))} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
        {[1, 3, 7, 14, 30].map((d) => <option key={d} value={d}>{d} {d === 1 ? 'dia' : 'dias'}</option>)}
      </select>
      <p className="mt-2 flex items-start gap-1.5 text-xs text-gray"><ShieldCheck size={13} className="mt-0.5 shrink-0" aria-hidden="true" /> Desliga-se sozinho quando acabar o prazo ou quando o condutor voltar a registar pela app.</p>
      {mutation.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível ligar.')}</p>}
      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm">Cancelar</button>
        <button type="button" onClick={() => mutation.mutate()} disabled={reason.trim().length < 5 || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">Ligar</button>
      </div>
    </Modal>
  )
}

function RegisterModal({ drivers, onClose, onDone }: { drivers: CentralDriver[]; onClose: () => void; onDone: () => void }) {
  const [driverId, setDriverId] = useState<number | ''>(drivers.length === 1 ? drivers[0].id : '')
  const [stationSearch, setStationSearch] = useState('')
  const [stationId, setStationId] = useState<number | ''>('')
  const [quantity, setQuantity] = useState('')
  const [amount, setAmount] = useState('')
  const [channel, setChannel] = useState<CentralPickupPayload['channel']>('PHONE')
  const [pickedAt, setPickedAt] = useState('')
  const [reason, setReason] = useState('')

  const stations = useQuery({
    queryKey: ['admin-central-stations', stationSearch],
    queryFn: () => adminService.listStations({ search: stationSearch || undefined, status: 'ACTIVE' }),
  })

  const qty = Number(quantity)
  const value = parseKz(amount)
  const valid = driverId !== '' && stationId !== '' && Number.isInteger(qty) && qty >= 1 && value !== null && reason.trim().length >= 10

  const mutation = useMutation({
    mutationFn: () => adminService.registerCentralPickup({
      driver_id: driverId as number, station_id: stationId as number, quantity: qty, amount: value as number, channel, reason: reason.trim(),
      picked_at: pickedAt ? new Date(pickedAt).toISOString() : undefined,
    }),
    onSuccess: onDone,
  })

  return (
    <Modal title="Registar carregamento pela central" onClose={onClose}>
      <label htmlFor="cr-driver" className="mt-3 block text-xs font-medium text-gray">Condutor</label>
      <select id="cr-driver" value={driverId} onChange={(e) => setDriverId(e.target.value ? Number(e.target.value) : '')} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
        <option value="">Selecione…</option>
        {drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
      </select>

      <label htmlFor="cr-search" className="mt-3 block text-xs font-medium text-gray">Procurar posto (nome ou número)</label>
      <input id="cr-search" value={stationSearch} onChange={(e) => setStationSearch(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      <label htmlFor="cr-station" className="mt-3 block text-xs font-medium text-gray">Posto</label>
      <select id="cr-station" value={stationId} onChange={(e) => setStationId(e.target.value ? Number(e.target.value) : '')} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
        <option value="">Selecione…</option>
        {(stations.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.code ? `${s.code} · ` : ''}{s.name}</option>)}
      </select>

      <div className="mt-3 grid grid-cols-2 gap-3">
        <div>
          <label htmlFor="cr-qty" className="block text-xs font-medium text-gray">Garrafas</label>
          <input id="cr-qty" type="number" min={1} inputMode="numeric" value={quantity} onChange={(e) => setQuantity(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
        </div>
        <div>
          <label htmlFor="cr-amount" className="block text-xs font-medium text-gray">Valor ao posto (Kz)</label>
          <input id="cr-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
        </div>
      </div>

      <label htmlFor="cr-channel" className="mt-3 block text-xs font-medium text-gray">Como o condutor avisou</label>
      <select id="cr-channel" value={channel} onChange={(e) => setChannel(e.target.value as CentralPickupPayload['channel'])} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
        {CHANNELS.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
      </select>

      <label htmlFor="cr-when" className="mt-3 block text-xs font-medium text-gray">Quando levou (vazio = agora; até 48 h atrás)</label>
      <input id="cr-when" type="datetime-local" value={pickedAt} onChange={(e) => setPickedAt(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />

      <label htmlFor="cr-reason" className="mt-3 block text-xs font-medium text-gray">Motivo (mín. 10 letras)</label>
      <textarea id="cr-reason" rows={2} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={255} placeholder="Ex.: o condutor ligou do posto, está sem telemóvel" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />

      {mutation.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível registar.')}</p>}
      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm">Cancelar</button>
        <button type="button" onClick={() => valid && mutation.mutate()} disabled={!valid || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">
          {mutation.isPending ? 'A registar…' : 'Registar'}
        </button>
      </div>
    </Modal>
  )
}

/**
 * O condutor mandou "CARREGAR 10 00023 4821" por SMS para o telemóvel da central.
 * Quem atende cola o texto e o número de quem enviou; o servidor confere o PIN.
 */
function SmsModal({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const [phone, setPhone] = useState('')
  const [text, setText] = useState('')
  const [amount, setAmount] = useState('')
  const value = amount.trim() === '' ? undefined : parseKz(amount)
  const valid = phone.trim().length >= 9 && /^\s*CARREGAR\s+\d{1,3}\s+\d{5}\s+\d{4,6}\s*$/i.test(text) && value !== null

  const mutation = useMutation({
    mutationFn: () => adminService.registerSmsPickup({ sender_phone: phone.trim(), sms_text: text.trim(), amount: value ?? undefined }),
    onSuccess: onDone,
  })

  return (
    <Modal title="Registar SMS recebido" onClose={onClose}>
      <p className="mt-2 text-sm text-gray">
        Cole aqui o SMS que o condutor enviou ao telemóvel da central. O sistema confere o PIN dele,se não estiver certo, não regista.
      </p>
      <label htmlFor="sms-phone" className="mt-3 block text-xs font-medium text-gray">Número de quem enviou</label>
      <input id="sms-phone" inputMode="tel" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="923 111 222" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      <label htmlFor="sms-text" className="mt-3 block text-xs font-medium text-gray">Texto do SMS</label>
      <input id="sms-text" value={text} onChange={(e) => setText(e.target.value)} placeholder="CARREGAR 10 00023 4821" autoComplete="off" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 font-mono text-sm" />
      <label htmlFor="sms-amount" className="mt-3 block text-xs font-medium text-gray">Valor ao posto (Kz),opcional</label>
      <input id="sms-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="Vazio = preço do último carregamento nesse posto" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      {mutation.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível registar.')}</p>}
      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm">Cancelar</button>
        <button type="button" onClick={() => valid && mutation.mutate()} disabled={!valid || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">
          {mutation.isPending ? 'A registar…' : 'Registar'}
        </button>
      </div>
    </Modal>
  )
}

function ResetPinModal({ driver, onClose, onDone }: { driver: CentralDriver; onClose: () => void; onDone: () => void }) {
  const [pin, setPin] = useState('')
  const mutation = useMutation({ mutationFn: () => adminService.resetSmsPin(driver.id, pin), onSuccess: onDone })
  return (
    <Modal title="Novo PIN de SMS" onClose={onClose}>
      <p className="mt-2 text-sm text-gray">
        Define um PIN provisório para <strong className="text-dark">{driver.name}</strong>. Diga-lho diretamente: ele deve mudá-lo na app, e até lá os registos por SMS precisam de confirmação.
      </p>
      <label htmlFor="rp-pin" className="mt-3 block text-xs font-medium text-gray">PIN (4 a 6 números)</label>
      <input id="rp-pin" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      {mutation.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível guardar.')}</p>}
      <div className="mt-4 flex justify-end gap-2">
        <button type="button" onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm">Cancelar</button>
        <button type="button" onClick={() => mutation.mutate()} disabled={pin.length < 4 || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">Guardar</button>
      </div>
    </Modal>
  )
}
