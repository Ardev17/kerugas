/**
 * Utilitários de localização e rota (Outubro 2026).
 *
 * Um só sítio para decidir "estas coordenadas servem?" e "qual é o link
 * de rota?",antes o link do Google Maps estava copiado em 5 ecrãs, uns
 * com verificação de nulos e outros sem (um posto sem coordenadas dava
 * `destination=null,null`).
 */

/** Número finito dentro dos limites da Terra. Rejeita NaN, strings e (0, 0) vazio. */
export function isValidCoordinate(lat: unknown, lng: unknown): lat is number {
  return (
    typeof lat === 'number' &&
    typeof lng === 'number' &&
    Number.isFinite(lat) &&
    Number.isFinite(lng) &&
    lat >= -90 &&
    lat <= 90 &&
    lng >= -180 &&
    lng <= 180
  )
}

/**
 * Link "Traçar rota" do Google Maps até ao destino. Abre a app do Google
 * Maps no telemóvel, ou o site no computador, com a origem na localização
 * atual da pessoa. `null` quando as coordenadas não servem,quem chama
 * esconde o botão em vez de mandar a pessoa para um destino inválido.
 */
export function directionsUrl(lat: unknown, lng: unknown): string | null {
  if (!isValidCoordinate(lat, lng)) return null
  return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`
}

export type GeoFailureReason = 'permission_denied' | 'unavailable' | 'timeout' | 'insecure' | 'unsupported' | 'gps_off' | 'invalid'

/** Mensagens para o cliente, em português claro e com o passo seguinte. */
export const GEO_MESSAGES: Record<GeoFailureReason, string> = {
  permission_denied:
    'A localização está bloqueada. Permita-a nas definições do navegador (ícone do cadeado, junto ao endereço) ou escolha a zona abaixo.',
  unavailable: 'Não conseguimos detetar a sua posição agora. Ligue o GPS ou a internet, ou escolha a zona abaixo.',
  timeout: 'A localização demorou demasiado. Vá para um sítio mais aberto e tente outra vez, ou escolha a zona abaixo.',
  insecure: 'A localização só funciona numa ligação segura (https). Escolha a zona abaixo.',
  unsupported: 'Este dispositivo não suporta localização. Escolha a zona abaixo.',
  gps_off: 'O GPS do telemóvel está desligado. Ative a localização nas definições rápidas e tente novamente.',
  invalid: 'Recebemos uma posição inválida. Tente outra vez ou escolha a zona abaixo.',
}
