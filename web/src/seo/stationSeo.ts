import type { Station } from '@/types'
import type { SeoMeta } from './head'
import { siteUrl } from './head'
import { SITE_NAME } from './pages'
import { stationPath } from '@/app/paths'

/**
 * SEO de um posto (Outubro 2026): "Posto X,gás butano em Lubango, Huíla".
 * A descrição diz o estado de forma honesta (com a hora da atualização) e o
 * JSON-LD descreve o posto como negócio local, com coordenadas.
 */
export function stationSeo(station: Station): SeoMeta {
  const zone = [station.municipality, station.province].filter(Boolean).join(', ')
  const title = `${station.name},gás butano${station.municipality ? ` em ${station.municipality}` : ''} | ${SITE_NAME}`
  const state = station.status_updated_at
    ? station.gas_available
      ? 'Último estado: com gás.'
      : 'Último estado: sem gás.'
    : 'Ainda sem estado recente.'
  const description = `${station.name}${zone ? ` (${zone})` : ''}: veja se tem gás butano, como está a fila e há quanto tempo foi atualizado. ${state} Rota e pedido de entrega no KeruGás.`
  const path = stationPath(station)
  const url = `${siteUrl()}${path}`

  return {
    title,
    description: description.length > 300 ? `${description.slice(0, 297)}...` : description,
    path,
    type: 'place',
    image: station.photo_url ?? undefined,
    jsonLd: {
      '@context': 'https://schema.org',
      '@type': 'LocalBusiness',
      '@id': `${url}#posto`,
      name: station.name,
      url,
      ...(station.photo_url ? { image: station.photo_url } : {}),
      ...(station.phone ? { telephone: station.phone } : {}),
      address: {
        '@type': 'PostalAddress',
        ...(station.address ? { streetAddress: station.address } : {}),
        ...(station.municipality ? { addressLocality: station.municipality } : {}),
        ...(station.province ? { addressRegion: station.province } : {}),
        addressCountry: 'AO',
      },
      geo: { '@type': 'GeoCoordinates', latitude: station.latitude, longitude: station.longitude },
      ...(station.opening_hours ? { openingHours: station.opening_hours } : {}),
      makesOffer: { '@type': 'Offer', itemOffered: { '@type': 'Product', name: 'Gás butano (garrafa)' } },
    },
  }
}
