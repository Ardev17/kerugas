import { Link } from 'react-router-dom'
import { Flame, Navigation, Clock, ShieldCheck } from 'lucide-react'
import { LastUpdated } from '@/components/LastUpdated'
import { RouteLink } from '@/components/RouteLink'
import type { Station } from '@/types'
import { queueLevelColor, queueLevelLabel } from '@/constants/theme'
import { stationPath } from '@/app/paths'
import { Spotlight } from '@/components/fx/Spotlight'

const QUEUE_BARS: Record<string, number> = { LOW: 1, MEDIUM: 2, HIGH: 3 }

export function StationCard({ station }: { station: Station }) {
  return (
    <Spotlight className="h-full rounded-2xl border border-gray/15 bg-white shadow-sm">
    <article className="p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <h3 className="font-semibold text-dark">{station.name}</h3>
          <p className="text-sm text-gray">
            {station.municipality}{station.distance_km !== undefined && ` · ${station.distance_km} km`}
          </p>
        </div>

        <span
          className={`flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ${
            station.gas_available ? 'bg-success/10 text-success' : 'bg-danger/10 text-danger'
          }`}
        >
          {station.gas_available ? <span className="fx-live-dot" aria-hidden="true" /> : null}
          <Flame size={14} aria-hidden="true" className={station.gas_available ? 'fx-flicker-icon' : ''} />
          {station.gas_available ? 'Gás disponível' : 'Gás indisponível'}
        </span>
      </div>

      <div className="mt-3 flex flex-wrap items-center gap-4 text-sm text-gray">
        {station.queue_level && (
          <span className="flex items-center gap-1.5">
            <span className="fx-meter" style={{ ['--meter-color' as string]: queueLevelColor[station.queue_level] }} aria-hidden="true">
              {[1, 2, 3].map((n) => (
                <i key={n} className={n <= (QUEUE_BARS[station.queue_level ?? ''] ?? 0) ? 'on' : ''} />
              ))}
            </span>
            {queueLevelLabel[station.queue_level] ?? station.queue_level}
          </span>
        )}

        <span className="flex items-center gap-1.5">
          <Clock size={14} aria-hidden="true" />
          <LastUpdated at={station.status_updated_at} />
        </span>

        <span className="flex items-center gap-1.5">
          <ShieldCheck size={14} aria-hidden="true" />
          {station.confidence_score}% confiança
        </span>
      </div>

      <div className="fx-bar mt-3" aria-hidden="true">
        <i style={{ width: `${Math.max(0, Math.min(100, station.confidence_score))}%` }} />
      </div>

      <div className="mt-4 flex gap-2">
        <Link
          to={stationPath(station)}
          className="fx-btn-ghost flex-1 rounded-xl border border-gray/20 px-3 py-2.5 text-center text-sm font-medium text-dark hover:bg-light"
        >
          Ver detalhes
        </Link>
        <RouteLink
          latitude={station.latitude}
          longitude={station.longitude}
          className="fx-btn-fire flex flex-1 items-center justify-center gap-1.5 rounded-xl px-3 py-2.5 text-sm font-semibold text-white"
        >
          <Navigation size={14} aria-hidden="true" />
          Traçar rota
        </RouteLink>
      </div>
    </article>
    </Spotlight>
  )
}
