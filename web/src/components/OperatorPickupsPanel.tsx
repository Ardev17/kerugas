import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Package } from 'lucide-react'
import { operatorService } from '@/services/operatorService'
import type { BottlePickup } from '@/types'

const STATE_LABEL: Record<BottlePickup['state'], { text: string; cls: string }> = {
  CONFIRMED: { text: 'Confirmado', cls: 'bg-success/10 text-success' },
  DRIVER_ONLY: { text: 'Por confirmar', cls: 'bg-amber-100 text-amber-800' },
  DISPUTED: { text: 'Contestado', cls: 'bg-danger/10 text-danger' },
}

/**
 * Aba do operador "Garrafas levadas pela KeruGás" (Outubro 2026): contador
 * do posto (desde o último reinício do Super Admin) e os carregamentos que
 * os condutores registaram, para confirmar ou contestar.
 */
export function OperatorPickupsPanel() {
  const queryClient = useQueryClient()
  const [disputing, setDisputing] = useState<number | null>(null)
  const [note, setNote] = useState('')
  const { data, isLoading } = useQuery({ queryKey: ['operator-pickups'], queryFn: operatorService.pickups, refetchInterval: 60_000 })

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['operator-pickups'] })
  const confirmMutation = useMutation({ mutationFn: (id: number) => operatorService.confirmPickup(id), onSuccess: refresh })
  const disputeMutation = useMutation({
    mutationFn: ({ id, text }: { id: number; text: string }) => operatorService.disputePickup(id, text),
    onSuccess: () => { setDisputing(null); setNote(''); void refresh() },
  })

  if (isLoading || !data) return <p className="mt-6 text-sm text-gray">A carregar...</p>

  const c = data.counter

  return (
    <div className="mt-6 space-y-4">
      <div className="rounded-xl border border-gray/15 bg-white p-5">
        <p className="flex items-center gap-1.5 text-sm text-gray"><Package size={16} /> Garrafas levadas pela KeruGás</p>
        <p className="mt-1 text-4xl font-bold text-dark" data-testid="counter-since-reset">{c.since_reset}</p>
        <p className="mt-1 text-xs text-gray">
          Hoje: {c.today}
          {c.last_reset_at ? ` · Contador reiniciado em ${new Date(c.last_reset_at).toLocaleString('pt-PT')}` : ''}
        </p>
        {c.pending_confirmation > 0 && (
          <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">{c.pending_confirmation} registo(s) por confirmar.</p>
        )}
      </div>

      <div className="rounded-xl border border-gray/15 bg-white p-5">
        <p className="font-semibold text-dark">Registos dos condutores</p>
        {data.pickups.length === 0 && <p className="mt-2 text-sm text-gray">Ainda nenhum condutor registou garrafas neste posto.</p>}
        <ul className="mt-3 space-y-3">
          {data.pickups.map((p) => (
            <li key={p.id} className="border-b border-gray/10 pb-3 text-sm last:border-0">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-dark"><strong>{p.quantity}</strong> garrafa(s) · {p.driver_name}</span>
                <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATE_LABEL[p.state].cls}`}>{STATE_LABEL[p.state].text}</span>
              </div>
              {p.source === 'CENTRAL' && <p className="text-xs text-amber-800">Registado pela central ({p.registered_by_name ?? '—'}): {p.central_reason}</p>}
              {p.source === 'SMS' && <p className="text-xs text-gray">Pedido por SMS do condutor, registado pela central</p>}
              <p className="text-xs text-gray">{p.registered_at ? new Date(p.registered_at).toLocaleString('pt-PT') : ''}{p.dispute_note ? ` · ${p.dispute_note}` : ''}</p>
              {p.state === 'DRIVER_ONLY' && disputing !== p.id && (
                <div className="mt-2 flex gap-2">
                  <button
                    onClick={() => confirmMutation.mutate(p.id)}
                    disabled={confirmMutation.isPending}
                    aria-label={`Confirmar ${p.quantity} garrafas de ${p.driver_name}`}
                    className="rounded-lg bg-primary px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                  >
                    Confirmar
                  </button>
                  <button onClick={() => setDisputing(p.id)} className="rounded-lg border border-danger/40 px-3 py-1.5 text-xs font-medium text-danger">
                    Não confere
                  </button>
                </div>
              )}
              {disputing === p.id && (
                <div className="mt-2 space-y-2">
                  <input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    aria-label="O que não confere"
                    placeholder="Ex.: levou só 8 garrafas"
                    className="w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
                  />
                  <div className="flex gap-2">
                    <button
                      onClick={() => disputeMutation.mutate({ id: p.id, text: note.trim() })}
                      disabled={note.trim().length < 3 || disputeMutation.isPending}
                      className="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60"
                    >
                      Enviar
                    </button>
                    <button onClick={() => setDisputing(null)} className="rounded-lg border border-gray/20 px-3 py-1.5 text-xs">Cancelar</button>
                  </div>
                </div>
              )}
            </li>
          ))}
        </ul>
        {(confirmMutation.isError || disputeMutation.isError) && <p className="mt-2 text-xs text-danger">Não foi possível guardar. Verifique a ligação à internet.</p>}
      </div>
    </div>
  )
}
