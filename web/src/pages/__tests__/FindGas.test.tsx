import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import FindGas from '../FindGas'
import { GEO_MESSAGES } from '@/lib/geo'
import type { Station } from '@/types'

const nearby = vi.fn()
const lastCall = () => nearby.mock.calls[nearby.mock.calls.length - 1][0]
vi.mock('@/services/stationService', () => ({ stationService: { nearby: (...a: unknown[]) => nearby(...a) } }))
vi.mock('@/services/analyticsService', () => ({ analyticsService: { track: vi.fn() } }))
vi.mock('@/services/territoryService', () => ({
  territoryService: {
    listProvinces: async () => [
      { id: 1, name: 'Huíla' },
      { id: 2, name: 'Luanda' },
    ],
    listMunicipalities: async (provinceId: number) =>
      provinceId === 1 ? [{ id: 10, name: 'Lubango', province_id: 1 }, { id: 11, name: 'Chibia', province_id: 1 }] : [{ id: 20, name: 'Talatona', province_id: 2 }],
  },
}))

const station = (over: Partial<Station> = {}): Station => ({
  id: 1, name: 'Posto Central', slug: 'posto-central', province: 'Huíla', municipality: 'Lubango', address: null,
  reference_point: null, latitude: -14.9177, longitude: 13.4925, phone: null, opening_hours: null, photo_url: null,
  status: 'ACTIVE', gas_available: true, queue_level: 'LOW', cylinder_types: null, confidence_score: 80,
  freshness: 'FRESH', freshness_label: 'x', status_updated_at: new Date().toISOString(), distance_km: 1.2, ...over,
})

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>
        <FindGas />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

function stubGeolocation(behaviour: 'granted' | 'denied') {
  const getCurrentPosition = vi.fn((ok: (p: unknown) => void, fail: (e: { code: number; message: string }) => void) => {
    if (behaviour === 'granted') ok({ coords: { latitude: -14.9177, longitude: 13.4925, accuracy: 12 } })
    else fail({ code: 1, message: 'User denied Geolocation' })
  })
  vi.stubGlobal('navigator', { geolocation: { getCurrentPosition } })
  vi.stubGlobal('isSecureContext', true)
  return getCurrentPosition
}

const provinceSelect = () => screen.getByLabelText('Província') as HTMLSelectElement
const municipalitySelect = () => screen.getByLabelText('Município') as HTMLSelectElement
const useLocationButton = () => screen.getByRole('button', { name: /usar minha localização/i })

async function pickZone(provinceId: string, municipalityId: string) {
  await waitFor(() => expect(provinceSelect().options.length).toBeGreaterThan(1))
  fireEvent.change(provinceSelect(), { target: { value: provinceId } })
  await waitFor(() => expect(municipalitySelect()).not.toBeDisabled())
  await waitFor(() => expect(municipalitySelect().options.length).toBeGreaterThan(1))
  fireEvent.change(municipalitySelect(), { target: { value: municipalityId } })
}

describe('Encontrar gás,localização e zona', () => {
  beforeEach(() => {
    nearby.mockReset()
    nearby.mockResolvedValue({ stations: [station()], blocked: [], accessBlock: null })
  })
  afterEach(() => vi.unstubAllGlobals())

  it('mostra logo a província e o município, antes de carregar em "Usar minha localização"', () => {
    stubGeolocation('granted')
    renderPage()

    expect(provinceSelect()).toBeInTheDocument()
    expect(municipalitySelect()).toBeInTheDocument()
    expect(municipalitySelect()).toBeDisabled() // até escolher a província
    expect(useLocationButton()).toBeInTheDocument()
    expect(nearby).not.toHaveBeenCalled() // nada é pedido sem localização nem zona
  })

  it('lista as províncias e só então os municípios dessa província', async () => {
    stubGeolocation('granted')
    renderPage()

    await waitFor(() => expect(screen.getByRole('option', { name: 'Huíla' })).toBeInTheDocument())
    fireEvent.change(provinceSelect(), { target: { value: '1' } })
    await waitFor(() => expect(screen.getByRole('option', { name: 'Lubango' })).toBeInTheDocument())
    expect(screen.queryByRole('option', { name: 'Talatona' })).not.toBeInTheDocument()
  })

  it('"Usar minha localização" procura por coordenadas, sem município', async () => {
    const getCurrentPosition = stubGeolocation('granted')
    renderPage()

    fireEvent.click(useLocationButton())

    await waitFor(() => expect(nearby).toHaveBeenCalled())
    expect(getCurrentPosition).toHaveBeenCalledTimes(1)
    const params = lastCall()
    expect(params).toMatchObject({ latitude: -14.9177, longitude: 13.4925 })
    expect(params.municipality_id).toBeUndefined()
    expect(await screen.findByText('Posto Central')).toBeInTheDocument()
  })

  it('o botão "Usar minha localização" desaparece quando a localização foi obtida, e a zona continua visível', async () => {
    stubGeolocation('granted')
    renderPage()

    fireEvent.click(useLocationButton())
    await screen.findByText('Posto Central')

    expect(screen.queryByRole('button', { name: /usar minha localização/i })).not.toBeInTheDocument()
    expect(provinceSelect()).toBeInTheDocument()
  })

  it('localização recusada: mostra a explicação em português, e a zona manual funciona', async () => {
    stubGeolocation('denied')
    renderPage()

    fireEvent.click(useLocationButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(GEO_MESSAGES.permission_denied)
    expect(screen.getByRole('alert')).not.toHaveTextContent('User denied')
    expect(nearby).not.toHaveBeenCalled()

    await pickZone('1', '10')
    await waitFor(() => expect(nearby).toHaveBeenCalled())
    expect(lastCall()).toMatchObject({ municipality_id: 10 })
    expect(lastCall().latitude).toBeUndefined()
  })

  it('sem suporte de localização (ou sem https): explica e deixa escolher a zona', async () => {
    vi.stubGlobal('navigator', {})
    renderPage()

    fireEvent.click(useLocationButton())

    expect(await screen.findByRole('alert')).toHaveTextContent(GEO_MESSAGES.unsupported)
    expect(provinceSelect()).not.toBeDisabled()
  })

  it('escolher a zona sem pedir a localização procura só por município', async () => {
    stubGeolocation('granted')
    renderPage()

    await pickZone('1', '11')

    await waitFor(() => expect(nearby).toHaveBeenCalled())
    const params = lastCall()
    expect(params).toMatchObject({ municipality_id: 11 })
    expect(params.latitude).toBeUndefined()
    expect(params.longitude).toBeUndefined()
  })

  it('a escolha manual de município tem prioridade sobre a localização já obtida', async () => {
    stubGeolocation('granted')
    renderPage()

    fireEvent.click(useLocationButton())
    await screen.findByText('Posto Central')

    await pickZone('2', '20')

    await waitFor(() => expect(lastCall()).toMatchObject({ municipality_id: 20 }))
    expect(lastCall().latitude).toBeUndefined()
  })

  it('"Usar minha localização" depois de escolher a zona limpa a escolha e volta às coordenadas', async () => {
    stubGeolocation('granted')
    renderPage()

    await pickZone('1', '10')
    await waitFor(() => expect(nearby).toHaveBeenCalled())

    fireEvent.click(useLocationButton())

    await waitFor(() => expect(lastCall()).toMatchObject({ latitude: -14.9177, longitude: 13.4925 }))
    expect(provinceSelect().value).toBe('')
    expect(municipalitySelect().value).toBe('')
  })

  it('"apenas com gás" é enviado ao servidor', async () => {
    stubGeolocation('granted')
    renderPage()

    fireEvent.click(useLocationButton())
    await screen.findByText('Posto Central')
    fireEvent.click(screen.getByLabelText(/mostrar apenas postos com gás disponível/i))

    await waitFor(() => expect(lastCall()).toMatchObject({ gas_available: true }))
  })

  it('cada resultado traz o "Traçar rota" com as coordenadas do posto', async () => {
    stubGeolocation('granted')
    nearby.mockResolvedValue({ stations: [station(), station({ id: 2, name: 'Posto Norte', latitude: -14.8, longitude: 13.5 })], blocked: [], accessBlock: null })
    renderPage()

    fireEvent.click(useLocationButton())
    await screen.findByText('Posto Norte')

    const routes = screen.getAllByRole('link', { name: /traçar rota/i })
    expect(routes.map((r) => r.getAttribute('href'))).toEqual([
      'https://www.google.com/maps/dir/?api=1&destination=-14.9177,13.4925',
      'https://www.google.com/maps/dir/?api=1&destination=-14.8,13.5',
    ])
  })

  it('um posto sem coordenadas válidas aparece sem o botão de rota (nunca "null,null")', async () => {
    stubGeolocation('granted')
    nearby.mockResolvedValue({ stations: [station({ latitude: null as unknown as number, longitude: null as unknown as number })], blocked: [], accessBlock: null })
    renderPage()

    fireEvent.click(useLocationButton())
    await screen.findByText('Posto Central')

    expect(screen.queryByRole('link', { name: /traçar rota/i })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /ver detalhes/i })).toBeInTheDocument()
  })

  it('sem postos por perto: mensagem clara e saída para "Pedir gás"', async () => {
    stubGeolocation('granted')
    nearby.mockResolvedValue({ stations: [], blocked: [], accessBlock: null })
    renderPage()

    fireEvent.click(useLocationButton())

    expect(await screen.findByText(/não encontramos postos nesta área/i)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /pedir gás/i })).toHaveAttribute('href', '/pedir-gas')
  })

  // ---- consulta fechada por município (Novembro 2026) --------------------

  it('zona fechada: explica a subscrição em vez de dizer que não há postos', async () => {
    nearby.mockResolvedValue({
      stations: [],
      blocked: [{ id: 3, name: 'Lobito', reason: 'Período gratuito terminado.' }],
    })

    renderPage()
    await pickZone('1', '10')

    expect(await screen.findByText(/Lobito: pague a subscrição, para ver os dados/)).toBeInTheDocument()
    expect(screen.getByText('Período gratuito terminado.')).toBeInTheDocument()
    // a mensagem genérica de "não há postos" não pode aparecer: induz em erro
    expect(screen.queryByText(/Não encontramos postos nesta área/)).not.toBeInTheDocument()
  })

  it('zona fechada: dá o caminho para pagar,WhatsApp e os números oficiais', async () => {
    nearby.mockResolvedValue({ stations: [], blocked: [{ id: 3, name: 'Lobito', reason: null }], accessBlock: null })

    renderPage()
    await pickZone('1', '10')

    const whatsapp = await screen.findByRole('link', { name: /Pedir subscrição pelo WhatsApp/ })
    expect(whatsapp).toHaveAttribute('href', expect.stringContaining('wa.me/244922761180'))
    expect(screen.getByRole('link', { name: /922 761 180/ })).toHaveAttribute('href', 'tel:+244922761180')
  })

  it('sem zonas fechadas, a mensagem de vazio continua a ser a de sempre', async () => {
    nearby.mockResolvedValue({ stations: [], blocked: [], accessBlock: null })

    renderPage()
    await pickZone('1', '10')

    expect(await screen.findByText(/Não encontramos postos nesta área/)).toBeInTheDocument()
    expect(screen.queryByText(/assinantes/)).not.toBeInTheDocument()
  })

  it('conta bloqueada: diz que o problema é a conta, não a zona', async () => {
    nearby.mockResolvedValue({
      stations: [],
      blocked: [],
      accessBlock: { reason: 'user_blocked', message: 'A sua conta está sem acesso à consulta dos postos.' },
    })

    renderPage()
    await pickZone('1', '10')

    expect(await screen.findByRole('heading', { name: /A sua conta está sem acesso/ })).toBeInTheDocument()
    expect(screen.getByText('A sua conta está sem acesso à consulta dos postos.')).toBeInTheDocument()
    expect(screen.queryByText(/Não encontramos postos nesta área/)).not.toBeInTheDocument()
    expect(screen.queryByText(/reservada a assinantes/)).not.toBeInTheDocument()
  })

  it('sem sessão numa zona fechada, convida a entrar na conta', async () => {
    nearby.mockResolvedValue({
      stations: [],
      blocked: [{ id: 3, name: 'Benguela', reason: null }],
      accessBlock: { reason: 'login_required', message: 'Inicie sessão para consultar os postos desta zona.' },
    })

    renderPage()
    await pickZone('1', '10')

    expect(await screen.findByRole('link', { name: /Entrar na minha conta/ })).toHaveAttribute('href', '/login')
  })
})
