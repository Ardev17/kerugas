/**
 * Teste ponta a ponta (Chromium real) da localização e do "Traçar rota".
 * Outubro 2026.
 *
 * Constrói o site, serve-o com `vite preview` e controla o navegador com o
 * Playwright, com a API simulada (nenhum backend necessário) e o GPS
 * simulado (permissão concedida, recusada, tempo esgotado + plano B).
 *
 * Como correr (na pasta apps/web):
 *   npm i -D playwright            # uma vez (e `npx playwright install chromium`)
 *   node e2e/geolocation.e2e.mjs
 *
 * Termina com código 1 se algum cenário falhar,pode ir para o CI.
 */
import { execSync, spawn } from 'node:child_process'
import { createRequire } from 'node:module'
import { mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const require = createRequire(import.meta.url)
let playwright
try {
  playwright = require('playwright')
} catch {
  // Instalação global (ex.: ambientes de CI com o Playwright já presente).
  const globalRoot = execSync('npm root -g').toString().trim()
  playwright = require(join(globalRoot, 'playwright'))
}
const { chromium, devices } = playwright

const PORT = 4179
const BASE = `http://localhost:${PORT}`
const outDir = mkdtempSync(join(tmpdir(), 'kerugas-e2e-'))
const LUBANGO = { latitude: -14.9177, longitude: 13.4925 }

/* ----------------------------- API simulada ----------------------------- */
const station = (over = {}) => ({
  id: 1, name: '[DEMO] Posto Central Lubango', slug: 'central', province: 'Huíla', municipality: 'Lubango',
  address: 'Rua Direita', reference_point: null, latitude: -14.9177, longitude: 13.4925, phone: null,
  opening_hours: null, photo_url: null, status: 'ACTIVE', gas_available: true, queue_level: 'HIGH',
  cylinder_types: 'P13', confidence_score: 80, freshness: 'STALE',
  freshness_label: 'Atenção,desatualizando', // o ecrã NÃO deve mostrar este texto
  status_updated_at: new Date(Date.now() - (5 * 60 + 9) * 60_000).toISOString(), // há 5 h 9 min
  distance_km: 1.2, ...over,
})
const ok = (data, extra = {}) => ({ success: true, data, ...extra })

async function mockApi(context, log) {
  await context.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url())
    const path = url.pathname.replace('/api/v1', '')
    log.push({ path, params: Object.fromEntries(url.searchParams), body: route.request().postData(), method: route.request().method() })
    const json = (body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })

    if (path === '/provinces') return json(ok([{ id: 1, name: 'Huíla' }, { id: 2, name: 'Luanda' }]))
    if (path === '/provinces/1/municipalities') return json(ok([{ id: 10, name: 'Lubango', province_id: 1 }, { id: 11, name: 'Chibia', province_id: 1 }]))
    if (path === '/provinces/2/municipalities') return json(ok([{ id: 20, name: 'Talatona', province_id: 2 }]))
    if (path === '/territory/locate') return json(ok({ province_id: 1, municipality_id: 10, source: 'geocoder' }))
    if (path === '/stations/nearby') {
      return json(ok([station(), station({ id: 2, name: '[DEMO] Posto Norte', latitude: -14.8, longitude: 13.5, queue_level: 'LOW' })], { meta: { count: 2 } }))
    }
    if (path === '/map/stations') {
      return json(ok([station({ latitude: -14.9177, longitude: 13.4925, queue_level: 'HIGH' }), station({ id: 3, name: '[DEMO] Sem gás', gas_available: false, latitude: -8.84, longitude: 13.29 /* Luanda: longe, para os pontos não se sobreporem no zoom do país */ })]))
    }
    if (path === '/stations/1') return json(ok(station()))
    if (path === '/orders' && route.request().method() === 'POST') return json(ok({ id: 7, reference: 'KG-20261002-000007', status: 'PENDING', status_label: 'Recebido' }), 201)
    return json(ok([]))
  })
  // Mosaicos do mapa e Google Maps: sem rede externa nos testes.
  const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAHnOcQAAAAABJRU5ErkJggg==', 'base64')
  await context.route('**/*.tile.openstreetmap.org/**', (r) => r.fulfill({ status: 200, contentType: 'image/png', body: png }))
  await context.route('https://www.google.com/**', (r) => r.fulfill({ status: 200, contentType: 'text/html', body: '<title>Google Maps (simulado)</title>' }))
}

/* ------------------------------- cenários -------------------------------- */
const results = []
async function scenario(name, fn) {
  try {
    await fn()
    results.push({ name, pass: true })
    console.log(`  ✓ ${name}`)
  } catch (e) {
    results.push({ name, pass: false, error: e })
    const shot = join(tmpdir(), `e2e-falha-${results.length}.png`)
    await lastPage?.screenshot({ path: shot }).catch(() => {})
    console.log(`  ✗ ${name}\n      ${String(e.message).split('\n').slice(0,12).join('\n      ')}\n      captura: ${shot}`)
  }
}
const assert = (cond, msg) => { if (!cond) throw new Error(msg) }
const lastNearby = (log) => log.filter((r) => r.path === '/stations/nearby').at(-1)

let lastPage = null
async function newPage(browser, { geolocation, permissions, device } = {}) {
  const context = await browser.newContext({ ...(device ?? {}), ...(geolocation ? { geolocation, permissions: ['geolocation'] } : { permissions: permissions ?? [] }), locale: 'pt-AO' })
  const log = []
  await mockApi(context, log)
  const page = await context.newPage()
  lastPage = page
  const errors = []
  page.on('pageerror', (e) => errors.push(e.message))
  return { context, page, log, errors }
}

async function main() {
  console.log('A construir o site…')
  execSync(`npx vite build --outDir ${outDir}`, { stdio: 'ignore', env: { ...process.env, VITE_API_URL: '/api/v1' } })
  const server = spawn('npx', ['vite', 'preview', '--outDir', outDir, '--port', String(PORT), '--strictPort'], { stdio: 'ignore', shell: process.platform === 'win32' })
  for (let i = 0; i < 60; i++) {
    try { if ((await fetch(BASE)).ok) break } catch { /* ainda a arrancar */ }
    await new Promise((r) => setTimeout(r, 500))
  }

  const browser = await chromium.launch()
  console.log('\nEncontrar gás')

  await scenario('mostra província e município logo ao entrar, antes de pedir a localização', async () => {
    const { page, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/find`)
    await page.getByLabel('Província').waitFor()
    assert(await page.getByLabel('Município').isVisible(), 'município não visível')
    assert(await page.getByRole('button', { name: /usar minha localização/i }).isVisible(), 'botão em falta')
    await context.close()
  })

  await scenario('GPS concedido: pede /stations/nearby com as coordenadas e mostra os postos', async () => {
    const { page, log, context, errors } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/find`)
    await page.getByRole('button', { name: /usar minha localização/i }).click()
    await page.getByText('[DEMO] Posto Norte').waitFor()
    const req = lastNearby(log)
    assert(Math.abs(Number(req.params.latitude) - LUBANGO.latitude) < 1e-6, `latitude enviada: ${req.params.latitude}`)
    assert(Math.abs(Number(req.params.longitude) - LUBANGO.longitude) < 1e-6, `longitude enviada: ${req.params.longitude}`)
    assert(!('municipality_id' in req.params), 'não devia enviar município com GPS')
    assert(errors.length === 0, `erros na página: ${errors.join('; ')}`)
    await context.close()
  })

  await scenario('mostra o tempo exato e nunca "Muito recente" / "desatualizada"', async () => {
    const { page, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/find`)
    await page.getByRole('button', { name: /usar minha localização/i }).click()
    await page.getByText('[DEMO] Posto Norte').waitFor()
    const body = await page.locator('body').innerText()
    assert(/Última atualização há 5 horas e (9|10) minutos/.test(body), `texto de tempo não encontrado:\n${body.slice(0, 400)}`)
    assert(!/muito recente|desatualiz/i.test(body), 'ainda mostra rótulos antigos')
    await context.close()
  })

  await scenario('"Traçar rota" abre o Google Maps com o destino certo, num separador seguro', async () => {
    const { page, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/find`)
    await page.getByRole('button', { name: /usar minha localização/i }).click()
    await page.getByText('[DEMO] Posto Norte').waitFor()
    const links = page.getByRole('link', { name: /traçar rota/i })
    assert((await links.count()) === 2, 'esperava 2 botões de rota')
    assert((await links.first().getAttribute('href')) === 'https://www.google.com/maps/dir/?api=1&destination=-14.9177,13.4925', 'href errado')
    const [popup] = await Promise.all([context.waitForEvent('page'), links.first().click()])
    await popup.waitForLoadState()
    assert(popup.url().startsWith('https://www.google.com/maps/dir/?api=1&destination=-14.9177,13.4925'), `abriu ${popup.url()}`)
    assert((await popup.evaluate(() => window.opener)) === null, 'o novo separador tem acesso à página de origem (falta noopener)')
    assert(page.url().includes('/find'), 'a página original perdeu o sítio')
    await context.close()
  })

  await scenario('GPS recusado: explica em português e a zona manual funciona', async () => {
    const { page, log, context } = await newPage(browser) // sem permissão de localização
    await page.goto(`${BASE}/find`)
    await page.getByRole('button', { name: /usar minha localização/i }).click()
    const alert = page.getByRole('alert')
    await alert.waitFor()
    const text = await alert.innerText()
    assert(/bloqueada/i.test(text) && !/denied/i.test(text), `mensagem inesperada: ${text}`)
    assert(!lastNearby(log), 'não devia procurar postos sem localização')
    await page.getByLabel('Província').selectOption('1')
    await page.getByLabel('Município').selectOption('10')
    await page.getByText('[DEMO] Posto Norte').waitFor()
    assert(lastNearby(log).params.municipality_id === '10', 'devia procurar por município')
    await context.close()
  })

  await scenario('sem sinal de GPS a tempo: usa a posição aproximada (plano B)', async () => {
    const { page, context } = await newPage(browser)
    await context.addInitScript(() => {
      let calls = 0
      window.__geoOptions = []
      Object.defineProperty(navigator, 'geolocation', {
        configurable: true,
        value: {
          getCurrentPosition(ok, fail, options) {
            window.__geoOptions.push(options)
            calls += 1
            if (calls === 1) setTimeout(() => fail({ code: 3, message: 'Timeout expired' }), 10)
            else setTimeout(() => ok({ coords: { latitude: -14.92, longitude: 13.49, accuracy: 1800 } }), 10)
          },
        },
      })
    })
    await page.goto(`${BASE}/find`)
    await page.getByRole('button', { name: /usar minha localização/i }).click()
    await page.getByText('[DEMO] Posto Norte').waitFor()
    const options = await page.evaluate(() => window.__geoOptions)
    assert(options.length === 2, `esperava 2 tentativas, houve ${options.length}`)
    assert(options[0].enableHighAccuracy === true && options[1].enableHighAccuracy === false, 'a 2.ª tentativa devia ser aproximada')
    await context.close()
  })

  await scenario('em telemóvel (Pixel 7): sem scroll horizontal e botões de rota visíveis', async () => {
    const { page, context } = await newPage(browser, { geolocation: LUBANGO, device: devices['Pixel 7'] })
    await page.goto(`${BASE}/find`)
    await page.getByRole('button', { name: /usar minha localização/i }).click()
    await page.getByText('[DEMO] Posto Norte').waitFor()
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)
    assert(overflow <= 1, `scroll horizontal de ${overflow}px`)
    assert(await page.getByRole('link', { name: /traçar rota/i }).first().isVisible(), 'rota não visível')
    await context.close()
  })

  console.log('\nOutros ecrãs')

  await scenario('detalhes do posto: "Traçar rota" com o destino certo', async () => {
    const { page, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/station/1`)
    const link = page.getByRole('link', { name: /traçar rota/i })
    await link.waitFor()
    assert((await link.getAttribute('href')) === 'https://www.google.com/maps/dir/?api=1&destination=-14.9177,13.4925', 'href errado')
    assert((await page.locator('body').innerText()).includes('Última atualização há 5 horas'), 'falta o tempo da última atualização')
    await context.close()
  })

  await scenario('mapa: o popup diz o mesmo que a cor (gás + fila alta) e o tempo exato', async () => {
    const { page, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/map`)
    const markers = page.locator('.leaflet-interactive')
    await markers.first().waitFor()
    // Abre o popup de cada ponto e guarda o texto (a ordem dos pontos no SVG não é garantida).
    const popups = []
    for (let i = 0; i < (await markers.count()); i++) {
      await markers.nth(i).click({ force: true })
      const content = page.locator('.leaflet-popup-content')
      await content.first().waitFor()
      popups.push(await content.last().innerText())
      await page.locator('.leaflet-popup-close-button').last().click()
      await page.waitForFunction(() => document.querySelectorAll('.leaflet-popup').length === 0)
    }
    const withGas = popups.find((t) => t.includes('Posto Central') || t.includes('[DEMO] Posto'))
    assert(withGas && /Gás disponível · fila alta/.test(withGas), `popup do posto com gás: ${JSON.stringify(popups)}`)
    assert(/Última atualização há 5 horas/.test(withGas), `popup sem tempo exato: ${withGas}`)
    const noGas = popups.find((t) => t.includes('Sem gás'))
    assert(noGas && /Gás indisponível/.test(noGas), `popup do posto sem gás: ${JSON.stringify(popups)}`)
    const legend = await page.locator('body').innerText()
    for (const label of ['fila baixa', 'fila média', 'fila alta', 'fila desconhecida', 'Gás indisponível']) {
      assert(legend.includes(label), `legenda sem "${label}"`)
    }
    await context.close()
  })

  await scenario('criar conta: "obter localização" preenche província e município, e o cliente pode alterá-los', async () => {
    const { page, log, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/register`)
    await page.getByRole('button', { name: /aperte este botão/i }).click()
    await page.waitForFunction(() => document.querySelector('select')?.value !== '')
    const located = log.find((r) => r.path === '/territory/locate')
    assert(located && Math.abs(Number(located.params.latitude) - LUBANGO.latitude) < 1e-6, 'não chamou /territory/locate com as coordenadas')
    const selects = page.locator('select')
    await page.waitForFunction(() => [...document.querySelectorAll('select')].every((s) => s.value !== ''))
    assert((await selects.nth(0).inputValue()) === '1', 'província não preenchida')
    assert((await selects.nth(1).inputValue()) === '10', 'município não preenchido')
    await selects.nth(1).selectOption('11') // o cliente altera
    assert((await selects.nth(1).inputValue()) === '11', 'o cliente não conseguiu alterar o município')
    await context.close()
  })

  await scenario('pedir gás: partilhar localização preenche a zona e o pedido segue com as coordenadas GPS', async () => {
    const { page, log, context } = await newPage(browser, { geolocation: LUBANGO })
    await page.goto(`${BASE}/order`)
    await page.getByLabel('Nome completo').fill('Maria João')
    await page.getByLabel('Número de telefone').fill('923456789')
    await page.getByRole('button', { name: /continuar/i }).click()
    await page.getByRole('button', { name: /partilhar minha localização/i }).click()
    await page.waitForFunction(() => [...document.querySelectorAll('select')].slice(0, 2).every((s) => s.value !== ''))
    assert((await page.getByLabel('Província').inputValue()) === '1', 'província não preenchida')
    assert((await page.getByLabel('Município').inputValue()) === '10', 'município não preenchido')
    await page.getByRole('button', { name: /continuar/i }).click() // zona -> quantidade
    await page.getByRole('button', { name: /continuar/i }).click() // quantidade -> confirmar
    await page.getByRole('button', { name: /enviar pedido/i }).click()
    await page.waitForURL('**/order/success/**')
    const post = log.find((r) => r.path === '/orders' && r.method === 'POST')
    assert(post, 'o pedido não foi enviado')
    const body = JSON.parse(post.body)
    assert(Math.abs(body.latitude - LUBANGO.latitude) < 1e-6 && Math.abs(body.longitude - LUBANGO.longitude) < 1e-6, `coordenadas no pedido: ${body.latitude},${body.longitude}`)
    assert(body.location_source === 'GPS', `origem: ${body.location_source}`)
    assert(body.province_id === 1 && body.municipality_id === 10, 'zona no pedido')
    await context.close()
  })

  await scenario('pedir gás sem localização: segue com a zona manual e origem MANUAL', async () => {
    const { page, log, context } = await newPage(browser)
    await page.goto(`${BASE}/order`)
    await page.getByLabel('Nome completo').fill('Maria João')
    await page.getByLabel('Número de telefone').fill('923456789')
    await page.getByRole('button', { name: /continuar/i }).click()
    await page.getByRole('button', { name: /partilhar minha localização/i }).click()
    await page.getByRole('alert').waitFor() // explica porque falhou
    await page.getByLabel('Província').selectOption('1')
    await page.getByLabel('Município').selectOption('10')
    await page.getByRole('button', { name: /continuar/i }).click() // zona -> quantidade
    await page.getByRole('button', { name: /continuar/i }).click() // quantidade -> confirmar
    await page.getByRole('button', { name: /enviar pedido/i }).click()
    await page.waitForURL('**/order/success/**')
    const body = JSON.parse(log.find((r) => r.path === '/orders' && r.method === 'POST').body)
    assert(body.location_source === 'MANUAL', `origem: ${body.location_source}`)
    assert(body.latitude === undefined && body.longitude === undefined, 'não devia enviar coordenadas')
    await context.close()
  })

  await browser.close()
  // No Windows o servidor corre dentro de um cmd.exe: mata a árvore toda, senão a porta fica presa.
  if (process.platform === 'win32') { try { execSync(`taskkill /pid ${server.pid} /T /F`, { stdio: 'ignore' }) } catch { /* já terminou */ } } else server.kill()

  const failed = results.filter((r) => !r.pass)
  console.log(`\n${results.length - failed.length}/${results.length} cenários passaram`)
  process.exit(failed.length ? 1 : 0)
}

main().catch((e) => { console.error(e); process.exit(1) })
