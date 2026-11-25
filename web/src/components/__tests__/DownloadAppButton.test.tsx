import { describe, expect, it, vi, beforeEach } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'

const off = { published: false, storeUrl: null as string | null }
const state = vi.hoisted(() => ({ native: false, stores: { android: { published: false, storeUrl: null as string | null }, ios: { published: false, storeUrl: null as string | null } } }))
vi.mock('@/native/platform', () => ({
  get isNativeApp() {
    return state.native
  },
}))
vi.mock('@/hooks/useAppStores', async (orig) => ({
  ...(await orig<typeof import('@/hooks/useAppStores')>()),
  useAppStores: () => state.stores,
}))

import { DownloadAppButton, StoreBadges } from '../DownloadAppButton'

const PLAY = 'https://play.google.com/store/apps/details?id=ao.kerugas.app'
const APPLE = 'https://apps.apple.com/ao/app/kerugas/id1'

describe('DownloadAppButton e StoreBadges', () => {
  beforeEach(() => {
    state.native = false
    state.stores = { android: { ...off }, ios: { ...off } }
  })

  it('antes das lojas: cabeçalho e menu não mostram nada', () => {
    const { container } = render(<><DownloadAppButton /><DownloadAppButton variant="row" /></>)
    expect(container).toBeEmptyDOMElement()
  })

  it('antes das lojas: o rodapé mostra "Brevemente" no Google Play E na App Store, sem links', () => {
    render(<StoreBadges />)
    expect(screen.getByRole('note', { name: /brevemente no google play/i })).toBeInTheDocument()
    expect(screen.getByRole('note', { name: /brevemente na app store/i })).toBeInTheDocument()
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('cada loja liga-se sozinha: Google Play ativo, App Store ainda "Brevemente"', () => {
    state.stores = { android: { published: true, storeUrl: PLAY }, ios: { ...off } }
    render(<StoreBadges />)
    expect(screen.getByRole('link', { name: /google play/i })).toHaveAttribute('href', PLAY)
    expect(screen.getByRole('note', { name: /app store/i })).toBeInTheDocument()
  })

  it('com as duas publicadas: dois links seguros', () => {
    state.stores = { android: { published: true, storeUrl: PLAY }, ios: { published: true, storeUrl: APPLE } }
    render(<StoreBadges />)
    const links = screen.getAllByRole('link')
    expect(links.map((l) => l.getAttribute('href'))).toEqual([PLAY, APPLE])
    links.forEach((l) => expect(l).toHaveAttribute('rel', expect.stringContaining('noopener')))
  })

  it('no computador, o botão do cabeçalho leva ao Google Play', () => {
    state.stores = { android: { published: true, storeUrl: PLAY }, ios: { published: true, storeUrl: APPLE } }
    render(<DownloadAppButton />)
    expect(screen.getByRole('link')).toHaveAttribute('href', PLAY)
  })

  it('dentro da própria app nada aparece', () => {
    state.native = true
    state.stores = { android: { published: true, storeUrl: PLAY }, ios: { published: true, storeUrl: APPLE } }
    const { container } = render(<><DownloadAppButton /><StoreBadges /></>)
    expect(container).toBeEmptyDOMElement()
  })

  it('publicada: Google Play usa o selo "DISPONÍVEL NO"; App Store o selo "Disponível na" com o ícone', () => {
    state.stores = { android: { published: true, storeUrl: PLAY }, ios: { published: true, storeUrl: APPLE } }
    render(<StoreBadges />)
    expect(screen.getByRole('img', { name: /disponível no google play/i })).toHaveAttribute('src', '/branding/badges/google-play.webp')
    expect(screen.getByRole('link', { name: /app store/i })).toHaveTextContent('Disponível naApp Store')
  })

  it('se o selo do Google Play faltar, desenha um no mesmo estilo', () => {
    state.stores = { android: { published: true, storeUrl: PLAY }, ios: { ...off } }
    render(<StoreBadges />)
    fireEvent.error(screen.getByRole('img', { name: /disponível no google play/i }))
    expect(screen.getByRole('link', { name: /google play/i })).toHaveTextContent('Disponível noGoogle Play')
  })
})

describe('versão de teste (APK)', () => {
  const APK = 'https://github.com/ardev/kerugas-teste/releases/latest/download/kerugas.apk'
  beforeEach(() => {
    state.native = false
    state.stores = { android: { published: false, storeUrl: null, apkUrl: APK, apkVersion: '1.0.3-teste' } as typeof state.stores.android, ios: { ...off } }
  })

  it('o rodapé mostra "Versão de teste" com o link do APK,nunca "Disponível no Google Play"', () => {
    render(<StoreBadges />)
    const link = screen.getByRole('link', { name: /versão de teste/i })
    expect(link).toHaveAttribute('href', APK)
    expect(link).toHaveTextContent('Android (APK)')
    expect(screen.queryByRole('img', { name: /disponível no google play/i })).toBeNull()
    expect(screen.getByRole('note', { name: /brevemente na app store/i })).toBeInTheDocument()
  })

  it('dentro da própria app não aparece', () => {
    state.native = true
    const { container } = render(<StoreBadges />)
    expect(container).toBeEmptyDOMElement()
  })
})
