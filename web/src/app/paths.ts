/**
 * URLs em português e com nome (Outubro 2026, SEO).
 *
 * Os endereços antigos (em inglês e /station/12) CONTINUAM a funcionar:
 * redirecionam para os novos,no navegador (router.tsx, `LEGACY_REDIRECTS`)
 * e no servidor com 301 (nginx, vercel.json, _redirects). Assim nenhum link
 * já partilhado, notificação antiga, QR code ou favorito se perde.
 *
 * Mudar um endereço aqui = mudar em todo o lado. Não renomear os que os apps
 * abrem diretamente (/pedido/, /avaliar/, /pedidos) sem atualizar também o
 * AndroidManifest.xml e o apple-app-site-association.
 */
export const PATHS = {
  find: '/encontrar-gas',
  map: '/mapa',
  order: '/pedir-gas',
  howItWorks: '/como-funciona',
  about: '/sobre',
  contact: '/contacto',
  terms: '/termos',
  privacy: '/privacidade',
} as const

/** Endereço antigo → novo (os de cima). Usado no router e nos testes. */
export const LEGACY_REDIRECTS: Array<[string, string]> = [
  ['/find', PATHS.find],
  ['/map', PATHS.map],
  ['/order', PATHS.order],
  ['/how-it-works', PATHS.howItWorks],
  ['/about', PATHS.about],
  ['/contact', PATHS.contact],
  ['/terms', PATHS.terms],
  ['/privacy', PATHS.privacy],
]

/**
 * "Posto Central,Lubango" → "posto-central-lubango". Igual ao Str::slug do
 * Laravel para letras portuguesas (acentos removidos, ç → c), para o sitemap
 * da API e o site gerarem o mesmo endereço.
 */
export function slugify(text: string): string {
  return text
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/**
 * Página de um posto: /postos/12-posto-central-lubango. O número à frente é
 * o que identifica o posto,se o nome mudar, os links antigos continuam a
 * abrir o posto certo (e o site corrige para o nome atual).
 */
export function stationPath(station: { id: number; name?: string | null }): string {
  const slug = station.name ? slugify(station.name) : ''
  return slug ? `/postos/${station.id}-${slug}` : `/postos/${station.id}`
}

/** Lê o número do posto de "12-posto-central" (ou "12"). */
export function stationIdFromParam(param: string | undefined): number {
  const match = /^(\d+)/.exec(param ?? '')
  return match ? Number(match[1]) : NaN
}
