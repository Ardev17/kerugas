import { useMemo, useRef } from 'react'
import { MapContainer, TileLayer, Marker, Circle } from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { theme } from '@/constants/theme'

interface LocationPickerProps {
  latitude: number
  longitude: number
  accuracy?: number | null
  onChange: (lat: number, lng: number) => void
}

// Ícone próprio em vez do ícone por omissão do Leaflet,evita o
// problema clássico de bundlers (Vite/webpack) partirem os caminhos
// dos ficheiros de imagem do marcador por omissão, e mantém a cor da
// marca em vez do azul genérico do Leaflet.
const pinIcon = L.divIcon({
  className: '',
  html: `<div style="width:26px;height:26px;border-radius:50% 50% 50% 0;background:${theme.primary};transform:rotate(-45deg);border:2px solid white;box-shadow:0 1px 4px rgba(0,0,0,0.4)"></div>`,
  iconSize: [26, 26],
  iconAnchor: [13, 26],
})

/**
 * Deixa o utilizador AJUSTAR manualmente a localização detetada
 * automaticamente. Necessário porque `navigator.geolocation` num
 * computador (sem chip GPS) recorre a geolocalização por IP, que em
 * Angola frequentemente resolve para a sede do ISP (tipicamente
 * Luanda) em vez da cidade real do utilizador,uma limitação da
 * infraestrutura de rede, fora do alcance do que JavaScript consegue
 * corrigir sozinho. O círculo mostra visualmente o raio de incerteza
 * (`accuracy`, em metros) devolvido pelo browser, para o utilizador
 * perceber se vale a pena ajustar o pino.
 */
export function LocationPicker({ latitude, longitude, accuracy, onChange }: LocationPickerProps) {
  const markerRef = useRef<L.Marker>(null)

  const eventHandlers = useMemo(
    () => ({
      dragend() {
        const marker = markerRef.current
        if (marker) {
          const { lat, lng } = marker.getLatLng()
          onChange(lat, lng)
        }
      },
    }),
    [onChange]
  )

  const accuracyLabel = accuracy
    ? accuracy >= 1000
      ? `~${(accuracy / 1000).toFixed(1)} km`
      : `~${Math.round(accuracy)} m`
    : null

  return (
    <div>
      <div className="overflow-hidden rounded-lg border border-gray/20">
        <MapContainer center={[latitude, longitude]} zoom={14} style={{ height: 220, width: '100%' }}>
          <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" attribution="&copy; OpenStreetMap" />
          {accuracy && <Circle center={[latitude, longitude]} radius={accuracy} pathOptions={{ color: theme.primary, fillOpacity: 0.08 }} />}
          <Marker
            position={[latitude, longitude]}
            draggable
            eventHandlers={eventHandlers}
            icon={pinIcon}
            ref={markerRef}
          />
        </MapContainer>
      </div>
      <p className="mt-1.5 text-xs text-gray">
        {accuracyLabel
          ? `Precisão aproximada do browser: ${accuracyLabel}. Se o pino não estiver no sítio certo, arraste-o para a localização exata.`
          : 'Arraste o pino se não estiver no sítio certo.'}
      </p>
    </div>
  )
}
