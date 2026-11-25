import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Clock, BarChart3 } from 'lucide-react'
import { motion } from 'framer-motion'
import { operatorService } from '@/services/operatorService'
import { queueLevelLabel as QUEUE_LABEL } from '@/constants/theme'
import { successFeedback } from '@/native/haptics'
import { OperatorSubscriptionCard } from '@/components/OperatorSubscriptionCard'
import { OperatorPickupsPanel } from '@/components/OperatorPickupsPanel'
import { GasFlame } from '@/components/fx/GasFlame'
import { celebrate } from '@/components/fx/celebrate'
import { useAuth } from '@/hooks/useAuth'
import { StationOrdersPanel } from '@/components/station/StationOrdersPanel'

const QUEUE_DIGIT: Record<string, string> = { LOW: '0', MEDIUM: '1', HIGH: '2' }

/**
 * Painel do operador (secção 94/95). Mostra o "código atual" no mesmo
 * formato usado por SMS (ex: "10" = gás disponível + fila baixa),
 * para que o operador entenda a equivalência entre atualizar aqui
 * ou enviar o código por SMS.
 */
export default function OperatorDashboard() {
  const queryClient = useQueryClient()
  // O gerente (Novembro 2026) usa este painel só para o estado do gás: as
  // garrafas levadas pela KeruGás confirmam-se pelos operadores.
  const { user } = useAuth()
  const isManager = user?.role === 'STATION_MANAGER'
  const [gasAvailable, setGasAvailable] = useState(true)
  const [queueLevel, setQueueLevel] = useState('LOW')
  // REGRESSÃO CORRIGIDA (de novo): sem este "touched", o formulário
  // ficava sempre nos valores iniciais do useState acima (gás
  // disponível + fila baixa), nunca refletindo o estado real do posto
  //,o operador via "Sem gás" acima e "Tem gás" pré-selecionado no
  // formulário, sem perceber que estava prestes a reverter o estado
  // real. Mesmo padrão já usado para cylinderTypesTouched.
  const [statusTouched, setStatusTouched] = useState(false)
  const [cylinderTypes, setCylinderTypes] = useState('')
  const [cylinderTypesTouched, setCylinderTypesTouched] = useState(false)

  // Abas (Outubro 2026): estado do posto | garrafas levadas pela KeruGás.
  const [tab, setTab] = useState<'estado' | 'garrafas' | 'pedidos'>('estado')

  const stationQuery = useQuery({ queryKey: ['operator-station'], queryFn: operatorService.myStation })
  const historyQuery = useQuery({ queryKey: ['operator-history'], queryFn: operatorService.history })

  const mutation = useMutation({
    mutationFn: () =>
      operatorService.updateStatus({
        gas_available: gasAvailable,
        queue_level: queueLevel,
        // Só envia se o operador tiver mesmo mexido no campo,caso
        // contrário mantém-se o valor da última atualização (nunca
        // apagar tipos de garrafa por omissão).
        cylinder_types: cylinderTypesTouched ? cylinderTypes : undefined,
      }),
    onSuccess: () => {
      void successFeedback()
      // Estado publicado: uma explosão de faíscas a partir do botão.
      celebrate()
      queryClient.invalidateQueries({ queryKey: ['operator-station'] })
      queryClient.invalidateQueries({ queryKey: ['operator-history'] })
      setCylinderTypesTouched(false)
      setStatusTouched(false)
    },
  })

  useEffect(() => {
    if (stationQuery.data && !cylinderTypesTouched) {
      setCylinderTypes(stationQuery.data.cylinder_types ?? '')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stationQuery.data?.cylinder_types])

  useEffect(() => {
    if (stationQuery.data && !statusTouched) {
      setGasAvailable(stationQuery.data.gas_available)
      setQueueLevel(stationQuery.data.queue_level ?? 'LOW')
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stationQuery.data?.gas_available, stationQuery.data?.queue_level])

  if (stationQuery.isLoading) {
    return <div className="mx-auto max-w-lg px-4 py-16 text-center text-gray">A carregar o seu posto...</div>
  }

  if (stationQuery.isError || !stationQuery.data) {
    return (
      <div className="mx-auto max-w-lg px-4 py-16 text-center">
        <p className="font-medium text-dark">Não existe posto atribuído à sua conta.</p>
        <p className="mt-1 text-sm text-gray">Contacte o suporte do KERUGAS para associar o seu posto.</p>
      </div>
    )
  }

  const station = stationQuery.data
  const code = `${gasAvailable ? '1' : '0'}${QUEUE_DIGIT[queueLevel] ?? '0'}`

  return (
    <div className="mx-auto max-w-lg px-4 py-10">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h1 className="text-2xl font-extrabold text-dark">{station.name}</h1>
          <p className="text-gray">{station.municipality}, {station.province}</p>
        </div>
        {station.is_listed ? (
          <Link
            to="/operator/analytics"
            className="flex shrink-0 items-center gap-1.5 rounded-lg border border-primary/30 px-3 py-2 text-xs font-semibold text-primary hover:bg-primary/5"
          >
            <BarChart3 size={14} /> Ver analytics
          </Link>
        ) : (
          <span
            title="O analytics vem com a subscrição que mantém o posto visível na aplicação."
            className="flex shrink-0 items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-2 text-xs font-medium text-gray"
          >
            <BarChart3 size={14} /> Analytics com a subscrição
          </span>
        )}
      </div>

      <OperatorSubscriptionCard />

      {isManager && (
        <Link to="/posto" className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-primary hover:opacity-80">
          ← Gestão do posto (informações, operadores e condutores)
        </Link>
      )}

      {!isManager && <div role="tablist" aria-label="Painel do posto" className="mt-6 grid grid-cols-3 gap-1 rounded-xl bg-light p-1 text-sm font-medium">
        {(['estado', 'pedidos', 'garrafas'] as const).map((t) => (
          <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`relative isolate rounded-lg py-2 transition-colors ${tab === t ? 'text-dark' : 'text-gray'}`}>
            {/* Fundo branco desliza entre as abas. */}
            {tab === t && <motion.span layoutId="operator-tab" className="absolute inset-0 -z-10 rounded-lg bg-white shadow-sm" transition={{ type: 'spring', stiffness: 420, damping: 34 }} />}
            {t === 'estado' ? 'Estado do posto' : t === 'pedidos' ? 'Pedidos' : 'Garrafas KeruGás'}
          </button>
        ))}
      </div>}

      {tab === 'pedidos' && !isManager ? <StationOrdersPanel /> : tab === 'garrafas' && !isManager ? <OperatorPickupsPanel /> : (
        <>
      <div className="fx-slide-next relative mt-6 overflow-hidden rounded-2xl border border-gray/15 bg-white p-5">
        <div className={`pointer-events-none absolute -right-10 -top-10 h-40 w-40 rounded-full blur-3xl ${station.gas_available ? 'bg-primary/25' : 'bg-gray/10'}`} aria-hidden="true" />
        <p className="text-sm text-gray">Estado atual</p>
        <div className="mt-2 flex items-center gap-3">
          {/* key: ao mudar de estado a chama reacende (ou apaga) com animação. */}
          <GasFlame key={String(station.gas_available)} size={34} lit={station.gas_available} />
          <span className="font-semibold text-dark">
            {station.gas_available ? 'Gás disponível' : 'Gás indisponível'},{QUEUE_LABEL[station.queue_level ?? 'LOW']}
          </span>
        </div>
        <p className="mt-2 flex items-center gap-1.5 text-xs text-gray">
          <Clock size={12} /> Confiança: {station.confidence_score}%
        </p>
        {station.cylinder_types && (
          <p className="mt-1 text-xs text-gray">Garrafas: {station.cylinder_types}</p>
        )}
      </div>

      <div className="mt-6 rounded-xl border border-gray/15 bg-white p-5">
        <p className="font-semibold text-dark">Atualizar estado</p>

        <div className="mt-3 flex gap-2">
          <button
            onClick={() => { setGasAvailable(true); setStatusTouched(true) }}
            className={`fx-choice flex flex-1 items-center justify-center gap-2 rounded-xl border px-3 py-3 text-sm font-semibold ${gasAvailable ? 'is-on border-success bg-success/10 text-success' : 'border-gray/20'}`}
          >
            <GasFlame key={`on-${gasAvailable}`} size={14} lit={gasAvailable} />
            Tem gás
          </button>
          <button
            onClick={() => { setGasAvailable(false); setStatusTouched(true) }}
            className={`fx-choice flex-1 rounded-xl border px-3 py-3 text-sm font-semibold ${!gasAvailable ? 'is-on border-danger bg-danger/10 text-danger' : 'border-gray/20'}`}
          >
            Sem gás
          </button>
        </div>

        <select
          value={queueLevel}
          onChange={(e) => { setQueueLevel(e.target.value); setStatusTouched(true) }}
          className="fx-input mt-3 w-full rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
        >
          <option value="LOW">Fila baixa</option>
          <option value="MEDIUM">Fila média</option>
          <option value="HIGH">Fila alta</option>
        </select>

        <label className="mt-3 block text-sm">
          <span className="mb-1 block font-medium text-dark">Tipos de garrafa disponíveis</span>
          <input
            type="text"
            value={cylinderTypes}
            onChange={(e) => { setCylinderTypes(e.target.value); setCylinderTypesTouched(true) }}
            placeholder="Ex: garrafa leve, garrafa pequena, garrafa grande"
            className="fx-input w-full rounded-xl border border-gray/20 px-3 py-2.5 text-sm"
          />
          <span className="mt-1 block text-xs text-gray">
            Nomes separados por vírgula. Só é atualizado por aqui ou pelo Admin —
            o SMS nunca altera este campo.
          </span>
        </label>

        <div className="mt-3 rounded-xl bg-light p-3 text-center">
          <p className="text-xs text-gray">Código equivalente por SMS</p>
          <p className="text-2xl font-black tracking-[0.3em] text-dark">
            <span key={code} className="fx-flip">{code}</span>
          </p>
        </div>

        <button
          onClick={() => mutation.mutate()}
          disabled={mutation.isPending}
          className="fx-btn-fire mt-4 w-full rounded-xl px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
        >
          {mutation.isPending ? 'A atualizar...' : 'Atualizar estado'}
        </button>
      </div>

      <div className="mt-6 rounded-xl border border-gray/15 bg-white p-5">
        <p className="font-semibold text-dark">Histórico recente</p>
        <div className="fx-cascade mt-3 space-y-2">
          {historyQuery.data?.length === 0 && <p className="text-sm text-gray">Sem histórico ainda.</p>}
          {historyQuery.data?.map((h, i) => (
            <div key={i} style={{ ['--i' as string]: Math.min(i, 8) }} className="flex items-center justify-between border-b border-gray/10 pb-2 text-sm last:border-0">
              <span className="flex items-center gap-2 text-dark">
                <span className={`h-2 w-2 rounded-full ${h.gas_available ? 'bg-success shadow-[0_0_8px_#16A34A]' : 'bg-danger'}`} aria-hidden="true" />
                {h.gas_available ? 'Gás disponível' : 'Gás indisponível'},{QUEUE_LABEL[h.queue_level]}
              </span>
              <span className="text-xs text-gray">{new Date(h.created_at).toLocaleString('pt-PT')}</span>
            </div>
          ))}
        </div>
      </div>
        </>
      )}
    </div>
  )
}
