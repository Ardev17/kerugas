import { MapContainer, Marker, TileLayer, Circle } from 'react-leaflet'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { theme } from '@/constants/theme'

/**
 * Mini-mapa de uma localização partilhada nos Vizinhos (Outubro 2026). Parado
 * (sem arrastar nem zoom): é só para se ver onde é,a rota abre no Google
 * Maps. Carregado à parte (React.lazy), por isso o Leaflet só desce quando há
 * uma partilha ativa na conversa.
 */
// Ícone de bomba (o mesmo desenho do Fuel do lucide),SVG em vez de emoji,
// para ficar igual em todos os telemóveis.
const FUEL = '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" x2="15" y1="22" y2="22"/><line x1="4" x2="14" y1="9" y2="9"/><path d="M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18"/><path d="M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2a2 2 0 0 0 2-2V9.83a2 2 0 0 0-.59-1.42L18 5"/></svg>'

const pin = (kind: 'point' | 'station') =>
  L.divIcon({
    className: '',
    html:
      kind === 'station'
        ? `<div style="width:34px;height:34px;border-radius:50%;background:${theme.primary};border:3px solid white;box-shadow:0 2px 8px rgba(0,0,0,.35);display:flex;align-items:center;justify-content:center">${FUEL}</div>`
        : `<div style="position:relative;width:22px;height:22px"><span style="position:absolute;inset:-9px;border-radius:50%;background:${theme.primary};opacity:.22;animation:vzpulse 1.8s ease-out infinite"></span><span style="position:absolute;inset:0;border-radius:50%;background:${theme.primary};border:3px solid white;box-shadow:0 2px 6px rgba(0,0,0,.35)"></span></div><style>@keyframes vzpulse{0%{transform:scale(.6);opacity:.35}100%{transform:scale(1.6);opacity:0}}</style>`,
    iconSize: kind === 'station' ? [34, 34] : [22, 22],
    iconAnchor: kind === 'station' ? [17, 17] : [11, 11],
  })

export default function LocationMiniMap({ latitude, longitude, accuracy, kind }: { latitude: number; longitude: number; accuracy: number | null; kind: 'point' | 'station' }) {
  return (
    <MapContainer
      center={[latitude, longitude]}
      zoom={16}
      dragging={false}
      touchZoom={false}
      doubleClickZoom={false}
      scrollWheelZoom={false}
      boxZoom={false}
      keyboard={false}
      zoomControl={false}
      attributionControl={false}
      style={{ height: 150, width: '100%' }}
    >
      <TileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
      {kind === 'point' && accuracy !== null && accuracy > 15 && (
        <Circle center={[latitude, longitude]} radius={accuracy} pathOptions={{ color: theme.primary, weight: 1, fillOpacity: 0.08 }} />
      )}
      <Marker position={[latitude, longitude]} icon={pin(kind)} interactive={false} />
    </MapContainer>
  )
}
