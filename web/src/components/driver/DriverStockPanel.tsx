import { useCallback, useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Package, Plus, X, CloudOff, RefreshCw } from 'lucide-react'
import { driverService } from '@/services/driverService'
import { enqueuePickup, flushQueue, isRetryable, newClientUuid, readQueue, type QueuedPickup } from '@/lib/pickupQueue'
import { parseKz } from '@/lib/kz'
import { DriverCentralInbox } from '@/components/driver/DriverCentralInbox'
import type { BottlePickup } from '@/types'

const RECENT_KEY = 'kerugas.recentStations.v1'
type StationOption = { id: number; name: string; code?: string }

function readRecent(): StationOption[] {
  try {
    const parsed = JSON.parse(localStorage.getItem(RECENT_KEY) ?? '[]')
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function rememberStation(s: StationOption) {
  try {
    const list = [s, ...readRecent().filter((r) => r.id !== s.id)].slice(0, 8)
    localStorage.setItem(RECENT_KEY, JSON.stringify(list))
  } catch {
    // ignorar
  }
}

const STATE_LABEL: Record<BottlePickup['state'], { text: string; cls: string }> = {
  CONFIRMED: { text: 'Confirmado pelo posto', cls: 'bg-success/10 text-success' },
  DRIVER_ONLY: { text: 'Só condutor', cls: 'bg-amber-100 text-amber-800' },
  DISPUTED: { text: 'Contestado', cls: 'bg-danger/10 text-danger' },
}

function formatKz(v: number) {
  return `${v.toLocaleString('pt-PT', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} Kz`
}

/**
 * "Garrafas disponíveis" do condutor + registo de carregamentos nos postos
 * (Outubro 2026). Funciona sem internet: o registo fica numa fila no
 * telemóvel e é enviado sozinho quando a rede voltar.
 */
export function DriverStockPanel() {
  const queryClient = useQueryClient()
  const [queue, setQueue] = useState<QueuedPickup[]>(() => readQueue())
  const [open, setOpen] = useState(false)
  const [syncMessage, setSyncMessage] = useState<string | null>(null)

  const stockQuery = useQuery({ queryKey: ['driver-stock'], queryFn: driverService.getStock, refetchInterval: 60_000 })

  const sync = useCallback(async () => {
    if (readQueue().length === 0) return
    const result = await flushQueue((p) => driverService.registerPickup(p))
    setQueue(readQueue())
    if (result.sent > 0) {
      setSyncMessage(`${result.sent} carregamento(s) enviado(s).`)
      void queryClient.invalidateQueries({ queryKey: ['driver-stock'] })
    }
    if (result.rejected.length > 0) {
      setSyncMessage(`${result.rejected.length} carregamento(s) foram recusados pelo servidor. Registe-os de novo.`)
    }
  }, [queryClient])

  useEffect(() => {
    void sync()
    const onOnline = () => void sync()
    window.addEventListener('online', onOnline)
    const timer = window.setInterval(() => void sync(), 60_000)
    return () => {
      window.removeEventListener('online', onOnline)
      window.clearInterval(timer)
    }
  }, [sync])

  const stock = stockQuery.data?.stock
  const queuedBottles = queue.reduce((n, q) => n + q.quantity, 0)

  return (
    <section aria-label="Garrafas disponíveis" className="mt-4 rounded-xl border border-gray/15 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-3">
          <span className="flex h-11 w-11 items-center justify-center rounded-full bg-primary/10 text-primary"><Package size={22} /></span>
          <div>
            <p className="text-xs text-gray">Garrafas disponíveis</p>
            <p className="text-3xl font-bold text-dark" data-testid="stock-available">{stock ? stock.available : '—'}</p>
          </div>
        </div>
        <button
          onClick={() => setOpen(true)}
          className="flex items-center gap-1.5 rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-white"
        >
          <Plus size={16} /> Registar carregamento
        </button>
      </div>

      {stock && (
        <p className="mt-2 text-xs text-gray">
          Em mão: {stock.on_hand} · Reservadas para pedidos atribuídos: {stock.reserved} · Carregadas no total: {stock.picked}
        </p>
      )}

      {queue.length > 0 && (
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">
          <span className="flex items-center gap-1.5">
            <CloudOff size={14} /> {queue.length} carregamento(s) por enviar (+{queuedBottles} garrafas). Serão enviados quando houver internet.
          </span>
          <button onClick={() => void sync()} className="flex items-center gap-1 font-semibold"><RefreshCw size={12} /> Enviar agora</button>
        </div>
      )}
      {syncMessage && <p className="mt-2 text-xs text-gray" role="status">{syncMessage}</p>}

      {stockQuery.data && <DriverCentralInbox data={stockQuery.data} />}

      {(stockQuery.data?.pickups.length ?? 0) > 0 && (
        <ul className="mt-3 space-y-1.5">
          {stockQuery.data!.pickups.slice(0, 5).map((p) => (
            <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
              <span className="text-dark">
                {p.quantity} garrafa(s) · {p.station_name}
                <span className="text-xs text-gray"> · {p.registered_at ? new Date(p.registered_at).toLocaleString('pt-PT') : ''}</span>
              </span>
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATE_LABEL[p.state].cls}`}>{p.awaiting_confirmation ? 'Registado pela central' : STATE_LABEL[p.state].text}</span>
            </li>
          ))}
        </ul>
      )}

      {open && (
        <PickupModal
          recentFromServer={(stockQuery.data?.pickups ?? []).map((p) => ({ id: p.station_id, name: p.station_name ?? `Posto #${p.station_id}` }))}
          onClose={() => setOpen(false)}
          onSaved={(queued) => {
            setOpen(false)
            setQueue(readQueue())
            setSyncMessage(queued ? 'Sem internet: carregamento guardado no telemóvel.' : 'Carregamento registado. O posto foi avisado para confirmar.')
            void queryClient.invalidateQueries({ queryKey: ['driver-stock'] })
          }}
        />
      )}
    </section>
  )
}

function PickupModal({ recentFromServer, onClose, onSaved }: {
  recentFromServer: StationOption[]
  onClose: () => void
  onSaved: (queued: boolean) => void
}) {
  const [search, setSearch] = useState('')
  const [station, setStation] = useState<StationOption | null>(null)
  const [quantity, setQuantity] = useState('')
  const [amount, setAmount] = useState('')
  const [error, setError] = useState<string | null>(null)
  const online = typeof navigator === 'undefined' ? true : navigator.onLine

  const stationsQuery = useQuery({
    queryKey: ['driver-stations', search],
    queryFn: () => driverService.searchStations(search),
    enabled: online,
  })

  const recent = [...readRecent(), ...recentFromServer].filter((s, i, arr) => arr.findIndex((x) => x.id === s.id) === i)
  const options: StationOption[] = online && stationsQuery.data ? stationsQuery.data : recent

  const qty = Number(quantity)
  const amountValue = parseKz(amount)
  const valid = station !== null && Number.isInteger(qty) && qty >= 1 && qty <= 500 && amountValue !== null

  const mutation = useMutation({
    mutationFn: async () => {
      const payload = {
        client_uuid: newClientUuid(),
        station_id: station!.id,
        quantity: qty,
        amount: amountValue as number,
        registered_at: new Date().toISOString(),
      }
      rememberStation(station!)
      const queue = () => {
        enqueuePickup({ ...payload, station_name: station!.name, queued_at: payload.registered_at })
        return true
      }
      if (!navigator.onLine) return queue()
      try {
        await driverService.registerPickup(payload)
        return false
      } catch (e) {
        if (isRetryable(e)) return queue()
        throw e
      }
    },
    onSuccess: (queued) => onSaved(queued),
    onError: (e: unknown) => {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message
      setError(msg ?? 'Não foi possível registar. Verifique os dados.')
    },
  })

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="max-h-[92vh] w-full max-w-sm overflow-y-auto rounded-2xl bg-white p-6">
        <div className="flex items-center justify-between">
          <h3 className="font-semibold text-dark">Registar carregamento</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>
        <p className="mt-2 text-sm text-gray">Garrafas que levou de um posto para o stock da KeruGás. O operador do posto confirma depois.</p>
        {!online && <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-800">Sem internet: o registo fica guardado e é enviado depois.</p>}

        {online && (
          <>
            <label htmlFor="pickup-search" className="mt-3 block text-xs font-medium text-gray">Procurar posto</label>
            <input id="pickup-search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Nome do posto..." className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
          </>
        )}
        <label htmlFor="pickup-station" className="mt-3 block text-xs font-medium text-gray">Posto</label>
        <select
          id="pickup-station"
          value={station?.id ?? ''}
          onChange={(e) => setStation(options.find((o) => o.id === Number(e.target.value)) ?? null)}
          className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm"
        >
          <option value="">Selecione o posto...</option>
          {options.map((o) => <option key={o.id} value={o.id}>{o.name}{o.code ? ` · Nº ${o.code}` : ''}</option>)}
        </select>
        {!online && options.length === 0 && <p className="mt-1 text-xs text-gray">Sem postos guardados. Ligue-se à internet uma vez para os carregar.</p>}

        <label htmlFor="pickup-qty" className="mt-3 block text-xs font-medium text-gray">Número de garrafas</label>
        <input id="pickup-qty" type="number" min={1} max={500} inputMode="numeric" value={quantity} onChange={(e) => setQuantity(e.target.value)} className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />

        <label htmlFor="pickup-amount" className="mt-3 block text-xs font-medium text-gray">Valor a pagar ao posto (Kz)</label>
        <input id="pickup-amount" type="text" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="Ex.: 12000" className="mt-1 w-full rounded-lg border border-gray/20 px-3 py-2 text-sm" />
        {amountValue !== null && qty > 0 && <p className="mt-1 text-xs text-gray">{formatKz(amountValue / qty)} por garrafa</p>}

        {error && <p className="mt-2 text-xs text-danger">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg border border-gray/20 px-3 py-1.5 text-sm font-medium text-dark">Cancelar</button>
          <button onClick={() => valid && mutation.mutate()} disabled={!valid || mutation.isPending} className="rounded-lg bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60">
            {mutation.isPending ? 'A registar...' : 'Registar'}
          </button>
        </div>
      </div>
    </div>
  )
}
