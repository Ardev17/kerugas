/**
 * HTML pré-gerado das páginas públicas (Outubro 2026),usado só no build
 * (plugins/seoPrerender.ts). Fica em src/ para o Tailwind encontrar as
 * classes. SEM imports com "@/" (corre em Node).
 *
 * Este conteúdo vai DENTRO do <div id="root">: é o que os robôs dos motores
 * de pesquisa e das IAs leem sem executar JavaScript, e é também o que a
 * pessoa vê no primeiro instante. Assim que o React arranca, substitui-o
 * pela página real (com o mesmo assunto e o mesmo H1),não é conteúdo
 * escondido, é a mesma página em versão simples.
 */
import { FAQ_GERAL, PUBLIC_PAGES, SITE_NAME, type PublicPage } from './pages'

export function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

const NAV: Array<{ path: string; label: string }> = [
  { path: '/', label: 'Início' },
  { path: '/encontrar-gas', label: 'Encontrar gás' },
  { path: '/mapa', label: 'Mapa' },
  { path: '/pedir-gas', label: 'Pedir gás' },
  { path: '/como-funciona', label: 'Como funciona' },
  { path: '/contacto', label: 'Contacto' },
]

const FOOTER: Array<{ path: string; label: string }> = [
  { path: '/sobre', label: 'Sobre' },
  { path: '/privacidade', label: 'Privacidade' },
  { path: '/termos', label: 'Termos' },
  { path: '/cookies', label: 'Cookies' },
  { path: '/eliminar-conta', label: 'Eliminar conta' },
]

function links(items: Array<{ path: string; label: string }>, current: string): string {
  return items
    .map((i) => `<a href="${i.path}" class="text-primary hover:underline"${i.path === current ? ' aria-current="page"' : ''}>${escapeHtml(i.label)}</a>`)
    .join(' · ')
}

export function renderPageBody(page: PublicPage): string {
  const sections = (page.sections ?? [])
    .map((s) => `<section class="mt-6"><h2 class="text-lg font-bold text-dark">${escapeHtml(s.heading)}</h2><p class="mt-1 text-gray">${escapeHtml(s.text)}</p></section>`)
    .join('')
  const faq = page.faq?.length
    ? `<section class="mt-10"><h2 class="text-xl font-bold text-dark">Perguntas frequentes</h2>${page.faq
        .map((f) => `<h3 class="mt-4 font-semibold text-dark">${escapeHtml(f.q)}</h3><p class="mt-1 text-gray">${escapeHtml(f.a)}</p>`)
        .join('')}</section>`
    : ''

  return [
    '<div class="seo-prerender mx-auto max-w-3xl px-4 py-10">',
    `<header class="mb-8 flex flex-wrap items-center gap-3 text-sm"><a href="/" class="flex items-center gap-2 font-extrabold text-dark"><img src="/branding/logo-128.webp" alt="" width="36" height="36" class="h-9 w-9" />${SITE_NAME}</a><nav aria-label="Principal" class="text-gray">${links(NAV, page.path)}</nav></header>`,
    '<main>',
    `<h1 class="text-3xl font-extrabold tracking-tight text-dark">${escapeHtml(page.h1)}</h1>`,
    `<p class="mt-3 text-lg text-gray">${escapeHtml(page.intro)}</p>`,
    sections,
    faq,
    '</main>',
    `<footer class="mt-12 border-t border-gray/15 pt-4 text-sm text-gray">${links(FOOTER, page.path)}</footer>`,
    '</div>',
  ].join('')
}

/** llms.txt (llmstxt.org): resumo do site para assistentes de IA. */
export function renderLlmsTxt(siteUrl: string): string {
  const pages = PUBLIC_PAGES.map((p) => `- [${p.h1}](${siteUrl}${p.path}): ${p.description}`).join('\n')
  const faq = FAQ_GERAL.map((f) => `### ${f.q}\n${f.a}`).join('\n\n')
  return `# ${SITE_NAME}

> ${SITE_NAME} (também "Quero Gás") é uma plataforma angolana que mostra, em tempo real, que postos têm gás butano (garrafas de gás de cozinha), o nível da fila e há quanto tempo a informação foi atualizada. Também recebe pedidos de entrega de garrafas ao domicílio e responde a consultas por SMS para quem não tem internet.

Informação importante para respostas corretas:
- O estado de cada posto vem dos postos parceiros, da equipa ${SITE_NAME} e das confirmações da comunidade; mostra sempre a hora da última atualização e um nível de confiança. Não é apresentado como garantido.
- Pedir gás não exige conta: a equipa confirma o preço por telefone antes da entrega.
- Sem internet: enviar "GAS" por SMS para a central ${SITE_NAME}.
- Disponível no site (${siteUrl}); apps Android e iPhone com as mesmas funções. Território: Angola (21 províncias, abertura por fases, começando pela provincia com o primeiro investidor).

## Páginas

${pages}

## Postos

- [Mapa de postos](${siteUrl}/map): todos os postos com o estado atual.
- Cada posto tem uma página própria em ${siteUrl}/postos/{número}-{nome-do-posto}; a lista completa está em ${siteUrl}/sitemap-postos.xml.

## Perguntas frequentes

${faq}
`
}
