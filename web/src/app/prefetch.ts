/**
 * Velocidade (Outubro 2026): pouco depois de a app abrir, as páginas mais
 * usadas descarregam em segundo plano,quando a pessoa toca nelas já
 * estão prontas e abrem sem "A carregar...". Com "Poupança de dados"
 * ligada no telemóvel não descarrega nada antecipadamente.
 */
const COMMON_PAGES = [
  () => import('@/pages/StationDetails'),
  () => import('@/pages/Login'),
  () => import('@/pages/OrderGas'),
  () => import('@/pages/MyOrders'),
  () => import('@/pages/OrderTracking'),
  () => import('@/pages/AppMenu'),
  () => import('@/pages/Dashboard'),
  () => import('@/pages/Vizinhos'),
]

export function prefetchCommonPages(delayMs = 2500): () => void {
  const connection = (navigator as Navigator & { connection?: { saveData?: boolean } }).connection
  if (connection?.saveData) return () => {}

  const timer = window.setTimeout(() => {
    for (const load of COMMON_PAGES) void load().catch(() => {})
  }, delayMs)
  return () => window.clearTimeout(timer)
}
