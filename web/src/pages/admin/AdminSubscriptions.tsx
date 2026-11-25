import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BadgeCheck, Ban, CalendarClock, CreditCard, Loader2, Plus, Radio, Settings2, X } from 'lucide-react'
import { subscriptionService } from '@/services/subscriptionService'
import { adminService } from '@/services/adminService'
import { getApiErrorMessage } from '@/lib/api'
import { useAuth } from '@/hooks/useAuth'
import { formatKz } from '@/lib/metrics'
import type { Subscription, SubscriptionKind, SubscriptionSettings, SubscriptionState } from '@/types'
import { hasSuperPowers, isProvinceSuperAdmin } from '@/lib/roles'

const STATE_LABEL: Record<SubscriptionState, string> = {
  ACTIVE: 'Ativa', EXPIRED: 'Terminada', CANCELLED: 'Cancelada', SCHEDULED: 'Agendada',
}
const STATE_STYLE: Record<SubscriptionState, string> = {
  ACTIVE: 'bg-success/10 text-success',
  EXPIRED: 'bg-danger/10 text-danger',
  CANCELLED: 'bg-gray/10 text-gray',
  SCHEDULED: 'bg-primary/10 text-primary',
}
const KIND_LABEL: Record<SubscriptionKind, string> = {
  SMS_CONSULT: 'Consulta por SMS', STATION_LISTING: 'Presença na aplicação', STATION_ORDERS: 'Pedidos do município',
  // Descontinuado em Novembro de 2026; fica só para ler o histórico.
  STATION_ANALYTICS: 'Analytics do posto (antigo)',
}

// Pedidos do município (Novembro 2026): blocos de semanas.
const WEEK_BLOCKS = [1, 2, 4, 8, 12, 26, 52]
const weeksLabel = (w: number) => (w === 1 ? '1 semana' : w === 4 ? '4 semanas (1 mês)' : w === 52 ? '52 semanas (1 ano)' : `${w} semanas`)

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

type Tab = 'lista' | 'definicoes'

/**
 * Subscrições mensais (Outubro 2026),só Super Admin. A ativação é
 * manual: o cliente/operador paga por transferência, envia o comprovativo
 * por WhatsApp e o Super Admin regista aqui o período. O servidor também
 * recusa (403) a qualquer outro papel.
 */
export default function AdminSubscriptions() {
  const { user } = useAuth()
  const [tab, setTab] = useState<Tab>('lista')

  // Super Admin geral, ou Provincial (Outubro 2026): este só ativa
  // subscrições de postos da sua província; preço e IBAN são do geral.
  const provincial = isProvinceSuperAdmin(user)
  if (user && !hasSuperPowers(user)) return <Navigate to="/admin" replace />

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-dark">Subscrições</h1>
        <p className="text-sm text-gray">
          {provincial
            ? <>Analytics dos postos da província de <strong className="text-dark">{user?.province ?? '—'}</strong>. Ativação manual; o preço e o IBAN são definidos pelo Super Admin geral.</>
            : 'Consulta de gás por SMS e analytics dos postos. Pagamento por transferência bancária, ativação manual.'}
        </p>
      </div>

      <div className="flex gap-1 overflow-x-auto rounded-lg border border-gray/15 bg-white p-1">
        <TabButton active={tab === 'lista'} onClick={() => setTab('lista')} icon={CreditCard} label="Subscrições" />
        {!provincial && (
          <TabButton active={tab === 'definicoes'} onClick={() => setTab('definicoes')} icon={Settings2} label="Definições e pagamento" />
        )}
      </div>

      {tab === 'lista' || provincial ? <ListPanel provincial={provincial} /> : <SettingsPanel />}
    </div>
  )
}

function TabButton({ active, onClick, icon: Icon, label }: { active: boolean; onClick: () => void; icon: typeof CreditCard; label: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`flex shrink-0 items-center gap-2 rounded-md px-4 py-2 text-sm font-semibold transition-colors ${active ? 'bg-primary text-white' : 'text-gray hover:bg-light'}`}
    >
      <Icon size={16} /> {label}
    </button>
  )
}

/* ------------------------------------------------------------------ */
/* Lista                                                               */
/* ------------------------------------------------------------------ */

function ListPanel({ provincial = false }: { provincial?: boolean }) {
  const queryClient = useQueryClient()
  const [type, setType] = useState('')
  const [state, setState] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [activating, setActivating] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  const query = useQuery({
    queryKey: ['admin-subscriptions', type, state, search, page],
    queryFn: () => subscriptionService.list({ type: type || undefined, state: state || undefined, search: search || undefined, page }),
  })

  const cancelMutation = useMutation({
    mutationFn: (id: number) => subscriptionService.cancel(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['admin-subscriptions'] }),
  })

  const summary = query.data?.meta.summary
  const rows = query.data?.data ?? []

  const confirmCancel = (s: Subscription) => {
    const who = s.station?.name ?? s.phone
    if (window.confirm(`Cancelar a subscrição de ${who}? Esta ação fica registada.`)) cancelMutation.mutate(s.id)
  }

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {!provincial && <Kpi icon={BadgeCheck} label="Clientes subscritos (SMS)" value={summary?.active_sms} />}
        <Kpi icon={Radio} label="Postos subscritos" value={summary?.active_stations} />
        <Kpi icon={CalendarClock} label="A terminar em 7 dias" value={summary?.expiring_7d} tone="warn" />
        <Kpi icon={Ban} label="Postos travados na presença" value={summary?.stations_listing_blocked} />
      </div>

      {notice && (
        <p role="status" className="rounded-lg bg-success/10 p-3 text-sm text-success">{notice}</p>
      )}

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-wrap gap-2">
          <select aria-label="Tipo" value={type} onChange={(e) => { setType(e.target.value); setPage(1) }} className="rounded-lg border border-gray/20 bg-white px-3 py-2 text-sm">
            <option value="">Todos os tipos</option>
            {!provincial && <option value="SMS_CONSULT">Consulta por SMS</option>}
            <option value="STATION_LISTING">Presença na aplicação</option>
            <option value="STATION_ORDERS">Pedidos do município</option>
            <option value="STATION_ANALYTICS">Analytics do posto (antigo)</option>
          </select>
          <select aria-label="Estado" value={state} onChange={(e) => { setState(e.target.value); setPage(1) }} className="rounded-lg border border-gray/20 bg-white px-3 py-2 text-sm">
            <option value="">Todos os estados</option>
            <option value="active">Ativas</option>
            <option value="expiring">A terminar (7 dias)</option>
            <option value="expired">Terminadas</option>
            <option value="cancelled">Canceladas</option>
          </select>
          <input
            aria-label="Pesquisar subscrições"
            placeholder="Telemóvel ou posto..."
            value={search}
            onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            className="rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
        </div>
        <button
          type="button"
          onClick={() => setActivating(true)}
          className="flex items-center justify-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white"
        >
          <Plus size={16} /> Ativar subscrição
        </button>
      </div>

      {query.isLoading && <p className="text-sm text-gray">A carregar subscrições...</p>}
      {query.isError && <p className="text-sm text-danger">Não foi possível carregar as subscrições.</p>}
      {query.data && rows.length === 0 && (
        <p className="rounded-xl border border-dashed border-gray/25 p-8 text-center text-sm text-gray">
          Ainda não há subscrições {state || type || search ? 'com estes filtros' : 'registadas'}. Quando receber um comprovativo, use "Ativar subscrição".
        </p>
      )}

      {rows.length > 0 && (
        <>
          <div className="hidden overflow-x-auto rounded-xl border border-gray/15 bg-white md:block">
            <table className="w-full text-left text-sm">
              <thead className="bg-light text-xs uppercase text-gray">
                <tr>
                  <th className="px-4 py-3">Quem</th><th className="px-4 py-3">Tipo</th><th className="px-4 py-3">Estado</th>
                  <th className="px-4 py-3">Válida</th><th className="px-4 py-3">Pago</th><th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {rows.map((s) => (
                  <tr key={s.id} className="border-t border-gray/10">
                    <td className="px-4 py-3">
                      <p className="font-medium text-dark">{s.station?.name ?? s.phone}</p>
                      {s.station?.operator_name && <p className="text-xs text-gray">Operador: {s.station.operator_name}</p>}
                    </td>
                    <td className="px-4 py-3 text-gray">{KIND_LABEL[s.type]}</td>
                    <td className="px-4 py-3"><StateBadge state={s.state} /></td>
                    <td className="px-4 py-3 text-gray">{formatDate(s.starts_at)} → {formatDate(s.expires_at)}</td>
                    <td className="px-4 py-3 text-gray">
                      {s.amount_kz != null ? formatKz(s.amount_kz) : '—'}
                      {s.payment_reference && <p className="max-w-[16rem] truncate text-xs" title={s.payment_reference}>{s.payment_reference}</p>}
                    </td>
                    <td className="px-4 py-3 text-right">
                      {s.state !== 'CANCELLED' && s.state !== 'EXPIRED' && (
                        <button type="button" onClick={() => confirmCancel(s)} className="text-xs font-semibold text-danger">Cancelar</button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="space-y-3 md:hidden">
            {rows.map((s) => (
              <div key={s.id} className="rounded-xl border border-gray/15 bg-white p-4">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <p className="truncate font-medium text-dark">{s.station?.name ?? s.phone}</p>
                    <p className="text-xs text-gray">{KIND_LABEL[s.type]}</p>
                  </div>
                  <StateBadge state={s.state} />
                </div>
                <p className="mt-2 text-xs text-gray">{formatDate(s.starts_at)} → {formatDate(s.expires_at)}</p>
                {s.amount_kz != null && <p className="text-xs text-gray">Pago: {formatKz(s.amount_kz)}</p>}
                {s.state !== 'CANCELLED' && s.state !== 'EXPIRED' && (
                  <button type="button" onClick={() => confirmCancel(s)} className="mt-2 text-xs font-semibold text-danger">Cancelar</button>
                )}
              </div>
            ))}
          </div>

          {query.data && query.data.meta.last_page > 1 && (
            <div className="flex items-center justify-center gap-3 text-sm">
              <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-gray/20 px-3 py-1.5 disabled:opacity-40">Anterior</button>
              <span className="text-gray">Página {query.data.meta.current_page} de {query.data.meta.last_page}</span>
              <button type="button" disabled={page >= query.data.meta.last_page} onClick={() => setPage(page + 1)} className="rounded-lg border border-gray/20 px-3 py-1.5 disabled:opacity-40">Seguinte</button>
            </div>
          )}
        </>
      )}

      {activating && (
        <ActivateModal provincial={provincial}
          onClose={() => setActivating(false)}
          onDone={(message) => {
            setActivating(false)
            setNotice(message)
            void queryClient.invalidateQueries({ queryKey: ['admin-subscriptions'] })
            void queryClient.invalidateQueries({ queryKey: ['admin-stations'] })
          }}
        />
      )}
    </div>
  )
}

function Kpi({ icon: Icon, label, value, tone }: { icon: typeof BadgeCheck; label: string; value: number | undefined; tone?: 'warn' }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-4">
      <Icon size={16} className={tone === 'warn' ? 'text-warning' : 'text-primary'} />
      <p className="mt-2 text-2xl font-bold text-dark">{value ?? '—'}</p>
      <p className="text-xs text-gray">{label}</p>
    </div>
  )
}

function StateBadge({ state }: { state: SubscriptionState }) {
  return <span className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-semibold ${STATE_STYLE[state]}`}>{STATE_LABEL[state]}</span>
}

/* ------------------------------------------------------------------ */
/* Ativar / renovar                                                    */
/* ------------------------------------------------------------------ */

function ActivateModal({ onClose, onDone, provincial = false }: { onClose: () => void; onDone: (message: string) => void; provincial?: boolean }) {
  const [type, setType] = useState<SubscriptionKind>(provincial ? 'STATION_LISTING' : 'SMS_CONSULT')
  const [phone, setPhone] = useState('')
  const [stationSearch, setStationSearch] = useState('')
  const [stationId, setStationId] = useState<number | ''>('')
  const [months, setMonths] = useState(1)
  const [weeks, setWeeks] = useState(2)
  const isStation = type === 'STATION_LISTING' || type === 'STATION_ORDERS'
  // Os dois produtos de posto são cobrados por semana.
  const porSemana = isStation
  const settingsQuery = useQuery({ queryKey: ['subscription-settings'], queryFn: subscriptionService.settings, enabled: porSemana && !provincial, retry: false })
  const weekPrice = type === 'STATION_LISTING'
    ? (settingsQuery.data?.price_station_listing_week_kz ?? 0)
    : (settingsQuery.data?.price_station_orders_week_kz ?? 1000)
  const [amount, setAmount] = useState('')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [sendSms, setSendSms] = useState(true)

  const digits = phone.replace(/\D/g, '')
  const lookupQuery = useQuery({
    queryKey: ['subscription-lookup', digits],
    queryFn: () => subscriptionService.lookup(phone),
    enabled: type === 'SMS_CONSULT' && digits.length >= 9,
    retry: false,
  })
  const stationsQuery = useQuery({
    queryKey: ['admin-stations', 'subscription-picker', stationSearch],
    queryFn: () => adminService.listStations({ search: stationSearch || undefined }),
    enabled: isStation,
  })

  const mutation = useMutation({
    mutationFn: () => subscriptionService.activate({
      type,
      phone: type === 'SMS_CONSULT' ? phone : undefined,
      station_id: isStation && stationId !== '' ? Number(stationId) : undefined,
      months,
      weeks: porSemana ? weeks : undefined,
      amount_kz: amount ? Number(amount) : null,
      payment_reference: reference || null,
      notes: notes || null,
      send_confirmation_sms: type === 'SMS_CONSULT' ? sendSms : undefined,
    }),
    onSuccess: ({ subscription, meta }) => {
      const parts = [`Subscrição ativa até ${formatDate(subscription.expires_at)}.`]
      if (meta.confirmation_sms === 'sent') parts.push('SMS de confirmação enviado.')
      if (meta.confirmation_sms === 'failed' || meta.confirmation_sms === 'not_sent') parts.push('O SMS de confirmação não saiu,confirme no histórico de SMS.')
      if (meta.operator_notified) parts.push('O operador foi avisado.')
      if (meta.staff_notified) parts.push('O posto foi avisado e já recebe os pedidos do município.')
      onDone(parts.join(' '))
    },
  })

  const ready = type === 'SMS_CONSULT' ? digits.length >= 9 : stationId !== ''
  const status = lookupQuery.data?.status

  return (
    <div role="dialog" aria-label="Ativar subscrição" className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">Ativar ou renovar subscrição</h3>
          <button type="button" onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>

        <div className="mt-4 space-y-4">
          <div role="group" aria-label="Tipo de subscrição" className="grid grid-cols-3 gap-1 rounded-lg border border-gray/20 p-0.5">
            {(provincial ? (['STATION_LISTING', 'STATION_ORDERS'] as const) : (['SMS_CONSULT', 'STATION_LISTING', 'STATION_ORDERS'] as const)).map((k) => (
              <button
                key={k}
                type="button"
                aria-pressed={type === k}
                onClick={() => setType(k)}
                className={`rounded-md px-3 py-2 text-sm font-medium ${type === k ? 'bg-primary text-white' : 'text-gray'}`}
              >
                {KIND_LABEL[k]}
              </button>
            ))}
          </div>

          {type === 'SMS_CONSULT' ? (
            <div>
              <label className="block text-sm font-medium text-dark" htmlFor="sub-phone">Telemóvel do cliente</label>
              <input
                id="sub-phone"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="923123456 ou +244923123456"
                className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
              />
              {status && (
                <p className="mt-1.5 rounded-lg bg-light p-2 text-xs text-gray">
                  Consultas este mês: <strong className="text-dark">{status.used} de {status.limit}</strong>
                  {' · '}
                  {status.subscribed ? `subscrito até ${formatDate(status.valid_until!)} (a renovação começa depois)` : 'sem subscrição ativa'}
                </p>
              )}
            </div>
          ) : (
            <div className="space-y-2">
              <label className="block text-sm font-medium text-dark" htmlFor="sub-station-search">Procurar posto</label>
              <input
                id="sub-station-search"
                value={stationSearch}
                onChange={(e) => { setStationSearch(e.target.value); setStationId('') }}
                placeholder="Pesquisar posto por nome..."
                className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
              />
              <select
                aria-label="Posto"
                value={stationId}
                onChange={(e) => setStationId(e.target.value ? Number(e.target.value) : '')}
                className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
              >
                <option value="">Selecione o posto...</option>
                {(stationsQuery.data?.data ?? []).map((s) => (
                  <option key={s.id} value={s.id}>{s.name}{s.operator_name ? `,${s.operator_name}` : ',sem operador'}</option>
                ))}
              </select>
            </div>
          )}

          <div className="grid grid-cols-2 gap-3">
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">Duração</span>
              {porSemana ? (
                <select aria-label="Semanas" value={weeks} onChange={(e) => setWeeks(Number(e.target.value))} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
                  {WEEK_BLOCKS.map((w) => <option key={w} value={w}>{weeksLabel(w)}</option>)}
                </select>
              ) : (
                <select value={months} onChange={(e) => setMonths(Number(e.target.value))} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm">
                  {[1, 2, 3, 6, 12].map((m) => <option key={m} value={m}>{m} {m === 1 ? 'mês' : 'meses'}</option>)}
                </select>
              )}
            </label>
            <label className="block text-sm">
              <span className="mb-1 block font-medium text-dark">Valor pago (Kz)</span>
              <input type="number" min={0} inputMode="numeric" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder={porSemana && weekPrice > 0 ? `${(weeks * weekPrice).toLocaleString('pt-AO')} (automático)` : 'opcional'} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
            </label>
          </div>

          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Comprovativo / referência</span>
            <input value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Ex.: Transferência BAI, WhatsApp 04/10" className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Notas internas</span>
            <textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          </label>

          {type === 'SMS_CONSULT' ? (
            <label className="flex items-start gap-2 text-sm text-dark">
              <input type="checkbox" checked={sendSms} onChange={(e) => setSendSms(e.target.checked)} className="mt-0.5" />
              <span>Enviar SMS de confirmação ao cliente <span className="block text-xs text-gray">Usa o template "Subscrição ativada" e gasta 1 SMS.</span></span>
            </label>
          ) : type === 'STATION_ORDERS' ? (
            <p className="rounded-lg bg-light p-3 text-xs text-gray">
              O posto passa a ver e a aceitar os pedidos de gás do seu município até ao fim do prazo (quem aceitar primeiro fica com o pedido) e desliga-se sozinho no fim. Os gerentes e operadores do posto são avisados. Se o posto já tiver dias pagos, o novo bloco começa quando esses acabam.
            </p>
          ) : (
            <p className="rounded-lg bg-light p-3 text-xs text-gray">
              Mantém o posto visível no mapa e nas pesquisas, e traz o <strong className="text-dark">analytics e a exportação incluídos</strong>. Enquanto a cobrança da presença estiver desligada nas Definições, todos os postos aparecem de qualquer forma,a subscrição fica registada e começa a valer no dia em que a ligar.
            </p>
          )}

          {mutation.isError && <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível ativar a subscrição.')}</p>}

          <div className="flex justify-end gap-2">
            <button type="button" onClick={onClose} className="rounded-lg border border-gray/20 px-4 py-2 text-sm font-semibold text-dark">Cancelar</button>
            <button
              type="button"
              onClick={() => mutation.mutate()}
              disabled={!ready || mutation.isPending}
              className="flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            >
              {mutation.isPending && <Loader2 size={14} className="animate-spin" />} Ativar subscrição
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ */
/* Definições e pagamento                                              */
/* ------------------------------------------------------------------ */

function SettingsPanel() {
  const queryClient = useQueryClient()
  const query = useQuery({ queryKey: ['subscription-settings'], queryFn: subscriptionService.settings })
  const [form, setForm] = useState<SubscriptionSettings | null>(null)
  const [saved, setSaved] = useState(false)

  useEffect(() => {
    if (query.data && !form) setForm(query.data)
  }, [query.data, form])

  const mutation = useMutation({
    mutationFn: (payload: SubscriptionSettings) => subscriptionService.updateSettings({
      sms_enforcement: payload.sms_enforcement,
      free_consults_per_month: payload.free_consults_per_month,
      bank_name: payload.bank_name ?? '',
      account_holder: payload.account_holder ?? '',
      iban: payload.iban ?? '',
      contact: payload.contact ?? '',
      price_sms_kz: payload.price_sms_kz ?? 0,
      price_station_listing_week_kz: payload.price_station_listing_week_kz ?? null,
      listing_enforcement: payload.listing_enforcement ?? false,
      listing_grace_days: payload.listing_grace_days ?? 4,
      price_station_orders_week_kz: payload.price_station_orders_week_kz || 1000,
    }),
    onSuccess: (data) => {
      setForm(data)
      setSaved(true)
      void queryClient.invalidateQueries({ queryKey: ['subscription-settings'] })
    },
  })

  if (query.isLoading || !form) return <p className="text-sm text-gray">A carregar definições...</p>

  const set = <K extends keyof SubscriptionSettings>(key: K, value: SubscriptionSettings[K]) => {
    setSaved(false)
    setForm({ ...form, [key]: value })
  }

  return (
    <form
      className="max-w-2xl space-y-6"
      onSubmit={(e) => { e.preventDefault(); mutation.mutate(form) }}
    >
      <section className="rounded-xl border border-gray/15 bg-white p-5">
        <h2 className="text-sm font-bold text-dark">Presença dos postos na aplicação</h2>
        <label className="mt-3 flex items-start gap-3 text-sm text-dark">
          <input
            type="checkbox"
            checked={form.listing_enforcement ?? false}
            onChange={(e) => set('listing_enforcement', e.target.checked)}
            className="mt-1"
          />
          <span>
            Exigir subscrição para o posto aparecer na aplicação
            <span className="block text-xs text-gray">
              Desligado: todos os postos aparecem, paguem ou não (comportamento actual). Ligado: um posto sem subscrição
              deixa de aparecer no mapa e nas pesquisas, e perde o analytics,excepto os marcados como postos da própria
              KeruGás, que nunca saem.
            </span>
          </span>
        </label>

        <label className="mt-4 block text-sm">
          <span className="mb-1 block font-medium text-dark">Dias de tolerância depois de expirar</span>
          <input
            type="number"
            min={0}
            max={60}
            aria-label="Dias de tolerância da presença"
            value={form.listing_grace_days ?? 4}
            onChange={(e) => set('listing_grace_days', e.target.value ? Number(e.target.value) : 0)}
            className="w-32 rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
          <span className="mt-1 block text-xs text-gray">
            O posto continua visível este número de dias depois de a subscrição expirar, e o operador é avisado com a data em
            que sai. Zero faz sair no próprio dia.
          </span>
        </label>

        <p className="mt-4 rounded-lg bg-light p-3 text-xs text-gray">
          <strong className="text-dark">Antes de ligar:</strong> a tolerância conta a partir da data em que a subscrição
          expira, portanto um posto que nunca subscreveu sai assim que carregar aqui. Marque primeiro como
          “Aparece sempre”, na página dos Postos, os que quer manter.
        </p>
      </section>

      <section className="rounded-xl border border-gray/15 bg-white p-5">
        <h2 className="text-sm font-bold text-dark">Consulta de gás por SMS</h2>
        <label className="mt-3 flex items-start gap-3 text-sm text-dark">
          <input
            type="checkbox"
            checked={form.sms_enforcement}
            onChange={(e) => set('sms_enforcement', e.target.checked)}
            className="mt-1"
          />
          <span>
            Exigir subscrição depois das consultas grátis
            <span className="block text-xs text-gray">
              Desligado: ninguém é bloqueado (comportamento atual). Ligado: quem já usou as consultas grátis do mês só recebe
              resposta de consulta se tiver subscrição ativa; a Central sugere o template de subscrição.
            </span>
          </span>
        </label>
        <label className="mt-4 block text-sm">
          <span className="mb-1 block font-medium text-dark">Consultas grátis por mês (por telemóvel)</span>
          <input
            type="number" min={0} max={100}
            value={form.free_consults_per_month}
            onChange={(e) => set('free_consults_per_month', Number(e.target.value))}
            className="w-28 rounded-lg border border-gray/20 px-3 py-2 text-sm"
          />
        </label>
      </section>

      <section className="rounded-xl border border-gray/15 bg-white p-5">
        <h2 className="text-sm font-bold text-dark">Dados para pagamento</h2>
        <p className="mt-1 text-xs text-gray">
          O cliente pede o IBAN pelo contacto abaixo, paga por transferência e envia o comprovativo. Os operadores veem estes dados no seu painel.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <TextField label="Banco" value={form.bank_name} onChange={(v) => set('bank_name', v)} />
          <TextField label="Titular da conta" value={form.account_holder} onChange={(v) => set('account_holder', v)} />
          <div className="sm:col-span-2">
            <TextField label="IBAN" value={form.iban} onChange={(v) => set('iban', v)} placeholder="AO06 0000 0000 0000 0000 0000 0" />
          </div>
          <div className="sm:col-span-2">
            <TextField label="Contacto para o comprovativo (aparece nos SMS)" value={form.contact} onChange={(v) => set('contact', v)} placeholder="+244923000000 (WhatsApp)" />
          </div>
        </div>
      </section>

      <section className="rounded-xl border border-gray/15 bg-white p-5">
        <h2 className="text-sm font-bold text-dark">Preços (opcional)</h2>
        <p className="mt-1 text-xs text-gray">
          Os SMS e avisos não mostram preço. Enquanto o valor não estiver definido, a variável <code>{'{{valor}}'}</code> dos templates fica vazia; só passa a ter texto quando preencher aqui.
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-2">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Consulta por SMS (Kz/mês)</span>
            <input type="number" min={0} value={form.price_sms_kz ?? ''} onChange={(e) => set('price_sms_kz', e.target.value ? Number(e.target.value) : null)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Presença na aplicação (Kz/semana)</span>
            <input type="number" min={0} aria-label="Preço semanal da presença na aplicação" value={form.price_station_listing_week_kz ?? ''} onChange={(e) => set('price_station_listing_week_kz', e.target.value ? Number(e.target.value) : null)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
            <span className="mt-1 block text-xs text-gray">Inclui o analytics e a exportação do posto.</span>
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Pedidos do município para postos (Kz/semana)</span>
            <input type="number" min={1} aria-label="Preço semanal dos pedidos do município" value={form.price_station_orders_week_kz ?? 1000} onChange={(e) => set('price_station_orders_week_kz', e.target.value ? Number(e.target.value) : 1000)} className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
            <span className="mt-1 block text-xs text-gray">O valor de cada bloco é este preço × semanas.</span>
          </label>
        </div>
      </section>

      {mutation.isError && <p className="text-sm text-danger">{getApiErrorMessage(mutation.error, 'Não foi possível guardar as definições.')}</p>}
      {saved && <p role="status" className="text-sm text-success">Definições guardadas.</p>}

      <button
        type="submit"
        disabled={mutation.isPending}
        className="flex items-center gap-2 rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white disabled:opacity-50"
      >
        {mutation.isPending && <Loader2 size={14} className="animate-spin" />} Guardar definições
      </button>
    </form>
  )
}

function TextField({ label, value, onChange, placeholder }: { label: string; value: string | null; onChange: (v: string) => void; placeholder?: string }) {
  return (
    <label className="block text-sm">
      <span className="mb-1 block font-medium text-dark">{label}</span>
      <input
        value={value ?? ''}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
      />
    </label>
  )
}
