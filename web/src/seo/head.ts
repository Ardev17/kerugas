import { DEFAULT_SITE_URL, LOCALE, OG_IMAGE_ALT, OG_IMAGE_PATH, SITE_NAME } from './pages'

/**
 * Escreve no <head> as etiquetas de SEO (Outubro 2026),título, descrição,
 * canonical, robots, Open Graph (pré-visualização no WhatsApp/Facebook) e
 * Twitter. Sem bibliotecas: reaproveita as etiquetas que já existem (as do
 * index.html ou do HTML pré-gerado) e só cria as que faltam.
 */

export interface SeoMeta {
  title: string
  description: string
  /** Caminho canónico (ex.: "/postos/12-posto-central"). Por omissão, o caminho atual. */
  path?: string
  noindex?: boolean
  image?: string
  type?: 'website' | 'article' | 'place'
  /** Dados estruturados específicos da página (FAQ, posto...). null apaga. */
  jsonLd?: object | null
}

export function siteUrl(): string {
  const fromEnv = (import.meta.env.VITE_SITE_URL as string | undefined)?.trim()
  return (fromEnv || DEFAULT_SITE_URL).replace(/\/+$/, '')
}

function upsertMeta(attr: 'name' | 'property', key: string, content: string) {
  let el = document.head.querySelector<HTMLMetaElement>(`meta[${attr}="${key}"]`)
  if (!el) {
    el = document.createElement('meta')
    el.setAttribute(attr, key)
    document.head.appendChild(el)
  }
  el.setAttribute('content', content)
}

function upsertLink(rel: string, href: string) {
  let el = document.head.querySelector<HTMLLinkElement>(`link[rel="${rel}"]`)
  if (!el) {
    el = document.createElement('link')
    el.setAttribute('rel', rel)
    document.head.appendChild(el)
  }
  el.setAttribute('href', href)
}

function setPageJsonLd(data: object | null | undefined) {
  const existing = document.head.querySelector('script[type="application/ld+json"][data-seo="page"]')
  if (!data) {
    existing?.remove()
    return
  }
  const el = existing ?? document.createElement('script')
  el.setAttribute('type', 'application/ld+json')
  el.setAttribute('data-seo', 'page')
  el.textContent = JSON.stringify(data)
  if (!existing) document.head.appendChild(el)
}

export function applySeo(meta: SeoMeta): void {
  if (typeof document === 'undefined') return
  const base = siteUrl()
  const path = meta.path ?? window.location.pathname
  const url = `${base}${path === '/' ? '/' : path.replace(/\/+$/, '')}`
  const image = meta.image ? (meta.image.startsWith('http') ? meta.image : `${base}${meta.image}`) : `${base}${OG_IMAGE_PATH}`

  document.title = meta.title
  upsertMeta('name', 'description', meta.description)
  upsertMeta('name', 'robots', meta.noindex ? 'noindex, nofollow' : 'index, follow, max-image-preview:large')
  upsertLink('canonical', url)

  upsertMeta('property', 'og:site_name', SITE_NAME)
  upsertMeta('property', 'og:locale', LOCALE)
  upsertMeta('property', 'og:type', meta.type === 'place' ? 'place' : meta.type ?? 'website')
  upsertMeta('property', 'og:title', meta.title)
  upsertMeta('property', 'og:description', meta.description)
  upsertMeta('property', 'og:url', url)
  upsertMeta('property', 'og:image', image)
  upsertMeta('property', 'og:image:alt', OG_IMAGE_ALT)
  upsertMeta('name', 'twitter:card', 'summary_large_image')
  upsertMeta('name', 'twitter:title', meta.title)
  upsertMeta('name', 'twitter:description', meta.description)
  upsertMeta('name', 'twitter:image', image)

  setPageJsonLd(meta.jsonLd)
}
