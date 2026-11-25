import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { Fuel, Loader2, LocateFixed, MapPin, MoonStar, RefreshCw, ShieldCheck } from 'lucide-react'
import { Sheet } from '@/components/vizinhos/Sheet'
import { useGeolocation } from '@/hooks/useGeolocation'
import { getApiErrorMessage } from '@/lib/api'
import type { GeoFailureReason } from '@/lib/geo'
import { durationOptions, stationHere } from '@/lib/vizinhos'
import { stationService } from '@/services/stationService'
import { vizinhosService } from '@/services/vizinhosService'
import type { VzMessage, VzOverview } from '@/types/vizinhos'

const GEO_ERROR: Partial<Record<GeoFailureReason, string>> = {
  permission_denied: 'A localização está bloqueada para o KeruGás. Permita-a nas definições do telemóvel (ou do navegador) e tente de novo.',
  gps_off: 'O GPS do telemóvel está desligado. Ligue a localização e tente de novo.',
  timeout: 'A localização demorou demasiado. Vá para um sítio mais aberto e tente de novo.',
  insecure: 'Este endereço não permite usar a localização.',
  unsupported: 'Este aparelho não consegue indicar a localização.',
}

/** Acima disto o GPS é demasiado impreciso para alguém chegar lá. */
const MAX_ACCURACY = 500

interface Props {
  overview: VzOverview
  municipalityId: number
  onClose: () => void
  onSent: (m: VzMessage) => void
}

/**
 * "Partilhar a minha localização" nos Vizinhos (Outubro 2026).
 * 1) lê o GPS; 2) se a pessoa está num posto, propõe partilhar o POSTO (a
 * posição exata fica privada); 3) escolhe 30 min, 1 h ou 2 h (sempre até às
 * 18h). Sem texto,quem quiser explicar escreve uma mensagem normal.
 */
export function LocationShareSheet({ overview, municipalityId, onClose, onSent }: Props) {
  const geo = useGeolocation()
  const win = overview.location_share
  const [kind, setKind] = useState<'point' | 'station' | null>(null)
  const [minutes, setMinutes] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const options = useMemo(() => durationOptions(win), [win])

  // Ao abrir: pede logo a localização (a pessoa carregou no botão para isso).
  useEffect(() => {
    if (win?.open) geo.request()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    if (minutes === null && options.length > 0) setMinutes(options.find((o) => o.minutes === 60)?.minutes ?? options[0].minutes)
  }, [options, minutes])

  const located = geo.status === 'granted' && geo.latitude !== null && geo.longitude !== null
  const nearby = useQuery({
    queryKey: ['vz-location-stations', geo.latitude, geo.longitude],
    queryFn: async () => (await stationService.nearby({ latitude: geo.latitude as number, longitude: geo.longitude as number, radius_km: 1 })).stations,
    enabled: located,
    staleTime: 60_000,
    retry: false,
  })
  const here = useMemo(() => stationHere(nearby.data ?? []), [nearby.data])

  // Por omissão: o posto, quando a pessoa está num (protege a posição exata).
  useEffect(() => {
    if (kind === null && (nearby.isSuccess || nearby.isError)) setKind(here ? 'station' : 'point')
  }, [kind, here, nearby.isSuccess, nearby.isError])

  const tooImprecise = located && geo.accuracy !== null && geo.accuracy > MAX_ACCURACY

  const share = useMutation({
    mutationFn: () =>
      vizinhosService.shareLocation(municipalityId, {
        kind: kind === 'station' && here ? 'station' : 'point',
        latitude: geo.latitude as number,
        longitude: geo.longitude as number,
        accuracy: geo.accuracy !== null ? Math.round(geo.accuracy) : null,
        station_id: kind === 'station' && here ? here.id : null,
        minutes: minutes as number,
      }),
    onSuccess: (m) => {
      onSent(m)
      onClose()
    },
    onError: (e) => setError(getApiErrorMessage(e, 'Não foi possível partilhar a localização. Tente de novo.')),
  })

  if (!win?.open) {
    return (
      <Sheet title="Partilhar a minha localização" onClose={onClose}>
        <div className="flex flex-col items-center gap-2 py-4 text-center">
          <span className="flex h-14 w-14 items-center justify-center rounded-full bg-primary/10 text-primary"><MoonStar size={26} aria-hidden="true" /></span>
          <p className="font-semibold text-dark">Disponível das {win?.opens_at ?? '08:00'} às {win?.closes_at ?? '18:00'}</p>
          <p className="max-w-xs text-sm text-gray">Fora deste horário os postos estão fechados, por isso a partilha de localização fica desligada.</p>
        </div>
      </Sheet>
    )
  }

  const ready = located && !tooImprecise && kind !== null && minutes !== null && !share.isPending
  const chosen = options.find((o) => o.minutes === minutes)
  const endsAt = chosen?.capped ? win.closes_at : null

  return (
    <Sheet title="Partilhar a minha localização" onClose={onClose}>
      {/* 1. Localização */}
      {(geo.status === 'idle' || geo.status === 'requesting') && (
        <p className="flex items-center gap-2 rounded-xl bg-light p-3 text-sm text-dark">
          <Loader2 size={16} className="animate-spin text-primary" aria-hidden="true" /> A obter a sua localização…
        </p>
      )}
      {(geo.status === 'denied' || geo.status === 'unsupported') && (
        <div role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger">
          {(geo.reason && GEO_ERROR[geo.reason]) || 'Não conseguimos detetar a sua posição agora. Ligue o GPS e tente de novo.'}
          <button type="button" onClick={geo.request} className="mt-2 flex items-center gap-1.5 text-xs font-semibold text-danger"><RefreshCw size={13} aria-hidden="true" /> Tentar de novo</button>
        </div>
      )}
      {tooImprecise && (
        <div role="alert" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">
          A localização está imprecisa (±{Math.round(geo.accuracy as number)} m),os vizinhos não o iam encontrar. Ligue o GPS, vá para um sítio aberto e tente de novo.
          <button type="button" onClick={geo.request} className="mt-2 flex items-center gap-1.5 text-xs font-semibold"><RefreshCw size={13} aria-hidden="true" /> Tentar de novo</button>
        </div>
      )}

      {located && !tooImprecise && (
        <>
          {/* 2. O quê */}
          <fieldset>
            <legend className="text-xs font-semibold uppercase tracking-wide text-gray">O que partilhar</legend>
            {nearby.isLoading && <p className="mt-2 flex items-center gap-2 text-xs text-gray"><Loader2 size={13} className="animate-spin" aria-hidden="true" /> A procurar postos aqui perto…</p>}
            <div className="mt-2 space-y-2">
              {here && (
                <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${kind === 'station' ? 'border-primary bg-primary/5' : 'border-gray/20'}`}>
                  <input type="radio" name="vz-loc-kind" className="mt-1" checked={kind === 'station'} onChange={() => setKind('station')} />
                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-1.5 text-sm font-semibold text-dark"><Fuel size={15} className="text-primary" aria-hidden="true" /> Estou no {here.name}</span>
                    <span className="mt-0.5 block text-xs text-gray">Recomendado: os vizinhos veem o posto, não a sua posição exata.</span>
                  </span>
                </label>
              )}
              <label className={`flex cursor-pointer items-start gap-3 rounded-xl border p-3 ${kind === 'point' ? 'border-primary bg-primary/5' : 'border-gray/20'}`}>
                <input type="radio" name="vz-loc-kind" className="mt-1" checked={kind === 'point'} onChange={() => setKind('point')} />
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5 text-sm font-semibold text-dark"><LocateFixed size={15} className="text-primary" aria-hidden="true" /> A minha posição exata</span>
                  <span className="mt-0.5 block text-xs text-gray">
                    O ponto onde o telemóvel está agora{geo.accuracy !== null ? ` (precisão ±${Math.round(geo.accuracy)} m)` : ''}.
                  </span>
                </span>
              </label>
            </div>
          </fieldset>

          {/* 3. Durante quanto tempo */}
          <fieldset className="mt-4">
            <legend className="text-xs font-semibold uppercase tracking-wide text-gray">Durante quanto tempo</legend>
            <div className="mt-2 flex gap-2" role="radiogroup" aria-label="Durante quanto tempo">
              {options.map((o) => (
                <button
                  key={o.minutes}
                  type="button"
                  role="radio"
                  aria-checked={minutes === o.minutes}
                  onClick={() => setMinutes(o.minutes)}
                  className={`flex-1 rounded-full border px-3 py-2 text-sm font-semibold ${minutes === o.minutes ? 'border-primary bg-primary text-white' : 'border-gray/20 text-dark'}`}
                >
                  {o.label}
                </button>
              ))}
            </div>
          </fieldset>

          <p className="mt-4 flex items-start gap-2 rounded-xl bg-light p-3 text-xs text-dark">
            <ShieldCheck size={15} className="mt-0.5 shrink-0 text-primary" aria-hidden="true" />
            <span>
              Todos os vizinhos de <strong>{overview.municipality.name}</strong> vão ver {kind === 'station' && here ? <>que está no <strong>{here.name}</strong></> : 'onde está'}
              {endsAt ? ` até às ${endsAt}` : ''}. Pode parar quando quiser. No fim, a localização é <strong>apagada</strong>.
            </span>
          </p>
        </>
      )}

      {error && <p role="alert" className="mt-3 rounded-lg bg-danger/10 px-3 py-2 text-xs text-danger">{error}</p>}

      <button
        type="button"
        disabled={!ready}
        onClick={() => { setError(null); share.mutate() }}
        className="mt-4 flex w-full items-center justify-center gap-2 rounded-full bg-primary py-3 text-sm font-semibold text-white disabled:opacity-50"
      >
        {share.isPending ? <Loader2 size={16} className="animate-spin" aria-hidden="true" /> : <MapPin size={16} aria-hidden="true" />}
        Partilhar localização
      </button>
    </Sheet>
  )
}
