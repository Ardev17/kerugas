/**
 * SEO e GEO (Outubro 2026),FONTE ÚNICA dos títulos, descrições e textos das
 * páginas públicas. Usada em dois sítios:
 *  1. no navegador, pelo <SeoManager> (título, descrição, canonical, og:*);
 *  2. no build (vite.config.ts → plugins/seoPrerender.ts), que gera um HTML
 *     já preenchido para cada página pública (os robôs das IAs e alguns
 *     motores de pesquisa não executam JavaScript), o sitemap, o robots.txt e
 *     o llms.txt.
 *
 * REGRAS: só caminhos relativos (sem "@/"), só dados (corre também em Node).
 * Ao mudar um texto aqui, muda em todo o lado. Nada de promessas falsas:
 * a informação dos postos é "ao vivo, com hora da última atualização",
 * nunca "garantida".
 */

export const SITE_NAME = 'KeruGás'
export const DEFAULT_SITE_URL = 'https://www.kerugas.ao'
export const OG_IMAGE_PATH = '/branding/og-image.jpg'
export const OG_IMAGE_ALT = 'KeruGás,disponibilidade de gás butano e filas nos postos, em tempo real'
export const LOCALE = 'pt_AO'
export const DEFAULT_TITLE = 'KeruGás,Onde há gás butano perto de si, em Angola'
export const DEFAULT_DESCRIPTION =
  'Veja em tempo real que postos têm gás butano, como está a fila e qual fica mais perto. Peça a entrega da garrafa ao domicílio. Angola.'

export interface SeoSection {
  heading: string
  text: string
}

export interface SeoFaq {
  q: string
  a: string
}

export interface PublicPage {
  /** Caminho exato da rota (React Router). */
  path: string
  /** <title>,até ~60 caracteres, com a palavra-chave principal à frente. */
  title: string
  /** meta description,até ~155 caracteres. */
  description: string
  /** O H1 do HTML pré-gerado (o mesmo assunto do H1 da página React). */
  h1: string
  intro: string
  sections?: SeoSection[]
  faq?: SeoFaq[]
  /** Prioridade e frequência no sitemap. */
  priority: number
  changefreq: 'always' | 'hourly' | 'daily' | 'weekly' | 'monthly' | 'yearly'
}

export const FAQ_GERAL: SeoFaq[] = [
  {
    q: 'Como sei que posto tem gás butano agora?',
    a: 'Abra o KeruGás em "Encontrar gás" ou no mapa: cada posto mostra se tem gás, o nível da fila, os tipos de garrafa e há quanto tempo a informação foi atualizada.',
  },
  {
    q: 'De onde vem a informação dos postos?',
    a: 'Dos próprios postos parceiros, da equipa KeruGás e das confirmações da comunidade. Cada estado mostra a hora da última atualização e um nível de confiança, que nunca é apresentado como garantido.',
  },
  {
    q: 'Posso pedir uma garrafa de gás em casa?',
    a: 'Sim. Em "Pedir gás" indica onde está e como o contactar; a equipa KeruGás confirma o preço consigo por telefone e um condutor entrega a garrafa. Não precisa de criar conta.',
  },
  {
    q: 'Funciona sem internet?',
    a: 'Sim, por SMS: envie "GAS" para o número da central KeruGás e a equipa responde com os postos com gás na sua zona.',
  },
  {
    q: 'O KeruGás é gratuito?',
    a: 'Consultar os postos no site e no app é gratuito. Nas entregas ao domicílio paga a garrafa e a taxa de entrega, confirmadas antes de o pedido seguir.',
  },
  {
    q: 'Em que províncias de Angola funciona?',
    a: 'A plataforma está preparada para as 21 províncias de Angola e vai abrindo por fases.',
  },
]

export const PUBLIC_PAGES: PublicPage[] = [
  {
    path: '/',
    title: DEFAULT_TITLE,
    description: DEFAULT_DESCRIPTION,
    h1: 'Encontre gás butano perto de si, sem andar de posto em posto',
    intro:
      'O KeruGás mostra em tempo real que postos de gás têm garrafas disponíveis, como está a fila e qual fica mais perto. Se preferir não sair de casa, peça a entrega da garrafa ao domicílio.',
    sections: [
      { heading: 'Disponibilidade de gás ao vivo', text: 'Cada posto mostra se tem gás, o nível da fila (baixa, média ou alta), os tipos de garrafa e há quanto tempo foi atualizado.' },
      { heading: 'Entrega de gás ao domicílio', text: 'Peça a garrafa sem criar conta: a equipa confirma o preço por telefone e um condutor KeruGás leva-a até si.' },
      { heading: 'Vizinhos KeruGás', text: 'A comunidade do seu município partilha avisos sobre os postos e confirma o que vê no local.' },
      { heading: 'Também por SMS', text: 'Sem internet? Envie "GAS" por SMS para a central e receba os postos com gás na sua zona.' },
    ],
    faq: FAQ_GERAL,
    priority: 1,
    changefreq: 'daily',
  },
  {
    path: '/encontrar-gas',
    title: 'Gás butano perto de mim,postos com gás agora | KeruGás',
    description: 'Lista dos postos com gás butano mais próximos, com o estado da fila e a hora da última atualização. Escolha a província e o município.',
    h1: 'Encontrar gás butano perto de si',
    intro: 'Veja agora que postos têm gás, como está a fila e qual fica mais perto. Use a sua localização ou escolha a província e o município.',
    priority: 0.9,
    changefreq: 'hourly',
  },
  {
    path: '/mapa',
    title: 'Mapa dos postos de gás em Angola, ao vivo | KeruGás',
    description: 'Mapa dos postos de gás butano: os que pulsam têm gás agora. Toque num posto para ver a fila, as garrafas disponíveis e traçar a rota.',
    h1: 'Mapa dos postos de gás',
    intro: 'Os postos que pulsam no mapa têm gás agora. Toque num posto para ver o estado, a fila e abrir a rota no Google Maps.',
    priority: 0.8,
    changefreq: 'hourly',
  },
  {
    path: '/pedir-gas',
    title: 'Pedir gás ao domicílio,entrega de garrafa de gás | KeruGás',
    description: 'Peça uma garrafa de gás butano em casa, sem criar conta. A equipa KeruGás confirma o preço por telefone e um condutor faz a entrega.',
    h1: 'Pedir gás ao domicílio',
    intro: 'Indique onde está e como o podemos contactar. A equipa KeruGás confirma consigo o preço e os tipos de garrafa e um condutor entrega o gás.',
    sections: [
      { heading: 'Como funciona a entrega', text: '1) Envia o pedido. 2) A equipa liga-lhe para confirmar o preço. 3) Um condutor do seu município leva a garrafa. 4) Acompanha o pedido pela referência e avalia a entrega.' },
    ],
    priority: 0.9,
    changefreq: 'weekly',
  },
  {
    path: '/como-funciona',
    title: 'Como funciona o KeruGás,gás butano em tempo real e entrega',
    description: 'Partilhe a localização, veja que postos têm gás e a fila, siga a rota ou peça a entrega. Saiba de onde vem a informação dos postos.',
    h1: 'Como funciona o KeruGás',
    intro: 'Em quatro passos: partilhe a localização (ou escolha a zona), veja a disponibilidade, siga para o posto ou peça a entrega ao domicílio.',
    sections: [
      { heading: '1. Partilhe a sua localização', text: 'Ou selecione manualmente a sua província e município,a escolha é sempre sua.' },
      { heading: '2. Veja a disponibilidade', text: 'Cada posto mostra se tem gás, o nível da fila, os tipos de garrafa disponíveis e há quanto tempo foi atualizado.' },
      { heading: '3. Trace a rota', text: 'Escolha o posto mais conveniente e abra a navegação diretamente no Google Maps.' },
      { heading: '4. Ou peça para receber', text: 'Sem gás perto de si, ou prefere não sair de casa? Peça a entrega,a nossa equipa trata do resto.' },
    ],
    faq: FAQ_GERAL,
    priority: 0.7,
    changefreq: 'monthly',
  },
  {
    path: '/sobre',
    title: 'Sobre o KeruGás,plataforma angolana de gás butano',
    description: 'O KeruGás liga clientes e postos de gás em Angola: informação ao vivo sobre gás e filas, entrega ao domicílio e atendimento por SMS.',
    h1: 'Sobre o KeruGás',
    intro: 'O KeruGás nasceu para acabar com as voltas de posto em posto à procura de gás butano. Junta num só sítio a informação dos postos, da equipa e da comunidade, e trata da entrega quando é preciso.',
    priority: 0.5,
    changefreq: 'monthly',
  },
  {
    path: '/contacto',
    title: 'Contactar o KeruGás,central, SMS e formulário',
    description: 'Fale com a central KeruGás por telefone, consulte postos por SMS (envie "GAS") ou escreva-nos pelo formulário de contacto.',
    h1: 'Contactar o KeruGás',
    intro: 'Ligue para a central, envie "GAS" por SMS para consultar postos ou deixe-nos uma mensagem pelo formulário,respondemos o mais breve possível.',
    priority: 0.5,
    changefreq: 'monthly',
  },
  {
    path: '/privacidade',
    title: 'Política de Privacidade | KeruGás',
    description: 'Que dados pessoais o KeruGás recolhe, para quê, durante quanto tempo e como pode pedir acesso, correção ou eliminação.',
    h1: 'Política de Privacidade',
    intro: 'Como o KeruGás trata os seus dados pessoais: o que recolhemos, porquê, com quem partilhamos e quais são os seus direitos.',
    priority: 0.3,
    changefreq: 'yearly',
  },
  {
    path: '/termos',
    title: 'Termos e Condições | KeruGás',
    description: 'As regras de utilização do KeruGás: consulta de postos, pedidos de entrega, comunidade Vizinhos e responsabilidades.',
    h1: 'Termos e Condições',
    intro: 'As condições de utilização do site e do app KeruGás.',
    priority: 0.3,
    changefreq: 'yearly',
  },
  {
    path: '/cookies',
    title: 'Política de Cookies | KeruGás',
    description: 'Que cookies e armazenamento local o KeruGás usa no site e porquê.',
    h1: 'Política de Cookies',
    intro: 'O que o KeruGás guarda no seu navegador e para que serve.',
    priority: 0.2,
    changefreq: 'yearly',
  },
  {
    path: '/eliminar-conta',
    title: 'Eliminar a conta KeruGás,como pedir',
    description: 'Como eliminar a sua conta KeruGás e os seus dados, pelo app, pelo site ou por pedido à equipa.',
    h1: 'Eliminar a conta KeruGás',
    intro: 'Pode pedir a eliminação da sua conta e dos dados associados a qualquer momento. Explicamos aqui como e o que acontece aos dados.',
    priority: 0.2,
    changefreq: 'yearly',
  },
]

/**
 * Páginas que NÃO devem aparecer nos motores de pesquisa (painéis, conta,
 * pedidos de cada pessoa). Recebem `noindex` e ficam bloqueadas no robots.txt.
 */
export const PRIVATE_PREFIXES = [
  '/admin', '/driver', '/operator', '/posto', '/investidor', '/perfil', '/pedidos', '/pedido/',
  '/order/success', '/avaliar', '/conversa-central', '/comunidade', '/dashboard',
  '/favorites', '/alerts', '/notificacoes', '/menu', '/login', '/register',
  '/forgot-password', '/reset-password',
]

export function isPrivatePath(pathname: string): boolean {
  return PRIVATE_PREFIXES.some((p) => (p.endsWith('/') ? pathname.startsWith(p) : pathname === p || pathname.startsWith(`${p}/`)))
}

export function findPublicPage(pathname: string): PublicPage | undefined {
  const clean = pathname !== '/' ? pathname.replace(/\/+$/, '') : '/'
  return PUBLIC_PAGES.find((p) => p.path === clean)
}

/** Dados estruturados (schema.org) da organização e do site,iguais em todas as páginas. */
export function organizationJsonLd(siteUrl: string) {
  return {
    '@context': 'https://schema.org',
    '@graph': [
      {
        '@type': 'Organization',
        '@id': `${siteUrl}/#organization`,
        name: SITE_NAME,
        alternateName: 'Quero Gás',
        url: `${siteUrl}/`,
        logo: `${siteUrl}/branding/icon-512.png`,
        description: DEFAULT_DESCRIPTION,
        areaServed: { '@type': 'Country', name: 'Angola' },
      },
      {
        '@type': 'WebSite',
        '@id': `${siteUrl}/#website`,
        name: SITE_NAME,
        url: `${siteUrl}/`,
        inLanguage: 'pt-AO',
        publisher: { '@id': `${siteUrl}/#organization` },
      },
    ],
  }
}

export function faqJsonLd(faq: SeoFaq[]) {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faq.map((f) => ({ '@type': 'Question', name: f.q, acceptedAnswer: { '@type': 'Answer', text: f.a } })),
  }
}
