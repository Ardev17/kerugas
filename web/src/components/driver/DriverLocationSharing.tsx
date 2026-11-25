import { useEffect } from 'react'
import { MapPin } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { useDriverLocationStore } from '@/stores/driverLocationStore'
import { openLocationSettings, startDriverLocation } from '@/native/driverLocation'
import { isNativeApp } from '@/native/platform'

const DRIVER_ROLES = ['DRIVER', 'STATION_DRIVER']

/**
 * Montado uma vez na raiz (Novembro 2026): enquanto houver sessão de
 * condutor e consentimento, partilha a localização,em qualquer ecrã e, no
 * app, também em segundo plano. Termina ao sair da sessão.
 */
export function DriverLocationRunner() {
  const { user } = useAuth()
  const consent = useDriverLocationStore((s) => s.consent)
  const setState = useDriverLocationStore((s) => s.setState)
  const isDriver = !!user && DRIVER_ROLES.includes(user.role)

  useEffect(() => {
    if (!isDriver || !consent) return
    return startDriverLocation(setState)
  }, [isDriver, consent, setState])

  return null
}

/**
 * Aviso destacado antes de pedir a permissão (exigido pela Google Play e
 * pela Apple para localização em segundo plano) e estado da partilha.
 */
export function DriverLocationCard() {
  const { consent, state, setConsent } = useDriverLocationStore()

  if (!consent) {
    return (
      <div className="rounded-xl border-2 border-primary/40 bg-primary/5 p-4" role="region" aria-label="Partilhar localização">
        <div className="flex items-start gap-3">
          <MapPin className="mt-0.5 h-5 w-5 shrink-0 text-primary" aria-hidden />
          <div className="space-y-2 text-sm">
            <p className="font-semibold text-dark">Partilhar a sua localização</p>
            <p className="text-gray">
              A KeruGás recolhe a localização deste telemóvel{isNativeApp ? ', também com a app fechada ou sem estar a ser usada,' : ''} para
              que o Super Admin e o Admin Provincial vejam os condutores mais perto de cada cliente e escolham a quem entregar o pedido.
            </p>
            <p className="text-gray">
              Só a última posição fica guardada e mais ninguém a vê. Pode parar a qualquer momento aqui ou nas definições do telemóvel.
              {isNativeApp ? ' No passo seguinte escolha “Permitir sempre”.' : ''}
            </p>
            <button type="button" className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white" onClick={() => setConsent(true)}>
              Aceito partilhar a localização
            </button>
          </div>
        </div>
      </div>
    )
  }

  const label: Record<typeof state, string> = {
    active: 'A partilhar a sua localização',
    off: 'A ligar a localização…',
    denied: 'Permissão de localização recusada',
    error: 'Sem localização (GPS desligado ou sem sinal)',
  }

  return (
    <div className="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-gray/15 bg-white p-3 text-sm" role="status">
      <span className="flex items-center gap-2">
        <span className={`h-2.5 w-2.5 rounded-full ${state === 'active' ? 'bg-success' : state === 'off' ? 'bg-warning' : 'bg-danger'}`} aria-hidden />
        {label[state]}
      </span>
      <span className="flex gap-2">
        {state === 'denied' && isNativeApp && (
          <button type="button" className="text-primary underline" onClick={() => void openLocationSettings()}>
            Abrir definições
          </button>
        )}
        <button type="button" className="text-gray underline" onClick={() => setConsent(false)}>
          Parar de partilhar
        </button>
      </span>
    </div>
  )
}
