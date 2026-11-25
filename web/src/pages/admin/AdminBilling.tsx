import { useState, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend,
} from 'recharts'
import { X, Download } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { theme } from '@/constants/theme'
import { useAuth } from '@/hooks/useAuth'
import { parseKz } from '@/lib/kz'
import type { BillingLedgerEntry, BillingLegacyOrder } from '@/types'
import { getApiErrorMessage } from '@/lib/api'
import { hasSuperPowers } from '@/lib/roles'

function formatDay(dateStr: string) {
  const [y, m, d] = dateStr.split('-')
  return y && m && d ? `${d}/${m}` : dateStr
}

function formatKz(value: number): string {
  return `${value.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`
}

/** Hoje no dia civil de Angola (a faturação conta por dia 00h–24h de Luanda). */
function todayInLuanda(): string {
  return new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Luanda' })
}

const LEDGER_LABEL: Record<BillingLedgerEntry['action'], string> = {
  'billing.station_paid': 'Pago ao posto',
  'billing.station_payment_undone': 'Pagamento ao posto desfeito',
  'billing.cash_received': 'Dinheiro recebido de condutor',
  'billing.cash_receipt_reversed': 'Recebimento estornado',
}

/**
 * Faturação,Quero Gás como INTERMEDIÁRIA (Outubro 2026).
 * Pedidos: só ENTREGUES contam, pelo dia da entrega (00h–24h, hora de Angola);
 * cada pedido = valor das garrafas + comissão. Cada Admin vê só os pedidos
 * que confirmou. Postos: o que se deve vem dos CARREGAMENTOS (o condutor
 * vai buscar garrafas ao posto); "pago ao posto" é só pago/não pago.
 * Condutores: recebimentos parciais. Tudo fica no registo do Super Admin.
 */
export default function AdminBilling() {
  const { user } = useAuth()
  const isSuperAdmin = hasSuperPowers(user)
  const queryClient = useQueryClient()
  const [date, setDate] = useState(todayInLuanda())
  const [payingStation, setPayingStation] = useState<{ id: number; name: string; total: number } | null>(null)
  const [completing, setCompleting] = useState<BillingLegacyOrder | null>(null)

  const { data, isLoading } = useQuery({ queryKey: ['admin-billing', date], queryFn: () => adminService.billing(date) })
  const ledgerQuery = useQuery({ queryKey: ['admin-billing-ledger'], queryFn: adminService.billingLedger, enabled: isSuperAdmin })

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['admin-billing'] })
    queryClient.invalidateQueries({ queryKey: ['admin-billing-ledger'] })
  }

  const payMutation = useMutation({
    mutationFn: (stationId: number) => adminService.payStation(stationId),
    onSuccess: () => { setPayingStation(null); refresh() },
  })

  const undoMutation = useMutation({
    mutationFn: (id: number) => adminService.undoBillingLedger(id),
    onSuccess: refresh,
  })

  if (isLoading || !data) {
    return <div className="text-gray">A carregar faturação...</div>
  }

  const daily = data.daily.map((d) => ({ ...d, day: formatDay(d.date) }))
  const s = data.summary

  return (
    <div className="space-y-8">
      <div>
        <div className="flex flex-wrap items-end justify-between gap-3">
          <h1 className="text-xl font-bold text-dark">Faturação</h1>
          <label className="text-sm text-gray">
            Dia{' '}
            <input
              type="date"
              value={date}
              max={todayInLuanda()}
              onChange={(e) => e.target.value && setDate(e.target.value)}
              className="rounded-lg border border-gray/20 px-2 py-1 text-sm text-dark"
            />
          </label>
        </div>
        <p className="mt-3 rounded-lg bg-light p-3 text-xs text-gray">
          Só contam pedidos entregues, pelo dia da entrega (00h–24h, hora de Angola).{' '}
          {data.scope === 'own'
            ? 'Pedidos: só os que a sua conta confirmou. Postos e condutores: os da sua província.'
            : data.scope === 'province'
              ? `A mostrar toda a província${user?.province ? ` de ${user.province}` : ''},todos os Admins, postos e condutores (Super Admin Provincial).`
              : 'A mostrar tudo,âmbito de Super Admin.'}
        </p>

        <div className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <Kpi label="Cobrado aos clientes" value={formatKz(s.collected_total)} />
          <Kpi label="Valor das garrafas (dia)" value={formatKz(s.bottles_total)} />
          <Kpi label="Comissão Quero Gás (dia)" value={formatKz(s.commission_total)} highlight />
          <Kpi label="Entregas / garrafas" value={`${s.orders} / ${s.bottles}`} />
        </div>
        <p className="mt-3 text-sm text-dark">
          Carregamentos do dia: <strong>{formatKz(s.stations_day_total)}</strong>
          {' · '}Dívida total aos postos (por pagar): <strong>{formatKz(s.stations_unpaid_total)}</strong>
        </p>
        {s.legacy_orders > 0 && (
          <p className="mt-1 text-xs text-amber-700">{s.legacy_orders} entrega(s) antiga(s) neste dia sem divisão ({formatKz(s.legacy_total)}),ver "Por completar".</p>
        )}
      </div>

      <ReportsCard date={date} />

      <Card title="Postos (carregamentos)">
        {data.stations.length === 0 ? (
          <p className="text-sm text-gray">Sem carregamentos neste dia nem por pagar.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead>
                <tr className="border-b border-gray/10 text-xs uppercase tracking-wide text-gray">
                  <th className="pb-2 pr-4">Posto</th>
                  <th className="pb-2 pr-4">Garrafas levadas (dia)</th>
                  <th className="pb-2 pr-4">Valor do dia</th>
                  <th className="pb-2 pr-4">Por pagar</th>
                  <th className="pb-2" />
                </tr>
              </thead>
              <tbody>
                {data.stations.map((r) => (
                  <tr key={r.station_id} className="border-b border-gray/5 last:border-0">
                    <td className="py-2 pr-4 text-dark">{r.station_name}</td>
                    <td className="py-2 pr-4 text-gray">{r.day_bottles}</td>
                    <td className="py-2 pr-4 text-gray">{formatKz(r.day_total)}</td>
                    <td className="py-2 pr-4 font-semibold text-dark">
                      {formatKz(r.unpaid_total)}
                      {r.unconfirmed_pickups > 0 && (
                        <span className="block text-xs font-normal text-amber-700">{r.unconfirmed_pickups} sem confirmação do posto</span>
                      )}
                    </td>
                    <td className="py-2 text-right">
                      {r.unpaid_total > 0 ? (
                        <button
                          onClick={() => setPayingStation({ id: r.station_id, name: r.station_name, total: r.unpaid_total })}
                          className="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white"
                          aria-label={`Marcar pago ${r.station_name}`}
                        >
                          Marcar pago
                        </button>
                      ) : (
                        <span className="text-xs text-gray">Pago</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card title="Condutores: dinheiro por entregar e garrafas">
        {data.drivers.length === 0 ? (
          <p className="text-sm text-gray">Nenhum condutor com entregas ainda.</p>
        ) : (
          <div className="space-y-3">
            {data.drivers.map((d) => (
              <DriverCashRow key={d.driver_id} driver={d} onDone={refresh} />
            ))}
          </div>
        )}
      </Card>

      {data.legacy.count > 0 && (
        <Card title={`Por completar (${data.legacy.count})`}>
          <p className="mb-3 text-xs text-gray">
            Pedidos antigos entregues só com o total. Indique as garrafas e a divisão,a soma tem de igualar o total já registado.
          </p>
          <div className="space-y-2">
            {data.legacy.items.map((o) => (
              <div key={o.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray/10 p-3 text-sm">
                <span className="text-dark">{o.order_reference} · {formatKz(o.delivery_price)} · {o.quantity} garrafa(s)</span>
                <button onClick={() => setCompleting(o)} className="rounded-lg border border-primary/40 px-2.5 py-1 text-xs font-medium text-primary">
                  Completar
                </button>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card title="Últimos 14 dias">
        <ResponsiveContainer width="100%" height={260}>
          <BarChart data={daily}>
            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
            <XAxis dataKey="day" fontSize={12} stroke={theme.gray} />
            <YAxis fontSize={12} stroke={theme.gray} />
            <Tooltip formatter={(value: number) => formatKz(value)} />
            <Legend />
            <Bar dataKey="bottles" stackId="a" fill={theme.gray} name="Valor das garrafas" />
            <Bar dataKey="commission" stackId="a" fill={theme.primary} radius={[4, 4, 0, 0]} name="Comissão" />
          </BarChart>
        </ResponsiveContainer>
      </Card>

      {isSuperAdmin && data.by_admin && (
        <Card title="Por Admin (dia)">
          {data.by_admin.length === 0 ? (
            <p className="text-sm text-gray">Sem entregas neste dia.</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead>
                  <tr className="border-b border-gray/10 text-xs uppercase tracking-wide text-gray">
                    <th className="pb-2 pr-4">Admin</th>
                    <th className="pb-2 pr-4">Província</th>
                    <th className="pb-2 pr-4">Entregas</th>
                    <th className="pb-2 pr-4">Total</th>
                    <th className="pb-2">Comissão</th>
                  </tr>
                </thead>
                <tbody>
                  {data.by_admin.map((row) => (
                    <tr key={row.admin_id} className="border-b border-gray/5 last:border-0">
                      <td className="py-2 pr-4 text-dark">{row.admin_name}</td>
                      <td className="py-2 pr-4 text-gray">{row.province ?? '—'}</td>
                      <td className="py-2 pr-4 text-gray">{row.count}</td>
                      <td className="py-2 pr-4 text-dark">{formatKz(row.total)}</td>
                      <td className="py-2 font-semibold text-dark">{formatKz(row.commission)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {isSuperAdmin && (
        <Card title="Registo de pagamentos e recebimentos">
          {(ledgerQuery.data ?? []).length === 0 ? (
            <p className="text-sm text-gray">Ainda sem registos.</p>
          ) : (
            <ul className="space-y-2">
              {(ledgerQuery.data ?? []).map((e) => (
                <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-gray/10 p-3 text-sm">
                  <div>
                    <p className="text-dark">
                      {LEDGER_LABEL[e.action]}
                      {typeof e.details.amount === 'number' && ` · ${formatKz(e.details.amount)}`}
                      {typeof e.details.station_name === 'string' && ` · ${e.details.station_name}`}
                      {typeof e.details.driver_name === 'string' && ` · ${e.details.driver_name}`}
                      {e.undone && <span className="ml-2 rounded-full bg-light px-2 py-0.5 text-xs text-gray">desfeito</span>}
                    </p>
                    <p className="text-xs text-gray">{e.by}{e.at ? ` · ${new Date(e.at).toLocaleString('pt-PT')}` : ''}</p>
                  </div>
                  {e.can_undo && (
                    <button
                      onClick={() => undoMutation.mutate(e.id)}
                      disabled={undoMutation.isPending}
                      className="rounded-lg border border-danger/40 px-2.5 py-1 text-xs font-medium text-danger disabled:opacity-60"
                    >
                      Desfazer
                    </button>
                  )}
                </li>
              ))}
            </ul>
          )}
          {undoMutation.isError && <p className="mt-2 text-xs text-danger">Não foi possível desfazer este registo.</p>}
        </Card>
      )}

      {payingStation && (
        <Modal title="Marcar como pago ao posto" onClose={() => setPayingStation(null)}>
          <p className="text-sm text-gray">
            Confirma que pagou <strong className="text-dark">{formatKz(payingStation.total)}</strong> ao posto{' '}
            <strong className="text-dark">{payingStation.name}</strong> (todos os carregamentos por pagar)? Fica registado em seu nome e o Super Admin é avisado.
          </p>
          {payMutation.isError && <p className="mt-2 text-xs text-danger">{getApiErrorMessage(payMutation.error, 'Não foi possível registar o pagamento.')}</p>}
          <div className="mt-4 flex justify-end gap-2">
            <button onClick={() => setPayingStation(null)} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">Cancelar</button>
            <button
              onClick={() => payMutation.mutate(payingStation.id)}
              disabled={payMutation.isPending}
              className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
            >
              {payMutation.isPending ? 'A registar...' : 'Confirmar pagamento'}
            </button>
          </div>
        </Modal>
      )}

      {completing && (
        <CompleteLegacyModal order={completing} onClose={() => setCompleting(null)} onDone={() => { setCompleting(null); refresh() }} />
      )}
    </div>
  )
}

/**
 * Relatórios (Outubro 2026): fecho do dia (do dia escolhido acima), dívida aos
 * postos e dinheiro dos condutores num período,em Excel ou PDF.
 */
function ReportsCard({ date }: { date: string }) {
  const firstOfMonth = `${date.slice(0, 8)}01`
  const [from, setFrom] = useState(firstOfMonth)
  const [to, setTo] = useState(date)
  const [error, setError] = useState<string | null>(null)
  const mutation = useMutation({
    mutationFn: (p: Parameters<typeof adminService.exportBillingReport>[0]) => adminService.exportBillingReport(p),
    onMutate: () => setError(null),
    onError: () => setError('Não foi possível gerar o relatório. Se for PDF, o servidor pode ainda não ter o gerador de PDF instalado.'),
  })

  const buttons = (type: 'day' | 'stations' | 'drivers', extra: { date?: string; from?: string; to?: string }, label: string) => (
    <div className="flex flex-wrap gap-2">
      {(['csv', 'pdf'] as const).map((format) => (
        <button
          key={format}
          onClick={() => mutation.mutate({ type, format, ...extra })}
          disabled={mutation.isPending}
          aria-label={`${label} em ${format === 'csv' ? 'Excel' : 'PDF'}`}
          className="flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-1.5 text-xs font-medium text-dark disabled:opacity-60"
        >
          <Download size={13} /> {format === 'csv' ? 'Excel' : 'PDF'}
        </button>
      ))}
    </div>
  )

  return (
    <Card title="Relatórios">
      <div className="grid gap-4 md:grid-cols-3">
        <div className="rounded-lg border border-gray/10 p-3">
          <p className="text-sm font-medium text-dark">Fecho do dia</p>
          <p className="mb-2 text-xs text-gray">Entregas, carregamentos e condutores de {formatDay(date)}.</p>
          {buttons('day', { date }, 'Fecho do dia')}
        </div>
        <div className="rounded-lg border border-gray/10 p-3">
          <p className="text-sm font-medium text-dark">Dívida aos postos</p>
          <p className="mb-2 text-xs text-gray">O que falta pagar a cada posto, carregamento a carregamento.</p>
          {buttons('stations', {}, 'Dívida aos postos')}
        </div>
        <div className="rounded-lg border border-gray/10 p-3">
          <p className="text-sm font-medium text-dark">Dinheiro dos condutores</p>
          <div className="mb-2 mt-1 flex flex-wrap items-center gap-1 text-xs text-gray">
            <input type="date" aria-label="Desde" value={from} max={to} onChange={(e) => e.target.value && setFrom(e.target.value)} className="rounded border border-gray/20 px-1 py-0.5 text-dark" />
            a
            <input type="date" aria-label="Até" value={to} min={from} onChange={(e) => e.target.value && setTo(e.target.value)} className="rounded border border-gray/20 px-1 py-0.5 text-dark" />
          </div>
          {buttons('drivers', { from, to }, 'Dinheiro dos condutores')}
        </div>
      </div>
      {mutation.isPending && <p className="mt-2 text-xs text-gray">A gerar o relatório...</p>}
      {error && <p className="mt-2 text-xs text-danger">{error}</p>}
    </Card>
  )
}

function DriverCashRow({ driver, onDone }: { driver: import('@/types').BillingDriverRow; onDone: () => void }) {
  const [amount, setAmount] = useState('')
  const mutation = useMutation({
    mutationFn: (value: number) => adminService.receiveDriverCash(driver.driver_id, value),
    onSuccess: () => { setAmount(''); onDone() },
  })
  const parsed = parseKz(amount)
  const valid = parsed !== null && parsed > 0 && parsed <= driver.outstanding + 0.009

  return (
    <div className="rounded-lg border border-gray/10 p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-medium text-dark">{driver.driver_name}</p>
          <p className="text-xs text-gray">
            Cobrado: {formatKz(driver.collected_total)} · Entregue: {formatKz(driver.received_total)} · Hoje cobrou: {formatKz(driver.collected_day)}
          </p>
          <p className="text-xs text-gray">
            Garrafas disponíveis: <strong className="text-dark">{driver.stock.available}</strong> (em mão {driver.stock.on_hand}, reservadas {driver.stock.reserved})
          </p>
        </div>
        <p className="font-semibold text-dark">Em falta: {formatKz(driver.outstanding)}</p>
      </div>
      {driver.outstanding > 0 && (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <input
            type="text"
            inputMode="decimal"
            aria-label={`Valor recebido de ${driver.driver_name}`}
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            placeholder="Valor recebido (Kz)"
            className="w-40 rounded-lg border border-gray/20 px-2 py-1 text-sm"
          />
          <button
            onClick={() => parsed !== null && mutation.mutate(parsed)}
            disabled={!valid || mutation.isPending}
            className="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white disabled:opacity-60"
          >
            Recebi
          </button>
          <button
            onClick={() => mutation.mutate(driver.outstanding)}
            disabled={mutation.isPending}
            className="rounded-lg border border-gray/20 px-2.5 py-1 text-xs font-medium text-dark disabled:opacity-60"
          >
            Recebi tudo
          </button>
          {mutation.isError && <span className="text-xs text-danger">Valor recusado (acima do que falta?).</span>}
        </div>
      )}
    </div>
  )
}

function CompleteLegacyModal({ order, onClose, onDone }: { order: BillingLegacyOrder; onClose: () => void; onDone: () => void }) {
  const [bottle, setBottle] = useState('')
  const [commission, setCommission] = useState('')
  const [quantity, setQuantity] = useState(String(order.quantity))

  const b = parseKz(bottle)
  const c = parseKz(commission)
  const qty = Number(quantity)
  const sumOk = b !== null && c !== null && Math.abs(b + c - order.delivery_price) < 0.01
  const qtyOk = Number.isInteger(qty) && qty >= order.quantity && qty <= 100
  const valid = sumOk && qtyOk

  const mutation = useMutation({
    mutationFn: () => adminService.completeOrderBilling(order.id, {
      bottle_price: b as number, commission: c as number, quantity: qty,
    }),
    onSuccess: onDone,
  })

  return (
    <Modal title={`Completar ${order.order_reference}`} onClose={onClose}>
      <p className="text-sm text-gray">Total já registado: <strong className="text-dark">{formatKz(order.delivery_price)}</strong></p>
      <label htmlFor="leg-bottle" className="mt-3 block text-xs font-medium text-gray">Valor das garrafas (Kz)</label>
      <input id="leg-bottle" type="text" inputMode="decimal" value={bottle} onChange={(e) => setBottle(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      <label htmlFor="leg-comm" className="mt-3 block text-xs font-medium text-gray">Comissão (Kz)</label>
      <input id="leg-comm" type="text" inputMode="decimal" value={commission} onChange={(e) => setCommission(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      {b !== null && c !== null && !sumOk && (
        <p className="mt-1 text-xs text-danger">A soma ({formatKz(b + c)}) tem de ser igual ao total ({formatKz(order.delivery_price)}).</p>
      )}
      <label htmlFor="leg-qty" className="mt-3 block text-xs font-medium text-gray">Número de garrafas (mínimo {order.quantity})</label>
      <input id="leg-qty" type="number" min={order.quantity} value={quantity} onChange={(e) => setQuantity(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
      {mutation.isError && <p className="mt-2 text-xs text-danger">Não foi possível guardar. Verifique os valores.</p>}
      <div className="mt-4 flex justify-end gap-2">
        <button onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">Cancelar</button>
        <button onClick={() => valid && mutation.mutate()} disabled={!valid || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60">
          {mutation.isPending ? 'A guardar...' : 'Guardar'}
        </button>
      </div>
    </Modal>
  )
}

function Modal({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[92vh] w-full max-w-sm overflow-y-auto rounded-2xl bg-white p-6">
        <div className="mb-3 flex items-center justify-between">
          <h3 className="font-semibold text-dark">{title}</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        {children}
      </div>
    </div>
  )
}

function Kpi({ label, value, highlight }: { label: string; value: string; highlight?: boolean }) {
  return (
    <div className={`rounded-xl border bg-white p-4 ${highlight ? 'border-primary/40' : 'border-gray/15'}`}>
      <p className="text-xs text-gray">{label}</p>
      <p className="mt-1 text-xl font-bold text-dark">{value}</p>
    </div>
  )
}

function Card({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="rounded-xl border border-gray/15 bg-white p-5">
      <p className="mb-4 font-semibold text-dark">{title}</p>
      {children}
    </div>
  )
}
