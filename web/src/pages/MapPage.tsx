import { useEffect } from 'react'
import SubscriptionNotice from '@/components/stations/SubscriptionNotice'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { MapContainer, TileLayer, CircleMarker, Popup, useMap } from 'react-leaflet'
import 'leaflet/dist/leaflet.css'
import { stationService } from '@/services/stationService'
import { analyticsService } from '@/services/analyticsService'
import { getEcho } from '@/lib/echo'
import { markerStatusKey, markerStatuses } from '@/constants/theme'
import { LastUpdated } from '@/components/LastUpdated'
import { useLiveRefetchInterval } from '@/stores/realtimeStore'
import { PageHero } from '@/components/fx/PageHero'
import { motionLevel } from '@/lib/motion'

const ANGOLA_CENTER: [number, number] = [-11.2027, 17.8739]

/** Abertura do mapa (Outubro 2026): a câmara "voa" do continente até Angola. */
function FlyIn() {
  const map = useMap()
  useEffect(() => {
    if (motionLevel() === 'off') return
    map.setView(ANGOLA_CENTER, 4, { animate: false })
    const t = window.setTimeout(() => map.flyTo(ANGOLA_CENTER, 6, { duration: motionLevel() === 'full' ? 1.8 : 1 }), 250)
    return () => window.clearTimeout(t)
  }, [map])
  return null
}

/**
 * Página /map (secção 13). Cor nunca é o único indicador,cada marcador
 * também expõe texto via Popup (acessibilidade, secção 13/76).
 *
 * Real-time (Etapa 16): subscreve o canal público 'stations' e
 * invalida a query sempre que um posto atualiza, em vez de obrigar
 * o utilizador a recarregar a página para ver dados frescos.
 */
export default function MapPage() {
  const queryClient = useQueryClient()
  const liveInterval = useLiveRefetchInterval(60_000)

  useEffect(() => {
    analyticsService.track('map_open')
  }, [])

  useEffect(() => {
    const echo = getEcho()
    if (!echo) {
      return
    }

    const channel = echo.channel('stations')

    channel.listen('.station.updated', () => {
      queryClient.invalidateQueries({ queryKey: ['map-stations'] })
    })

    return () => {
      echo.leaveChannel('stations')
    }
  }, [queryClient])

  const stationsQuery = useQuery({
    queryKey: ['map-stations'],
    queryFn: stationService.forMap,
    refetchInterval: liveInterval,
  })

  const postos = stationsQuery.data?.stations ?? []
  // Zonas fechadas (Novembro 2026): não aparecem no mapa, mas dizemos porquê.
  const zonasFechadas = stationsQuery.data?.blocked ?? []
  const bloqueioDeConta = stationsQuery.data?.accessBlock ?? null

  return (
    <div className="mx-auto max-w-6xl px-4 py-6">
      <PageHero title="Mapa de" highlight="postos" subtitle="Os pontos que pulsam têm gás agora. Toque num posto para ver o estado." className="mb-6" />

      {(bloqueioDeConta || zonasFechadas.length > 0) && (
        <div className="mb-6">
          <SubscriptionNotice
            zones={zonasFechadas}
            message={bloqueioDeConta?.message}
            needsLogin={bloqueioDeConta?.reason === 'login_required'}
            accountBlocked={bloqueioDeConta?.reason === 'user_blocked'}
          />
        </div>
      )}

      <div className="fx-map-frame fx-ring-border fx-slide-next h-[70vh] rounded-2xl">
        <div className="h-full overflow-hidden rounded-2xl">
        <MapContainer center={ANGOLA_CENTER} zoom={6} className="h-full w-full">
          <TileLayer
            attribution='&copy; OpenStreetMap contributors'
            url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
          />
          <FlyIn />

          {/* Halo a pulsar por baixo dos postos com gás (não é clicável). */}
          {postos.filter((st) => st.gas_available).map((station) => (
            <CircleMarker
              key={`halo-${station.id}`}
              center={[station.latitude, station.longitude]}
              radius={10}
              interactive={false}
              pathOptions={{
                className: 'fx-map-halo',
                stroke: false,
                fillColor: markerStatuses[markerStatusKey(station)].color,
                fillOpacity: 0.45,
              }}
            />
          ))}

          {postos.map((station) => (
            <CircleMarker
              key={station.id}
              center={[station.latitude, station.longitude]}
              radius={9}
              pathOptions={{
                className: 'fx-map-dot',
                color: '#fff',
                weight: 2,
                fillColor: markerStatuses[markerStatusKey(station)].color,
                fillOpacity: 0.9,
              }}
            >
              <Popup>
                <strong>{station.name}</strong>
                <br />
                <span style={{ color: markerStatuses[markerStatusKey(station)].color, fontWeight: 600 }}>●</span>{' '}
                {markerStatuses[markerStatusKey(station)].label}
                <br />
                <LastUpdated at={station.status_updated_at} />
              </Popup>
            </CircleMarker>
          ))}
        </MapContainer>
        </div>
      </div>

      <div className="fx-cascade mt-5 flex flex-wrap gap-2 text-sm text-gray">
        {(['LOW', 'MEDIUM', 'HIGH', 'UNKNOWN', 'NO_GAS'] as const).map((key, i) => (
          <span key={key} style={{ ['--i' as string]: i + 4 }}>
            <Legend color={markerStatuses[key].color} label={markerStatuses[key].label} />
          </span>
        ))}
      </div>
    </div>
  )
}

function Legend({ color, label }: { color: string; label: string }) {
  return (
    <span className="fx-glass flex items-center gap-2 rounded-full px-3 py-1.5">
      <span className="h-3 w-3 rounded-full" style={{ backgroundColor: color, boxShadow: `0 0 8px ${color}` }} aria-hidden="true" />
      {label}
    </span>
  )
}
