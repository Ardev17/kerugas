import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { LocationCard } from '../LocationCard'
import { LocationShareSheet } from '../LocationShareSheet'
import { durationOptions, locationLive, remainingLabel, stationHere, withLocationEnded } from '@/lib/vizinhos'
import type { VzLocation, VzMessage, VzOverview } from '@/types/vizinhos'

vi.mock('../LocationMiniMap', () => ({ default: () => <div data-testid="mini-map" /> }))
const geo = vi.hoisted(() => ({ state: { latitude: -12.5903 as number | null, longitude: 13.4187 as number | null, accuracy: 12 as number | null, status: 'granted', error: null, reason: null as string | null }, request: vi.fn() }))
vi.mock('@/hooks/useGeolocation', () => ({ useGeolocation: () => ({ ...geo.state, request: geo.request }) }))
const stations = vi.hoisted(() => ({ nearby: vi.fn() }))
vi.mock('@/services/stationService', () => ({ stationService: stations }))
const svc = vi.hoisted(() => ({ shareLocation: vi.fn() }))
vi.mock('@/services/vizinhosService', () => ({ vizinhosService: svc }))

const inMinutes = (m: number) => new Date(Date.now() + m * 60_000).toISOString()

function loc(extra: Partial<VzLocation> = {}): VzLocation {
  return { kind: 'point', label: null, active: true, expires_at: inMinutes(42), ended_at: null, can_stop: false, latitude: -12.5903, longitude: 13.4187, accuracy: 12, station: null, ...extra }
}

function message(location: VzLocation | null, extra: Partial<VzMessage> = {}): VzMessage {
  return {
    id: 7, municipality_id: 1, status: 'visible', topic: null, neighborhood: null, official: false, pinned: false, edited: false,
    created_at: new Date().toISOString(), author: { id: 3, name: 'Ana', avatar_url: null, badge: null, neighborhood: null },
    body: '', mentions: {}, image_url: null, has_image: false, reply_to: null, reactions: {}, my_reaction: null, mine: false, mentions_me: false,
    can_edit: false, can_delete: false, can_report: true, can_moderate: false, removal_reason: null, location, ...extra,
  }
}

function overview(open = true): VzOverview {
  return {
    municipality: { id: 1, name: 'Benguela', province: 'Benguela', enabled: true },
    neighborhoods: [], topics: [], report_reasons: [], emojis: [],
    me: { accepted_rules: true, neighborhood_id: null, can_write: true, writes_as_team: false, muted: false, muted_until: null, is_staff: false, can_administer: false, is_super: false, max_mute_hours: 0 },
    limits: { max_body: 1000, edit_minutes: 15, max_mentions: 5 }, switch_municipalities: [], presence: { online: 1, active_today: 1 },
    location_share: { open, opens_at: '08:00', closes_at: '18:00', durations: [30, 60, 120], closes_at_iso: open ? inMinutes(600) : null, opens_at_iso: inMinutes(600) },
  }
}

const wrap = (ui: ReactNode) => render(
  <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><MemoryRouter>{ui}</MemoryRouter></QueryClientProvider>,
)

describe('Partilha de localização,regras de tempo', () => {
  it('só está "ao vivo" enquanto o servidor diz que sim e a hora não passou', () => {
    expect(locationLive(loc())).toBe(true)
    expect(locationLive(loc({ expires_at: inMinutes(-1) }))).toBe(false)
    expect(locationLive(loc({ active: false }))).toBe(false)
    expect(locationLive(loc({ latitude: null }))).toBe(false)
    expect(locationLive(null)).toBe(false)
  })

  it('perto das 18h, a duração maior acaba às 18h (e não se oferecem as seguintes)', () => {
    const now = new Date('2026-10-08T16:20:00Z') // 17:20 em Angola
    const w = { open: true, opens_at: '08:00', closes_at: '18:00', durations: [30, 60, 120], closes_at_iso: '2026-10-08T17:00:00Z', opens_at_iso: '' }
    expect(durationOptions(w, now)).toEqual([
      { minutes: 30, label: '30 min', capped: false },
      { minutes: 60, label: 'até às 18:00', capped: true },
    ])
    expect(durationOptions({ ...w, open: false }, now)).toEqual([])
  })

  it('contagem, posto onde a pessoa está e fim local da partilha', () => {
    const now = new Date('2026-10-08T10:00:00Z')
    expect(remainingLabel('2026-10-08T10:42:00Z', now)).toBe('faltam 42 min')
    expect(remainingLabel('2026-10-08T11:05:00Z', now)).toBe('faltam 1 h 05 min')
    expect(stationHere([{ id: 1, distance_km: 0.4 }, { id: 2, distance_km: 0.08 }, { id: 3 }])).toEqual({ id: 2, distance_km: 0.08 })
    expect(stationHere([{ id: 1, distance_km: 0.4 }])).toBeNull()
    const ended = withLocationEnded(message(loc({ can_stop: true })))
    expect(ended.location).toMatchObject({ active: false, can_stop: false, latitude: null, longitude: null })
  })
})

describe('Cartão da localização na conversa', () => {
  it('ativa: mini-mapa, rota para o Google Maps e contagem', async () => {
    wrap(<LocationCard message={message(loc())} />)
    expect(screen.getByText('Partilhou a localização')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Traçar rota/ })).toHaveAttribute('href', 'https://www.google.com/maps/dir/?api=1&destination=-12.5903,13.4187')
    expect(screen.getByText(/faltam 42 min/)).toBeInTheDocument()
    expect(await screen.findByTestId('mini-map')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Parar de partilhar/ })).not.toBeInTheDocument()
  })

  it('posto: mostra o posto, o estado do gás e o link para a página do posto', () => {
    wrap(<LocationCard message={message(loc({ kind: 'station', label: 'Posto Caxila', accuracy: null, station: { id: 9, name: 'Posto Caxila', gas_available: true, queue_level: 'LOW' } }))} />)
    expect(screen.getByText('Está no Posto Caxila')).toBeInTheDocument()
    expect(screen.getByText(/Com gás · Fila baixa/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Ver posto/ })).toHaveAttribute('href', expect.stringMatching(/^\/postos\/9(-|$)/))
  })

  it('quem partilhou pode parar', async () => {
    const onStop = vi.fn()
    const m = message(loc({ can_stop: true }), { mine: true })
    wrap(<LocationCard message={m} onStop={onStop} />)
    await userEvent.click(screen.getByRole('button', { name: /Parar de partilhar/ }))
    expect(onStop).toHaveBeenCalledWith(m)
  })

  it('terminada: sem mapa nem rota,só o aviso de que foi apagada', () => {
    wrap(<LocationCard message={message(loc({ active: false, latitude: null, longitude: null, kind: 'station', label: 'Posto Caxila' }))} />)
    expect(screen.getByText(/A partilha terminou e, por segurança, a localização já foi apagada/)).toBeInTheDocument()
    expect(screen.getByText('Posto Caxila')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Traçar rota/ })).not.toBeInTheDocument()
  })

  it('a hora passou mas a API ainda não avisou: já não mostra as coordenadas', () => {
    wrap(<LocationCard message={message(loc({ expires_at: inMinutes(-2) }))} />)
    expect(screen.queryByRole('link', { name: /Traçar rota/ })).not.toBeInTheDocument()
    expect(screen.queryByTestId('mini-map')).not.toBeInTheDocument()
  })
})

describe('Folha "Partilhar a minha localização"', () => {
  beforeEach(() => {
    stations.nearby.mockReset()
    svc.shareLocation.mockReset()
    geo.request.mockReset()
    geo.state = { latitude: -12.5903, longitude: 13.4187, accuracy: 12, status: 'granted', error: null, reason: null }
  })

  it('num posto, propõe o POSTO por omissão e envia o posto com a duração escolhida', async () => {
    stations.nearby.mockResolvedValue({ stations: [{ id: 4, name: 'Posto Benfica', distance_km: 0.06 }], blocked: [], accessBlock: null })
    svc.shareLocation.mockResolvedValue(message(loc()))
    const onSent = vi.fn()
    const onClose = vi.fn()
    wrap(<LocationShareSheet overview={overview()} municipalityId={1} onClose={onClose} onSent={onSent} />)

    expect(await screen.findByLabelText(/Estou no Posto Benfica/)).toBeChecked()
    expect(screen.getByText(/Todos os vizinhos de/)).toHaveTextContent('que está no Posto Benfica')
    expect(screen.getByRole('radio', { name: '1 h' })).toHaveAttribute('aria-checked', 'true')

    await userEvent.click(screen.getByRole('radio', { name: '2 h' }))
    await userEvent.click(screen.getByRole('button', { name: /Partilhar localização/ }))

    await waitFor(() => expect(svc.shareLocation).toHaveBeenCalledWith(1, {
      kind: 'station', latitude: -12.5903, longitude: 13.4187, accuracy: 12, station_id: 4, minutes: 120,
    }))
    expect(onSent).toHaveBeenCalled()
    expect(onClose).toHaveBeenCalled()
  })

  it('longe de um posto: partilha a posição exata', async () => {
    stations.nearby.mockResolvedValue({ stations: [{ id: 4, name: 'Posto Longe', distance_km: 0.9 }], blocked: [], accessBlock: null })
    svc.shareLocation.mockResolvedValue(message(loc()))
    wrap(<LocationShareSheet overview={overview()} municipalityId={1} onClose={vi.fn()} onSent={vi.fn()} />)

    await waitFor(() => expect(screen.getByLabelText(/A minha posição exata/)).toBeChecked())
    expect(screen.queryByText(/Estou no Posto Longe/)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Partilhar localização/ }))
    await waitFor(() => expect(svc.shareLocation).toHaveBeenCalledWith(1, expect.objectContaining({ kind: 'point', station_id: null, minutes: 60 })))
  })

  it('GPS impreciso: não deixa partilhar', async () => {
    geo.state = { ...geo.state, accuracy: 1800 }
    stations.nearby.mockResolvedValue({ stations: [], blocked: [], accessBlock: null })
    wrap(<LocationShareSheet overview={overview()} municipalityId={1} onClose={vi.fn()} onSent={vi.fn()} />)
    expect(await screen.findByText(/A localização está imprecisa/)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Partilhar localização/ })).toBeDisabled()
  })

  it('das 18h às 8h: explica que está desligada e nem pede o GPS', () => {
    wrap(<LocationShareSheet overview={overview(false)} municipalityId={1} onClose={vi.fn()} onSent={vi.fn()} />)
    expect(screen.getByText('Disponível das 08:00 às 18:00')).toBeInTheDocument()
    expect(geo.request).not.toHaveBeenCalled()
    expect(screen.queryByRole('button', { name: /Partilhar localização/ })).not.toBeInTheDocument()
  })
})
