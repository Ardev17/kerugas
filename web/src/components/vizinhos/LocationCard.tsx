import { lazy, Suspense, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { Clock, Fuel, MapPin, Navigation, ShieldCheck, Square } from 'lucide-react'
import { queueLevelLabel } from '@/constants/theme'
import { directionsUrl } from '@/lib/geo'
import { clockTime, locationLive, remainingLabel } from '@/lib/vizinhos'
import { stationPath } from '@/app/paths'
import type { VzMessage } from '@/types/vizinhos'

const LocationMiniMap = lazy(() => import('./LocationMiniMap'))

/** Relógio que avança sozinho (para a contagem "faltam X min"). */
function useNow(everyMs: number): Date {
  const [now, setNow] = useState(() => new Date())
  useEffect(() => {
    const t = setInterval(() => setNow(new Date()), everyMs)
    return () => clearInterval(t)
  }, [everyMs])
  return now
}

/**
 * Localização partilhada numa mensagem dos Vizinhos (Outubro 2026).
 * Ativa: mini-mapa, "Traçar rota", contagem e, para quem partilhou, "Parar".
 * Terminada: só o aviso,as coordenadas já foram apagadas no servidor.
 */
export function LocationCard({ message, onStop, stopping }: { message: VzMessage; onStop?: (m: VzMessage) => void; stopping?: boolean }) {
  const now = useNow(30_000)
  const loc = message.location
  if (!loc) return null

  const live = locationLive(loc, now)
  const isStation = loc.kind === 'station'

  if (!live) {
    return (
      <div className="mt-2 flex items-start gap-2.5 rounded-xl border border-dashed border-gray/30 bg-light px-3 py-2.5 text-xs text-gray">
        {isStation ? <Fuel size={16} className="mt-0.5 shrink-0" aria-hidden="true" /> : <MapPin size={16} className="mt-0.5 shrink-0" aria-hidden="true" />}
        <p>
          {isStation && loc.label ? <>Esteve no <strong className="text-dark">{loc.label}</strong>. </> : 'Partilhou a localização. '}
          A partilha terminou e, por segurança, a localização já foi apagada.
        </p>
      </div>
    )
  }

  const lat = loc.latitude as number
  const lng = loc.longitude as number
  const route = directionsUrl(lat, lng)
  const station = loc.station

  return (
    <div className="mt-2 overflow-hidden rounded-2xl border border-primary/25 bg-white shadow-sm">
      <div className="flex items-center gap-2.5 px-3 pt-3">
        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full ${isStation ? 'bg-primary text-white' : 'bg-primary/10 text-primary'}`}>
          {isStation ? <Fuel size={18} aria-hidden="true" /> : <MapPin size={18} aria-hidden="true" />}
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold text-dark">
            {isStation ? <>Está no {station?.name ?? loc.label}</> : 'Partilhou a localização'}
          </p>
          <p className="text-[11px] text-gray">
            {isStation
              ? [station?.gas_available === true ? '🟢 Com gás' : station?.gas_available === false ? 'Sem gás' : null, station?.queue_level ? queueLevelLabel[station.queue_level] : null].filter(Boolean).join(' · ') || 'Posto KeruGás'
              : loc.accuracy !== null ? `Precisão ±${loc.accuracy} m` : 'Posição do telemóvel'}
          </p>
        </div>
        <span className="flex shrink-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700">
          <span className="fx-live-dot" aria-hidden="true" /> Ao vivo
        </span>
      </div>

      {/* `isolate`: as camadas do Leaflet (z-index 400+) ficam dentro do cartão e
          nunca passam por cima da caixa de escrever nem da barra de baixo. */}
      <div className="relative isolate z-0 mt-2.5 border-y border-gray/10 bg-light">
        <Suspense fallback={<div className="h-[150px] animate-pulse bg-light" />}>
          <LocationMiniMap latitude={lat} longitude={lng} accuracy={loc.accuracy} kind={loc.kind} />
        </Suspense>
      </div>

      <div className="space-y-2 p-3">
        <p className="flex items-center gap-1.5 text-[11px] text-gray">
          <Clock size={12} aria-hidden="true" />
          Até às {loc.expires_at ? clockTime(loc.expires_at) : '—'} · {loc.expires_at ? remainingLabel(loc.expires_at, now) : ''}
        </p>
        <div className="flex flex-wrap gap-2">
          {route && (
            <a href={route} target="_blank" rel="noopener noreferrer" className="flex flex-1 items-center justify-center gap-1.5 rounded-full bg-primary px-3 py-2 text-xs font-semibold text-white">
              <Navigation size={14} aria-hidden="true" /> Traçar rota
            </a>
          )}
          {isStation && station && (
            <Link to={stationPath(station)} className="flex items-center justify-center gap-1.5 rounded-full border border-gray/20 px-3 py-2 text-xs font-semibold text-dark">
              <Fuel size={14} aria-hidden="true" /> Ver posto
            </Link>
          )}
          {loc.can_stop && onStop && (
            <button type="button" disabled={stopping} onClick={() => onStop(message)} className="flex items-center justify-center gap-1.5 rounded-full border border-danger/30 px-3 py-2 text-xs font-semibold text-danger disabled:opacity-60">
              <Square size={12} aria-hidden="true" /> {stopping ? 'A parar…' : 'Parar de partilhar'}
            </button>
          )}
        </div>
        {loc.can_stop && (
          <p className="flex items-start gap-1.5 text-[11px] text-gray">
            <ShieldCheck size={12} className="mt-0.5 shrink-0" aria-hidden="true" />
            Só os vizinhos do seu município veem. No fim, a localização é apagada.
          </p>
        )}
      </div>
    </div>
  )
}
