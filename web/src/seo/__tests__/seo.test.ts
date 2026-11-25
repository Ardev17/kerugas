import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it } from 'vitest'
import { DEFAULT_SITE_URL, FAQ_GERAL, PUBLIC_PAGES, findPublicPage, isPrivatePath } from '../pages'
import { applySeo } from '../head'
import { defaultSeoFor } from '../useSeo'
import { stationSeo } from '../stationSeo'
import { renderLlmsTxt, renderPageBody } from '../prerenderHtml'
import { renderPage, robotsTxt, sitemapIndex, sitemapPages } from '../../../plugins/seoPrerender'
import type { Station } from '@/types'

/** SEO e GEO (Outubro 2026). */
describe('páginas públicas', () => {
  it('cada página pública existe no router', () => {
    const router = readFileSync(resolve(__dirname, '../../app/router.tsx'), 'utf8')
    for (const p of PUBLIC_PAGES) expect(router, p.path).toContain(`path="${p.path}"`)
  })

  it('títulos até 60 caracteres e descrições até 160, sem repetidos', () => {
    for (const p of PUBLIC_PAGES) {
      expect(p.title.length, p.title).toBeLessThanOrEqual(60)
      expect(p.description.length, p.path).toBeLessThanOrEqual(160)
    }
    expect(new Set(PUBLIC_PAGES.map((p) => p.title)).size).toBe(PUBLIC_PAGES.length)
    expect(new Set(PUBLIC_PAGES.map((p) => p.description)).size).toBe(PUBLIC_PAGES.length)
  })

  it('nenhuma página pública está marcada como privada', () => {
    for (const p of PUBLIC_PAGES) expect(isPrivatePath(p.path), p.path).toBe(false)
  })

  it('painéis, conta e pedidos são privados; postos e páginas institucionais não', () => {
    for (const p of ['/admin', '/admin/investidores', '/driver', '/operator/analytics', '/investidor', '/perfil', '/pedido/KG-1', '/order/success/KG-1', '/avaliar/KG-1', '/login']) {
      expect(isPrivatePath(p), p).toBe(true)
    }
    for (const p of ['/', '/pedir-gas', '/postos/12-posto-central', '/como-funciona', '/administracao-de-gas']) expect(isPrivatePath(p), p).toBe(false)
  })

  it('encontra a página com ou sem barra final', () => {
    expect(findPublicPage('/como-funciona/')?.path).toBe('/como-funciona')
    expect(findPublicPage('/nao-existe')).toBeUndefined()
  })
})

describe('SeoManager / applySeo', () => {
  afterEach(() => {
    document.head.innerHTML = ''
  })

  it('privadas e inexistentes levam noindex; postos e públicas são indexáveis', () => {
    expect(defaultSeoFor('/admin/billing').noindex).toBe(true)
    expect(defaultSeoFor('/qualquer-coisa').noindex).toBe(true)
    expect(defaultSeoFor('/postos/7').noindex).toBeFalsy()
    expect(defaultSeoFor('/postos/7-posto-central-lubango').noindex).toBeFalsy()
    expect(defaultSeoFor('/como-funciona').noindex).toBeFalsy()
    expect(defaultSeoFor('/como-funciona').jsonLd).toMatchObject({ '@type': 'FAQPage' })
  })

  it('escreve título, descrição, canonical, robots e Open Graph', () => {
    applySeo({ title: 'T', description: 'D', path: '/encontrar-gas' })
    expect(document.title).toBe('T')
    expect(document.head.querySelector('meta[name="description"]')?.getAttribute('content')).toBe('D')
    expect(document.head.querySelector('link[rel="canonical"]')?.getAttribute('href')).toBe(`${DEFAULT_SITE_URL}/encontrar-gas`)
    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toMatch(/^index/)
    expect(document.head.querySelector('meta[property="og:image"]')?.getAttribute('content')).toBe(`${DEFAULT_SITE_URL}/branding/og-image.jpg`)

    applySeo({ title: 'Admin', description: 'D', path: '/admin', noindex: true })
    expect(document.head.querySelectorAll('meta[name="robots"]')).toHaveLength(1)
    expect(document.head.querySelector('meta[name="robots"]')?.getAttribute('content')).toBe('noindex, nofollow')
  })

  it('os dados estruturados da página trocam-se e apagam-se', () => {
    applySeo({ title: 'A', description: 'D', jsonLd: { '@type': 'FAQPage' } })
    expect(document.head.querySelectorAll('script[data-seo="page"]')).toHaveLength(1)
    applySeo({ title: 'B', description: 'D', jsonLd: null })
    expect(document.head.querySelectorAll('script[data-seo="page"]')).toHaveLength(0)
  })
})

describe('posto', () => {
  const station = {
    id: 12, name: 'Posto Central', slug: 'posto-central', province: 'Huíla', municipality: 'Lubango', address: 'Rua 1',
    reference_point: null, latitude: -14.9, longitude: 13.5, phone: null, opening_hours: null, photo_url: null, status: 'ACTIVE',
    gas_available: true, queue_level: 'LOW', cylinder_types: null, confidence_score: 80, freshness: 'fresh', freshness_label: '',
    status_updated_at: '2026-10-14T10:00:00Z',
  } as unknown as Station

  it('título com o nome e a zona, e negócio local com coordenadas', () => {
    const seo = stationSeo(station)
    expect(seo.title).toContain('Posto Central')
    expect(seo.title).toContain('Lubango')
    expect(seo.path).toBe('/postos/12-posto-central')
    expect(seo.jsonLd).toMatchObject({ '@type': 'LocalBusiness', geo: { latitude: -14.9, longitude: 13.5 }, address: { addressLocality: 'Lubango', addressCountry: 'AO' } })
    expect(seo.description).toContain('com gás')
  })
})

describe('build: HTML pré-gerado, sitemap, robots e llms.txt', () => {
  const shell = '<html><head><title>X</title><meta name="description" content="x" /><link rel="canonical" href="https://www.kerugas.ao/" /></head><body><div id="root"></div></body></html>'

  it('cada página tem o seu título, canonical e conteúdo com um só H1', () => {
    const page = PUBLIC_PAGES.find((p) => p.path === '/como-funciona')!
    const html = renderPage(shell, page, 'https://www.kerugas.ao')
    expect(html).toContain(`<title>${page.title}</title>`)
    expect(html).toContain('<link rel="canonical" href="https://www.kerugas.ao/como-funciona" />')
    expect(html).toContain('"@type":"FAQPage"')
    expect(html.match(/<h1/g)).toHaveLength(1)
    expect(html).not.toContain('<div id="root"></div>')
  })

  it('o texto é escapado (nada de HTML injetado)', () => {
    const body = renderPageBody({ ...PUBLIC_PAGES[0], intro: '<script>alert(1)</script>' })
    expect(body).not.toContain('<script>')
    expect(body).toContain('&lt;script&gt;')
  })

  it('sitemap com todas as páginas públicas e índice com o dos postos', () => {
    const pages = sitemapPages('https://www.kerugas.ao', '2026-10-14')
    for (const p of PUBLIC_PAGES) expect(pages).toContain(`<loc>https://www.kerugas.ao${p.path}</loc>`)
    expect(sitemapIndex('https://www.kerugas.ao', '2026-10-14')).toContain('/sitemap-postos.xml')
  })

  it('robots.txt bloqueia as áreas privadas e indica o sitemap', () => {
    const robots = robotsTxt('https://www.kerugas.ao')
    expect(robots).toContain('Disallow: /admin')
    expect(robots).toContain('Disallow: /api')
    expect(robots).not.toMatch(/Disallow: \/\s*$/m)
    expect(robots).toContain('Sitemap: https://www.kerugas.ao/sitemap.xml')
  })

  it('llms.txt resume o produto, as páginas e as perguntas frequentes', () => {
    const llms = renderLlmsTxt('https://www.kerugas.ao')
    expect(llms.startsWith('# KeruGás')).toBe(true)
    expect(llms).toContain('https://www.kerugas.ao/pedir-gas')
    expect(llms).toContain(FAQ_GERAL[0].q)
  })
})
