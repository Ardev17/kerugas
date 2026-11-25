import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

/**
 * Rede de segurança do deploy (Outubro 2026): a localização e a rota
 * dependem de configuração FORA do código React,cabeçalhos do nginx e
 * permissões do Android. Se alguém as mudar sem querer, estes testes
 * falham antes de ir para produção.
 */
const root = resolve(__dirname, '../../../..')
const nginx = readFileSync(resolve(root, 'infrastructure/nginx/nginx.conf'), 'utf8')
const manifest = readFileSync(resolve(__dirname, '../../android/app/src/main/AndroidManifest.xml'), 'utf8')

/** Blocos `server { ... }` de topo do nginx.conf. */
function serverBlocks(conf: string): string[] {
  const blocks: string[] = []
  let depth = 0
  let start = -1
  for (let i = 0; i < conf.length; i++) {
    if (conf.startsWith('server {', i) && depth === 0) start = i
    if (conf[i] === '{') depth++
    if (conf[i] === '}') {
      depth--
      if (depth === 0 && start >= 0) {
        blocks.push(conf.slice(start, i + 1))
        start = -1
      }
    }
  }
  return blocks
}

const header = (block: string, name: string) =>
  block.match(new RegExp(`add_header\\s+${name}\\s+"([^"]*)"`, 'i'))?.[1]

// Os blocos que servem a aplicação (os que têm `location /`com proxy ao frontend).
const appBlocks = serverBlocks(nginx).filter((b) => /proxy_pass\s+http:\/\/frontend/.test(b))

describe('nginx,cabeçalhos que a localização e o mapa exigem', () => {
  it('encontra os blocos que servem a app (HTTP local e HTTPS de produção)', () => {
    expect(appBlocks.length).toBeGreaterThanOrEqual(2)
  })

  it.each(appBlocks.map((b, i) => [i, b] as const))('bloco %i: Permissions-Policy permite geolocalização à própria origem', (_i, block) => {
    const policy = header(block, 'Permissions-Policy')
    expect(policy).toBeDefined()
    const geo = policy!.match(/geolocation=(\([^)]*\)|\*)/)?.[1]
    expect(geo).toBeDefined()
    expect(geo).toContain('self')
    expect(geo).not.toBe('()') // geolocation=() bloquearia o botão em todo o site
  })

  it.each(appBlocks.map((b, i) => [i, b] as const))('bloco %i: a CSP deixa carregar mapas (tiles https), estilos do Leaflet e a API na mesma origem', (_i, block) => {
    const csp = header(block, 'Content-Security-Policy')!
    expect(csp).toBeDefined()
    const directive = (name: string) => csp.split(';').map((d) => d.trim()).find((d) => d.startsWith(name + ' '))

    expect(directive('img-src')).toMatch(/https:/) // tile.openstreetmap.org
    expect(directive('img-src')).toMatch(/blob:/) // pré-visualização do recorte da foto de perfil
    expect(directive('style-src')).toMatch(/'unsafe-inline'/) // Leaflet posiciona com estilos inline
    expect(directive('connect-src')).toMatch(/'self'/) // /api/ passa pelo nginx, mesma origem
    expect(directive('script-src')).not.toMatch(/'unsafe-eval'/)
  })

  it.each(appBlocks.map((b, i) => [i, b] as const))('bloco %i: a CSP fecha <object>, <base href> e o envio de formulários para fora', (_i, block) => {
    const csp = header(block, 'Content-Security-Policy')!
    const directive = (name: string) => csp.split(';').map((d) => d.trim()).find((d) => d.startsWith(name + ' '))

    expect(directive('object-src')).toBe("object-src 'none'")
    expect(directive('base-uri')).toBe("base-uri 'self'")
    expect(directive('form-action')).toBe("form-action 'self'")
    expect(directive('frame-ancestors')).toBe("frame-ancestors 'self'")
    expect(directive('script-src')).not.toMatch(/'unsafe-inline'|https:|\*/) // nenhum script de fora
    // câmara/microfone desligados no site (a câmara é a do app Android)
    expect(header(block, 'Permissions-Policy')).toMatch(/camera=\(\)/)
    expect(header(block, 'Cross-Origin-Opener-Policy')).toBe('same-origin')
  })

  it('em produção o tempo real (wss) é permitido explicitamente e o http:// é promovido', () => {
    const https = serverBlocks(nginx).find((b) => /listen 443/.test(b))!
    const csp = header(https, 'Content-Security-Policy')!
    expect(csp).toMatch(/connect-src [^;]*wss:\/\/www\.kerugas\.ao/)
    expect(csp).toMatch(/upgrade-insecure-requests/)
  })

  it('o build de produção chama a API na mesma origem (senão a CSP connect-src a bloqueia)', () => {
    const dockerfile = readFileSync(resolve(root, 'infrastructure/docker/Dockerfile.frontend'), 'utf8')
    const apiUrl = dockerfile.match(/^ARG VITE_API_URL=(\S+)/m)?.[1]
    expect(apiUrl).toBeDefined()
    expect(apiUrl!.startsWith('/')).toBe(true) // relativo = mesma origem
  })

  it('o nginx não anuncia a sua versão', () => {
    expect(nginx).toMatch(/^server_tokens off;/m)
  })

  it('o domínio de produção só serve a app por HTTPS (a localização exige contexto seguro)', () => {
    const production = serverBlocks(nginx).filter((b) => /server_name\s+www\.kerugas\.ao/.test(b))
    const [http, https] = [production.find((b) => /listen 80;/.test(b)), production.find((b) => /listen 443/.test(b))]

    expect(https).toBeDefined()
    expect(http).toMatch(/return 301 https:\/\/\$host\$request_uri/)
    expect(http).not.toMatch(/proxy_pass/) // nunca serve conteúdo sem TLS
  })

  it('o HTTPS de produção força TLS moderno e HSTS', () => {
    const https = serverBlocks(nginx).find((b) => /listen 443/.test(b))!
    expect(https).toMatch(/ssl_protocols\s+TLSv1\.2 TLSv1\.3/)
    expect(https).toMatch(/Strict-Transport-Security/)
  })
})

describe('Android,permissões da localização', () => {
  it('declara localização precisa e aproximada', () => {
    expect(manifest).toContain('android.permission.ACCESS_FINE_LOCATION')
    expect(manifest).toContain('android.permission.ACCESS_COARSE_LOCATION')
  })

  it('não pede localização em segundo plano (a Play Store exige justificação e a app não precisa)', () => {
    expect(manifest).not.toContain('ACCESS_BACKGROUND_LOCATION')
  })

  it('declara acesso à internet (o mapa e a API)', () => {
    expect(manifest).toContain('android.permission.INTERNET')
  })
})

describe('iPhone,o que a Apple exige para aprovar o app', () => {
  const plist = readFileSync(resolve(__dirname, '../../ios/App/App/Info.plist'), 'utf8')
  const entitlements = readFileSync(resolve(__dirname, '../../ios/App/App/App.entitlements'), 'utf8')
  const pbx = readFileSync(resolve(__dirname, '../../ios/App/App.xcodeproj/project.pbxproj'), 'utf8')
  const aasa = JSON.parse(readFileSync(resolve(root, 'infrastructure/nginx/apple-app-site-association'), 'utf8'))

  /** Texto da frase de uma permissão no Info.plist. */
  const usage = (key: string) => plist.match(new RegExp(`<key>${key}</key>\\s*<string>([^<]+)</string>`))?.[1] ?? ''

  it.each(['NSLocationWhenInUseUsageDescription', 'NSCameraUsageDescription', 'NSPhotoLibraryUsageDescription'])(
    'tem a frase de %s, em português e a dizer para que serve',
    (key) => {
      expect(usage(key).length).toBeGreaterThan(30)
      expect(usage(key)).toContain('KeruGás')
    }
  )

  it('não pede o microfone (o app não o usa)', () => {
    expect(plist).not.toContain('NSMicrophoneUsageDescription')
  })

  // Novembro 2026: os condutores partilham a localização com a app fechada.
  it('explica a localização em segundo plano dos condutores e tem o modo "location"', () => {
    expect(plist).toMatch(/<key>NSLocationAlwaysAndWhenInUseUsageDescription<\/key>\s*<string>Só para condutores/)
    expect(plist).toMatch(/<key>UIBackgroundModes<\/key>\s*<array>[\s\S]*<string>location<\/string>[\s\S]*<\/array>/)
  })

  it('recebe push com o app fechado e não pergunta pela criptografia a cada build', () => {
    expect(plist).toMatch(/<key>UIBackgroundModes<\/key>\s*<array>\s*<string>remote-notification<\/string>/)
    expect(plist).toMatch(/<key>ITSAppUsesNonExemptEncryption<\/key>\s*<false\/>/)
  })

  it('usa o mesmo identificador do Android e o entitlements está ligado ao projeto', () => {
    expect(pbx).toContain('PRODUCT_BUNDLE_IDENTIFIER = ao.kerugas.app;')
    expect(pbx.match(/CODE_SIGN_ENTITLEMENTS = App\/App\.entitlements;/g)).toHaveLength(2)
    expect(entitlements).toContain('aps-environment')
  })

  it('Universal Links: o app e o servidor falam dos mesmos domínios e caminhos', () => {
    expect(entitlements).toContain('applinks:www.kerugas.ao')
    const paths = aasa.applinks.details[0].components.map((c: { '/': string }) => c['/'])
    expect(paths).toEqual(expect.arrayContaining(['/avaliar/*', '/pedido/*', '/station/*', '/postos/*']))
    expect(nginx).toContain('location = /.well-known/apple-app-site-association')
  })
})
