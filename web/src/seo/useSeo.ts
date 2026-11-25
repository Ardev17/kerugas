import { useEffect } from 'react'
import { applySeo, type SeoMeta } from './head'
import { DEFAULT_DESCRIPTION, DEFAULT_TITLE, SITE_NAME, faqJsonLd, findPublicPage, isPrivatePath } from './pages'

export function defaultSeoFor(pathname: string): SeoMeta {
  const page = findPublicPage(pathname)
  if (page) {
    return { title: page.title, description: page.description, path: page.path, jsonLd: page.faq ? faqJsonLd(page.faq) : null }
  }
  // Detalhe do posto: indexável; o título final chega quando o posto carrega.
  if (/^\/postos\/\d+(-[a-z0-9-]*)?\/?$/.test(pathname)) {
    return { title: `Posto de gás | ${SITE_NAME}`, description: DEFAULT_DESCRIPTION, jsonLd: null }
  }
  // Painéis, conta, pedidos de cada pessoa e páginas inexistentes: fora dos motores de pesquisa.
  return {
    title: isPrivatePath(pathname) ? SITE_NAME : DEFAULT_TITLE,
    description: DEFAULT_DESCRIPTION,
    noindex: true,
    jsonLd: null,
  }
}

/**
 * SEO próprio de uma página (sobrepõe-se ao do <SeoManager>). Passar `null`
 * enquanto os dados ainda não chegaram.
 */
export function useSeo(meta: SeoMeta | null) {
  const key = meta ? JSON.stringify(meta) : null
  useEffect(() => {
    if (meta) applySeo(meta)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])
}
