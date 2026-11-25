import { Navigate, useLocation, useParams } from 'react-router-dom'

/**
 * Endereços antigos (Outubro 2026): /find → /encontrar-gas, /station/12 →
 * /postos/12, etc. Mantém a pesquisa (?repetir=1) e o "#", e substitui o
 * histórico (o botão "voltar" não fica preso no endereço antigo).
 */
export function LegacyRedirect({ to }: { to: string }) {
  const { search, hash } = useLocation()
  return <Navigate to={`${to}${search}${hash}`} replace />
}

/** /station/12 → /postos/12 (a página do posto completa o nome). */
export function LegacyStationRedirect() {
  const { id } = useParams<{ id: string }>()
  const { search, hash } = useLocation()
  return <Navigate to={`/postos/${encodeURIComponent(id ?? '')}${search}${hash}`} replace />
}
