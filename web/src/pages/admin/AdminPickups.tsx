import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { RotateCcw, Pencil } from 'lucide-react'
import { adminService } from '@/services/adminService'
import { useAuth } from '@/hooks/useAuth'
import { parseKz } from '@/lib/kz'
import type { BottlePickup } from '@/types'
import { hasSuperPowers } from '@/lib/roles'
import { DriverStockAlerts } from '@/components/admin/DriverStockAlerts'
import { CentralPickups } from '@/components/admin/CentralPickups'
import { getApiErrorMessage } from '@/lib/api'

function formatKz(v: number) {
  return `${v.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`
}

const CHANNEL_LABEL: Record<string, string> = { PHONE: 'telefonema', IN_PERSON: 'presencial', SMS: 'SMS', OTHER: 'outro' }

const STATE_LABEL: Record<BottlePickup['state'], { text: string; cls: string }> = {
  CONFIRMED: { text: 'Confirmado pelo posto', cls: 'bg-success/10 text-success' },
  DRIVER_ONLY: { text: 'Só condutor', cls: 'bg-amber-100 text-amber-800' },
  DISPUTED: { text: 'Contestado', cls: 'bg-danger/10 text-danger' },
}

/**
 * Carregamentos nos postos (Outubro 2026): quantas garrafas a KeruGás levou
 * de cada posto (contador desde o último reinício manual) e cada registo dos
 * condutores. Admin vê os condutores da sua província; só o Super Admin
 * reinicia contadores (o histórico fica guardado).
 */
export default function AdminPickups() {
  const { user } = useAuth()
  const isSuperAdmin = hasSuperPowers(user)
  const queryClient = useQueryClient()
  const [date, setDate] = useState('')
  const [resetting, setResetting] = useState<{ id: number; name: string; count: number } | null>(null)
  const [editing, setEditing] = useState<number | null>(null)
  const [amount, setAmount] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['admin-pickups', date],
    queryFn: () => adminService.pickups(date ? { date } : {}),
  })

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['admin-pickups'] })
    void queryClient.invalidateQueries({ queryKey: ['admin-billing'] })
  }

  const resetMutation = useMutation({
    mutationFn: (id: number) => adminService.resetStationCounter(id),
    onSuccess: () => { setResetting(null); refresh() },
  })

  const amountMutation = useMutation({
    mutationFn: ({ id, value }: { id: number; value: number }) => adminService.correctPickupAmount(id, value),
    onSuccess: () => { setEditing(null); refresh() },
  })

  const confirmMutation = useMutation({
    mutationFn: (id: number) => adminService.confirmCentralPickup(id),
    onSuccess: refresh,
  })

  if (isLoading || !data) return <div className="text-gray">A carregar carregamentos...</div>

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-xl font-bold text-dark">Carregamentos nos postos</h1>
        <p className="mt-2 text-xs text-gray">
          O condutor regista quantas garrafas levou de cada posto; o operador do posto confirma quando tem internet.
          {isSuperAdmin ? ' Só o Super Admin reinicia o contador de um posto.' : ' Mostra os condutores da sua província.'}
        </p>
      </div>

      <DriverStockAlerts />

      <CentralPickups />

      <div className="rounded-xl border border-gray/15 bg-white p-5">
        <p className="mb-4 font-semibold text-dark">Contador por posto</p>
        {data.counters.length === 0 ? (
          <p className="text-sm text-gray">Ainda não há carregamentos.</p>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2">
            {data.counters.map((c) => (
              <div key={c.station_id} className="rounded-lg border border-gray/10 p-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="font-medium text-dark">{c.station_name}</p>
                    <p className="text-3xl font-bold text-dark">{c.since_reset}</p>
                    <p className="text-xs text-gray">
                      garrafas desde {c.last_reset_at ? new Date(c.last_reset_at).toLocaleString('pt-PT') : 'o início'} · hoje {c.today}
                    </p>
                    {c.pending_confirmation > 0 && <p className="text-xs text-amber-700">{c.pending_confirmation} por confirmar pelo posto</p>}
                  </div>
                  {isSuperAdmin && (
                    <button
                      onClick={() => setResetting({ id: c.station_id, name: c.station_name, count: c.since_reset })}
                      aria-label={`Reiniciar contador ${c.station_name}`}
                      className="flex items-center gap-1 rounded-lg border border-gray/20 px-2 py-1 text-xs font-medium text-dark"
                    >
                      <RotateCcw size={12} /> Reiniciar
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="rounded-xl border border-gray/15 bg-white p-5">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
          <p className="font-semibold text-dark">Registos dos condutores</p>
          <label className="text-sm text-gray">
            Dia{' '}
            <input type="date" value={date} onChange={(e) => setDate(e.target.value)} className="rounded-lg border border-gray/20 px-2 py-1 text-sm text-dark" />
            {date && <button onClick={() => setDate('')} className="ml-2 text-xs text-primary">Todos</button>}
          </label>
        </div>
        {data.pickups.length === 0 ? (
          <p className="text-sm text-gray">Sem registos.</p>
        ) : (
          <ul className="space-y-2">
            {data.pickups.map((p) => (
              <li key={p.id} className="rounded-lg border border-gray/10 p-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="text-dark"><strong>{p.quantity}</strong> garrafa(s) · {p.station_code ? `${p.station_code} · ` : ''}{p.station_name} · {p.driver_name}</span>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${p.awaiting_confirmation ? 'bg-amber-100 text-amber-800' : STATE_LABEL[p.state].cls}`}>
                    {p.awaiting_confirmation ? (p.source === 'SMS' ? 'SMS com PIN provisório,por confirmar' : 'Registado pela central,por confirmar') : STATE_LABEL[p.state].text}
                  </span>
                </div>
                {p.source === 'CENTRAL' && (
                  <p className="mt-1 text-xs text-amber-800">
                    Registado pela central por {p.registered_by_name ?? '—'}{p.central_channel ? ` (${CHANNEL_LABEL[p.central_channel] ?? p.central_channel})` : ''}: {p.central_reason}
                  </p>
                )}
                {p.source === 'SMS' && <p className="mt-1 text-xs text-gray">Pedido por SMS do condutor, registado pela central{p.registered_by_name ? ` (${p.registered_by_name})` : ''}</p>}
                {p.awaiting_confirmation && isSuperAdmin && p.registered_by_id !== user?.id && (
                  <button
                    type="button"
                    onClick={() => confirmMutation.mutate(p.id)}
                    disabled={confirmMutation.isPending}
                    className="mt-1 rounded-lg bg-success/10 px-2.5 py-1 text-xs font-semibold text-success"
                  >
                    Confirmar este carregamento
                  </button>
                )}
                <p className="text-xs text-gray">
                  {p.registered_at ? new Date(p.registered_at).toLocaleString('pt-PT') : ''}
                  {p.synced_offline ? ' · registado sem internet' : ''}
                  {p.dispute_note ? ` · posto diz: ${p.dispute_note}` : ''}
                </p>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                  <span className="text-dark">Valor a pagar ao posto: <strong>{formatKz(p.amount)}</strong></span>
                  {p.station_paid_at ? (
                    <span className="text-xs text-success">Pago</span>
                  ) : editing === p.id ? (
                    <>
                      <input
                        aria-label="Novo valor"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        inputMode="decimal"
                        className="w-28 rounded-lg border border-gray/20 px-2 py-1 text-sm"
                      />
                      <button
                        onClick={() => { const v = parseKz(amount); if (v !== null) amountMutation.mutate({ id: p.id, value: v }) }}
                        disabled={amountMutation.isPending}
                        className="rounded-lg bg-primary px-2.5 py-1 text-xs font-semibold text-white"
                      >
                        Guardar
                      </button>
                      <button onClick={() => setEditing(null)} className="text-xs text-gray">Cancelar</button>
                    </>
                  ) : isSuperAdmin ? (
                    // Corrigir o valor do condutor: só Super Admin Provincial e Geral (Outubro 2026).
                    <button onClick={() => { setEditing(p.id); setAmount(String(p.amount)) }} className="flex items-center gap-1 text-xs font-medium text-primary">
                      <Pencil size={12} /> Corrigir valor
                    </button>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        )}
        {confirmMutation.isError && <p role="alert" className="mt-2 text-xs text-danger">{getApiErrorMessage(confirmMutation.error, 'Não foi possível confirmar.')}</p>}
        {amountMutation.isError && <p className="mt-2 text-xs text-danger">Não foi possível corrigir o valor.</p>}
      </div>

      {data.resets.length > 0 && (
        <div className="rounded-xl border border-gray/15 bg-white p-5">
          <p className="mb-3 font-semibold text-dark">Histórico de reinícios</p>
          <ul className="space-y-1 text-sm">
            {data.resets.map((r) => (
              <li key={r.id} className="text-gray">
                <span className="text-dark">{r.station_name}</span> · {r.bottles_before} garrafas · {r.reset_by ?? '—'} · {r.reset_at ? new Date(r.reset_at).toLocaleString('pt-PT') : ''}
              </li>
            ))}
          </ul>
        </div>
      )}

      {resetting && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
          <div className="w-full max-w-sm rounded-2xl bg-white p-6">
            <h3 className="font-semibold text-dark">Reiniciar contador</h3>
            <p className="mt-2 text-sm text-gray">
              O contador de <strong className="text-dark">{resetting.name}</strong> ({resetting.count} garrafas) volta a 0. Os registos e o histórico ficam guardados.
            </p>
            {resetMutation.isError && <p className="mt-2 text-xs text-danger">{getApiErrorMessage(resetMutation.error, 'Não foi possível reiniciar.')}</p>}
            <div className="mt-4 flex justify-end gap-2">
              <button onClick={() => setResetting(null)} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm">Cancelar</button>
              <button onClick={() => resetMutation.mutate(resetting.id)} disabled={resetMutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white">
                Reiniciar
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}
