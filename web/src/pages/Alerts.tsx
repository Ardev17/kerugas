import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, Trash2, Plus, X } from 'lucide-react'
import { alertService } from '@/services/alertService'
import { territoryService } from '@/services/territoryService'
import { PageHero } from '@/components/fx/PageHero'

const CHANNEL_LABEL: Record<string, string> = {
  in_app: 'Na aplicação', sms: 'SMS', email: 'Email', push: 'Push',
}

/**
 * Etapa 10,Alertas. Lista os alertas ativos do utilizador e permite
 * criar um novo por município (o caso mais comum: "avisa-me quando
 * houver gás no meu município", sem depender de um posto específico).
 */
export default function Alerts() {
  const [showForm, setShowForm] = useState(false)
  const queryClient = useQueryClient()

  const alertsQuery = useQuery({ queryKey: ['alerts'], queryFn: alertService.list })

  const removeMutation = useMutation({
    mutationFn: (id: number) => alertService.remove(id),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['alerts'] }),
  })

  return (
    <div className="mx-auto max-w-2xl px-4 py-10">
      <div className="flex items-center justify-between">
        <PageHero title="Alertas" subtitle="Seja avisado quando houver gás disponível." flame={false} />
        <button
          onClick={() => setShowForm(true)}
          className="fx-btn-fire flex shrink-0 items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-semibold text-white"
        >
          <Plus size={16} /> Novo alerta
        </button>
      </div>

      {alertsQuery.isLoading && (
        <div className="mt-8 space-y-3">
          {[1, 2].map((i) => <div key={i} className="fx-shimmer h-20 rounded-2xl" />)}
        </div>
      )}

      {alertsQuery.isSuccess && alertsQuery.data.length === 0 && (
        <div className="fx-slide-next mt-8 rounded-2xl border border-dashed border-gray/30 p-8 text-center">
          <span className="fx-bubble mx-auto h-14 w-14" style={{ ['--bubble-a' as string]: '#F59E0B', ['--bubble-b' as string]: '#FF6B1A' }} aria-hidden="true">
            <Bell size={26} className="fx-bell" />
          </span>
          <p className="mt-3 font-medium text-dark">Ainda não tem alertas ativos.</p>
          <p className="mt-1 text-sm text-gray">Crie um para ser avisado assim que houver gás no seu município.</p>
        </div>
      )}

      <div className="fx-cascade mt-8 space-y-3">
        {alertsQuery.data?.map((alert, i) => (
          <div key={alert.id} style={{ ['--i' as string]: Math.min(i, 10) }} className="fx-row flex items-center justify-between gap-3 rounded-2xl border border-gray/15 bg-white p-4">
            <span className="fx-bubble h-10 w-10 shrink-0" style={alert.active ? { ['--bubble-a' as string]: '#F59E0B', ['--bubble-b' as string]: '#FF6B1A' } : { ['--bubble-a' as string]: '#64748B', ['--bubble-b' as string]: '#94A3B8' }} aria-hidden="true">
              <Bell size={18} className={alert.active ? 'fx-bell' : ''} />
            </span>
            <div className="min-w-0 flex-1">
              <p className="font-medium text-dark">
                {alert.station ? `Posto: ${alert.station}` : alert.municipality ? `Município: ${alert.municipality}` : 'Alerta geral'}
              </p>
              <p className="mt-1 text-xs text-gray">
                Canais: {alert.channels.map((c) => CHANNEL_LABEL[c] ?? c).join(', ')}
                {!alert.active && ' · Inativo'}
              </p>
            </div>
            <button
              onClick={() => removeMutation.mutate(alert.id)}
              aria-label="Remover alerta"
              className="rounded-full p-2 text-danger transition-transform hover:scale-110 hover:bg-danger/10"
            >
              <Trash2 size={18} />
            </button>
          </div>
        ))}
      </div>

      {showForm && <NewAlertModal onClose={() => setShowForm(false)} onCreated={() => {
        setShowForm(false)
        queryClient.invalidateQueries({ queryKey: ['alerts'] })
      }} />}
    </div>
  )
}

function NewAlertModal({ onClose, onCreated }: { onClose: () => void; onCreated: () => void }) {
  const [municipalityId, setMunicipalityId] = useState<number | null>(null)
  const [provinceId, setProvinceId] = useState<number | null>(null)
  const [channels, setChannels] = useState<string[]>(['in_app'])

  const provincesQuery = useQuery({ queryKey: ['provinces'], queryFn: territoryService.listProvinces })
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities', provinceId],
    queryFn: () => territoryService.listMunicipalities(provinceId!),
    enabled: !!provinceId,
  })

  const mutation = useMutation({
    mutationFn: () => alertService.create({ municipality_id: municipalityId ?? undefined, channels }),
    onSuccess: onCreated,
  })

  const toggleChannel = (c: string) => {
    setChannels((prev) => (prev.includes(c) ? prev.filter((x) => x !== c) : [...prev, c]))
  }

  return (
    <div className="fx-backdrop-in fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4 backdrop-blur-sm">
      <div className="fx-modal-in w-full max-w-sm rounded-3xl bg-white p-6 shadow-2xl">
        <div className="flex items-center justify-between">
          <h3 className="flex items-center gap-2 font-bold text-dark"><Bell size={17} className="fx-bell text-amber-500" /> Novo alerta</h3>
          <button onClick={onClose} aria-label="Fechar"><X size={18} /></button>
        </div>

        <div className="mt-4 space-y-3">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Província</span>
            <select
              className="fx-input w-full rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
              value={provinceId ?? ''}
              onChange={(e) => { setProvinceId(Number(e.target.value) || null); setMunicipalityId(null) }}
            >
              <option value="">Selecione</option>
              {provincesQuery.data?.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
            </select>
          </label>

          <label className="block text-sm">
            <span className="mb-1 block font-medium text-dark">Município</span>
            <select
              className="fx-input w-full rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
              value={municipalityId ?? ''}
              onChange={(e) => setMunicipalityId(Number(e.target.value) || null)}
              disabled={!provinceId}
            >
              <option value="">Selecione</option>
              {municipalitiesQuery.data?.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
          </label>

          <div>
            <span className="mb-1 block text-sm font-medium text-dark">Canais</span>
            <div className="flex flex-wrap gap-2">
              {Object.entries(CHANNEL_LABEL).map(([key, label]) => (
                <button
                  key={key}
                  type="button"
                  onClick={() => toggleChannel(key)}
                  aria-pressed={channels.includes(key)}
                  className={`fx-choice rounded-full border px-3 py-1.5 text-xs font-medium ${
                    channels.includes(key) ? 'is-on border-primary bg-primary/10 text-primary' : 'border-gray/20 text-gray'
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          {mutation.isError && <p className="text-sm text-danger">Não foi possível criar o alerta.</p>}

          <button
            onClick={() => mutation.mutate()}
            disabled={!municipalityId || channels.length === 0 || mutation.isPending}
            className="fx-btn-fire w-full rounded-xl px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
          >
            {mutation.isPending ? 'A criar...' : 'Criar alerta'}
          </button>
        </div>
      </div>
    </div>
  )
}
