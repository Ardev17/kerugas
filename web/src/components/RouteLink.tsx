import type { ReactNode } from 'react'
import { directionsUrl } from '@/lib/geo'

/**
 * Botão "Traçar rota" (Outubro 2026): abre o Google Maps com a rota até ao
 * destino, a partir de onde a pessoa está. Se as coordenadas não servem,
 * não mostra nada,nunca um link para um destino inválido.
 *
 * `rel="noopener noreferrer"` + `target="_blank"`: a página de origem não
 * perde o estado e o Google Maps não recebe a nossa URL. No app Android o
 * link sai do WebView e abre a app do Google Maps (ou o navegador).
 */
export function RouteLink({
  latitude,
  longitude,
  className,
  children,
}: {
  latitude: unknown
  longitude: unknown
  className?: string
  children: ReactNode
}) {
  const href = directionsUrl(latitude, longitude)
  if (!href) return null

  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className={className}>
      {children}
    </a>
  )
}
