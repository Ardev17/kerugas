import fs from 'node:fs'
import path from 'node:path'
import type { Plugin } from 'vite'
import {
  DEFAULT_DESCRIPTION, DEFAULT_SITE_URL, DEFAULT_TITLE, LOCALE, OG_IMAGE_ALT, OG_IMAGE_PATH, PRIVATE_PREFIXES,
  PUBLIC_PAGES, SITE_NAME, faqJsonLd, organizationJsonLd, type PublicPage,
} from '../src/seo/pages'
import { escapeHtml, renderLlmsTxt, renderPageBody } from '../src/seo/prerenderHtml'

/**
 * SEO e GEO no build do SITE (Outubro 2026). Nos builds do app (android/ios)
 * não faz nada,o app não é indexado.
 *
 * 1. index.html: etiquetas por omissão (Open Graph, Twitter, canonical,
 *    robots), dados estruturados da organização e, se existir
 *    VITE_GOOGLE_SITE_VERIFICATION, a verificação do Google Search Console.
 * 2. Depois do build, para cada página pública de src/seo/pages.ts, escreve
 *    um HTML já preenchido (título, descrição, canonical e o conteúdo da
 *    página dentro do #root): "/" → index.html, "/how-it-works" →
 *    how-it-works.html, etc. Os robôs que não executam JavaScript (a maioria
 *    dos das IAs) passam a ver texto real em vez de uma página vazia.
 * 3. _app.html: a "casca" vazia da aplicação, usada pelo nginx para todas as
 *    outras rotas (painéis, conta...),ver infrastructure/docker/Dockerfile.frontend.
 * 4. sitemap.xml (índice) + sitemap-paginas.xml, robots.txt e llms.txt.
 *    O sitemap-postos.xml é gerado pela API (lista de postos ao vivo).
 */
export function seoPrerender(mode: string): Plugin {
  const isApp = mode === 'android' || mode === 'ios'
  let outDir = 'dist'
  let siteUrl = DEFAULT_SITE_URL

  return {
    name: 'kerugas-seo-prerender',
    configResolved(config) {
      outDir = path.resolve(config.root, config.build.outDir)
      siteUrl = (config.env.VITE_SITE_URL || process.env.VITE_SITE_URL || DEFAULT_SITE_URL).trim().replace(/\/+$/, '')
    },
    // `npm run preview` / `npm run share` (túnel): o mesmo comportamento do
    // nginx de produção,página pública pré-gerada se existir, senão a casca
    // _app.html (em vez do index.html com o texto da página inicial).
    configurePreviewServer(server) {
      if (isApp) return
      server.middlewares.use((req, _res, next) => {
        const url = (req.url ?? '/').split('?')[0]
        const wantsHtml = req.method === 'GET' && (req.headers.accept ?? '').includes('text/html')
        if (wantsHtml && url !== '/' && !url.startsWith('/api') && !path.extname(url)) {
          const clean = url.replace(/\/+$/, '')
          const pre = path.join(outDir, `${clean.slice(1)}.html`)
          if (fs.existsSync(pre)) req.url = `${clean}.html`
          else if (fs.existsSync(path.join(outDir, '_app.html'))) req.url = '/_app.html'
        }
        next()
      })
    },
    transformIndexHtml(html) {
      if (isApp) return html
      const verification = (process.env.VITE_GOOGLE_SITE_VERIFICATION ?? '').trim()
      const tags = [
        `<link rel="canonical" href="${siteUrl}/" />`,
        '<meta name="robots" content="index, follow, max-image-preview:large" />',
        `<meta property="og:site_name" content="${SITE_NAME}" />`,
        `<meta property="og:locale" content="${LOCALE}" />`,
        '<meta property="og:type" content="website" />',
        `<meta property="og:title" content="${escapeHtml(DEFAULT_TITLE)}" />`,
        `<meta property="og:description" content="${escapeHtml(DEFAULT_DESCRIPTION)}" />`,
        `<meta property="og:url" content="${siteUrl}/" />`,
        `<meta property="og:image" content="${siteUrl}${OG_IMAGE_PATH}" />`,
        '<meta property="og:image:width" content="1200" />',
        '<meta property="og:image:height" content="630" />',
        `<meta property="og:image:alt" content="${escapeHtml(OG_IMAGE_ALT)}" />`,
        '<meta name="twitter:card" content="summary_large_image" />',
        `<meta name="twitter:title" content="${escapeHtml(DEFAULT_TITLE)}" />`,
        `<meta name="twitter:description" content="${escapeHtml(DEFAULT_DESCRIPTION)}" />`,
        `<meta name="twitter:image" content="${siteUrl}${OG_IMAGE_PATH}" />`,
        verification ? `<meta name="google-site-verification" content="${escapeHtml(verification)}" />` : '',
        `<script type="application/ld+json" data-seo="site">${JSON.stringify(organizationJsonLd(siteUrl))}</script>`,
      ].filter(Boolean)
      return html.replace('  </head>', `    ${tags.join('\n    ')}\n  </head>`)
    },
    writeBundle() {
      if (isApp) return
      const indexPath = path.join(outDir, 'index.html')
      if (!fs.existsSync(indexPath)) return
      const shell = fs.readFileSync(indexPath, 'utf8')

      // A casca serve TODAS as outras rotas (postos, painéis...): sem canonical
      // nem og:url fixos,o <SeoManager> define-os no navegador para cada
      // página. Um canonical "/" aqui diria ao Google que todas eram a inicial.
      const appShell = shell
        .replace(/\s*<link rel="canonical"[^>]*\/?>/, '')
        .replace(/\s*<meta property="og:url"[^>]*\/?>/, '')
      fs.writeFileSync(path.join(outDir, '_app.html'), appShell)

      for (const page of PUBLIC_PAGES) {
        const file = page.path === '/' ? 'index.html' : `${page.path.slice(1)}.html`
        fs.writeFileSync(path.join(outDir, file), renderPage(shell, page, siteUrl))
      }

      const today = new Date().toISOString().slice(0, 10)
      fs.writeFileSync(path.join(outDir, 'sitemap-paginas.xml'), sitemapPages(siteUrl, today))
      fs.writeFileSync(path.join(outDir, 'sitemap.xml'), sitemapIndex(siteUrl, today))
      fs.writeFileSync(path.join(outDir, 'robots.txt'), robotsTxt(siteUrl))
      fs.writeFileSync(path.join(outDir, 'llms.txt'), renderLlmsTxt(siteUrl))
    },
  }
}

function replaceTag(html: string, pattern: RegExp, replacement: string): string {
  return pattern.test(html) ? html.replace(pattern, replacement) : html.replace('</head>', `  ${replacement}\n  </head>`)
}

export function renderPage(shell: string, page: PublicPage, siteUrl: string): string {
  const url = `${siteUrl}${page.path === '/' ? '/' : page.path}`
  const title = escapeHtml(page.title)
  const description = escapeHtml(page.description)
  let html = shell
  html = replaceTag(html, /<title>[\s\S]*?<\/title>/, `<title>${title}</title>`)
  html = replaceTag(html, /<meta name="description"[^>]*\/?>/, `<meta name="description" content="${description}" />`)
  html = replaceTag(html, /<link rel="canonical"[^>]*\/?>/, `<link rel="canonical" href="${url}" />`)
  html = replaceTag(html, /<meta property="og:title"[^>]*\/?>/, `<meta property="og:title" content="${title}" />`)
  html = replaceTag(html, /<meta property="og:description"[^>]*\/?>/, `<meta property="og:description" content="${description}" />`)
  html = replaceTag(html, /<meta property="og:url"[^>]*\/?>/, `<meta property="og:url" content="${url}" />`)
  html = replaceTag(html, /<meta name="twitter:title"[^>]*\/?>/, `<meta name="twitter:title" content="${title}" />`)
  html = replaceTag(html, /<meta name="twitter:description"[^>]*\/?>/, `<meta name="twitter:description" content="${description}" />`)
  if (page.faq?.length) {
    html = html.replace('</head>', `  <script type="application/ld+json" data-seo="page">${JSON.stringify(faqJsonLd(page.faq))}</script>\n  </head>`)
  }
  return html.replace('<div id="root"></div>', `<div id="root">${renderPageBody(page)}</div>`)
}

export function sitemapPages(siteUrl: string, today: string): string {
  const urls = PUBLIC_PAGES.map(
    (p) => `  <url><loc>${siteUrl}${p.path}</loc><lastmod>${today}</lastmod><changefreq>${p.changefreq}</changefreq><priority>${p.priority.toFixed(1)}</priority></url>`
  ).join('\n')
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>\n`
}

export function sitemapIndex(siteUrl: string, today: string): string {
  return `<?xml version="1.0" encoding="UTF-8"?>
<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <sitemap><loc>${siteUrl}/sitemap-paginas.xml</loc><lastmod>${today}</lastmod></sitemap>
  <sitemap><loc>${siteUrl}/sitemap-postos.xml</loc></sitemap>
</sitemapindex>
`
}

export function robotsTxt(siteUrl: string): string {
  const disallow = [...PRIVATE_PREFIXES.map((p) => p.replace(/\/$/, '')), '/api', '/_app.html']
    .map((p) => `Disallow: ${p}`)
    .join('\n')
  return `# KeruGás,robots.txt (gerado no build a partir de src/seo/pages.ts)
# Motores de pesquisa e assistentes de IA (GPTBot, ClaudeBot, PerplexityBot,
# Google-Extended...) são bem-vindos nas páginas públicas e nos postos.
User-agent: *
Allow: /
${disallow}

Sitemap: ${siteUrl}/sitemap.xml
`
}
