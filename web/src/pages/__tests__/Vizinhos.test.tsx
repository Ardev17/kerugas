import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import Vizinhos from '../Vizinhos'
import type { VzMessage, VzOverview, VzWindow } from '@/types/vizinhos'

const svc = vi.hoisted(() => ({
  overview: vi.fn(), messages: vi.fn(), acceptRules: vi.fn(), send: vi.fn(), react: vi.fn(),
  mentionable: vi.fn(), presence: vi.fn(), stopLocation: vi.fn(), activeLocations: vi.fn(),
}))
vi.mock('@/components/vizinhos/LocationMiniMap', () => ({ default: () => <div data-testid="mini-map" /> }))
vi.mock('@/services/vizinhosService', () => ({ vizinhosService: svc }))
vi.mock('@/lib/echo', () => ({ getEcho: () => null }))

function overview(over: Partial<VzOverview['me']> = {}): VzOverview {
  return {
    municipality: { id: 1, name: 'Viana', province: 'Luanda', enabled: true },
    neighborhoods: [{ id: 5, name: 'Zango' }],
    topics: [{ key: 'gas', label: 'Gás e postos' }],
    report_reasons: [{ key: 'spam', label: 'Spam' }],
    emojis: ['👍'],
    me: { accepted_rules: true, neighborhood_id: 5, can_write: true, writes_as_team: false, muted: false, muted_until: null, is_staff: false, can_administer: false, is_super: false, max_mute_hours: 0, ...over },
    limits: { max_body: 1000, edit_minutes: 15, max_mentions: 5 },
    switch_municipalities: [], presence: { online: 3, active_today: 9 },
  }
}

function msg(id: number, body: string, extra: Partial<VzMessage> = {}): VzMessage {
  return {
    id, municipality_id: 1, status: 'visible', topic: null, neighborhood: null, official: false, pinned: false, edited: false,
    created_at: new Date().toISOString(), author: { id: 10 + id, name: `Vizinho ${id}`, avatar_url: null, badge: null, neighborhood: 'Zango' },
    body, mentions: {}, image_url: null, has_image: false, reply_to: null, reactions: {}, my_reaction: null, mine: false, mentions_me: false,
    can_edit: false, can_delete: false, can_report: true, can_moderate: false, removal_reason: null, ...extra,
  }
}

function win(data: VzMessage[], meta: Partial<VzWindow['meta']> = {}): VzWindow {
  return { data, pinned: [], meta: { has_older: false, has_newer: false, oldest_id: data[0]?.id ?? null, newest_id: data[data.length - 1]?.id ?? null, municipality_id: 1, ...meta } }
}

function renderPage(path = '/comunidade') {
  window.scrollTo = vi.fn() as never
  Element.prototype.scrollIntoView = vi.fn()
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={[path]}><Vizinhos /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Vizinhos KeruGás', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset())
    svc.overview.mockResolvedValue(overview())
    svc.mentionable.mockResolvedValue([])
    svc.presence.mockResolvedValue({ online: 1, active_today: 1, people: [] })
  })

  it('primeira vez: ecrã de boas-vindas com as regras; só entra depois de aceitar', async () => {
    svc.overview.mockResolvedValue(overview({ accepted_rules: false }))
    svc.acceptRules.mockResolvedValue(undefined)
    renderPage()

    expect(await screen.findByRole('heading', { name: /Bem-vindo aos Vizinhos KeruGás/ })).toBeInTheDocument()
    expect(screen.getByText(/Não partilhe telemóveis, links/)).toBeInTheDocument()
    expect(svc.messages).not.toHaveBeenCalled()

    svc.overview.mockResolvedValue(overview())
    svc.messages.mockResolvedValue(win([msg(1, 'Bom dia!')]))
    await userEvent.click(screen.getByRole('button', { name: /Aceito e quero entrar/ }))
    expect(await screen.findByText('Bom dia!')).toBeInTheDocument()
  })

  it('mostra a conversa, as menções como etiqueta (sem números) e quem está online', async () => {
    svc.messages.mockResolvedValue(win([msg(1, 'Olá @[22], há gás?', { mentions: { '22': 'Ana Silva' }, mentions_me: true })]))
    renderPage()

    expect(await screen.findByText('@Ana Silva')).toBeInTheDocument()
    expect(screen.getByText(/3 online · 9 hoje/)).toBeInTheDocument()
    expect(screen.queryByText(/@\[22\]/)).not.toBeInTheDocument()
  })

  it('mensagens removidas aparecem só como aviso, sem texto nem autor', async () => {
    svc.messages.mockResolvedValue(win([msg(1, 'x', { status: 'removed', author: null, body: null })]))
    renderPage()
    expect(await screen.findByText('Mensagem removida pela moderação.')).toBeInTheDocument()
  })

  it('botões de mensagens anteriores e seguintes pedem a janela certa', async () => {
    svc.messages.mockResolvedValueOnce(win([msg(31, 'recente')], { has_older: true, oldest_id: 31 }))
    renderPage()
    expect(await screen.findByText('recente')).toBeInTheDocument()

    svc.messages.mockResolvedValueOnce(win([msg(30, 'antiga')], { has_older: false, has_newer: true, oldest_id: 30, newest_id: 30 }))
    await userEvent.click(screen.getByRole('button', { name: /Ver mensagens anteriores/ }))
    expect(await screen.findByText('antiga')).toBeInTheDocument()
    expect(svc.messages).toHaveBeenLastCalledWith(1, {}, { before_id: 31 })

    svc.messages.mockResolvedValueOnce(win([msg(31, 'recente')], { has_older: true, has_newer: false }))
    await userEvent.click(screen.getByRole('button', { name: /Ver mensagens seguintes/ }))
    expect(await screen.findByText('recente')).toBeInTheDocument()
    expect(svc.messages).toHaveBeenLastCalledWith(1, {}, { after_id: 30 })
  })

  it('abrir uma menção (?mensagem=ID) carrega a janela à volta dela e destaca-a', async () => {
    svc.messages.mockResolvedValue(win([msg(40, 'antes'), msg(41, 'a menção'), msg(42, 'depois')], { has_older: true, has_newer: true }))
    renderPage('/comunidade?municipio=1&mensagem=41')

    await screen.findByText('a menção')
    expect(svc.messages).toHaveBeenCalledWith(1, {}, { around_id: 41 })
    await waitFor(() => expect(document.getElementById('vz-msg-41')?.className).toContain('border-primary bg-primary/10'))
  })

  it('quem está silenciado lê mas não vê o campo de escrever', async () => {
    svc.overview.mockResolvedValue(overview({ muted: true, can_write: false }))
    svc.messages.mockResolvedValue(win([msg(1, 'olá')]))
    renderPage()

    expect(await screen.findByText(/Está silenciado/)).toBeInTheDocument()
    expect(screen.queryByLabelText('Mensagem')).not.toBeInTheDocument()
  })

  it('sem município no perfil, convida a escolher o município', async () => {
    svc.overview.mockRejectedValue({ response: { status: 422, data: { code: 'needs_municipality', message: 'x' } } })
    renderPage()
    expect(await screen.findByRole('link', { name: 'Escolher o meu município' })).toHaveAttribute('href', '/perfil')
  })

  it('envia uma mensagem nova e mostra-a logo', async () => {
    svc.messages.mockResolvedValue(win([]))
    svc.send.mockResolvedValue(msg(5, 'Chegou gás ao posto!', { mine: true }))
    renderPage()

    await userEvent.type(await screen.findByLabelText('Mensagem'), 'Chegou gás ao posto!')
    await userEvent.click(screen.getByRole('button', { name: /Enviar/ }))

    expect(await screen.findByText('Chegou gás ao posto!')).toBeInTheDocument()
    expect(svc.send).toHaveBeenCalledWith(1, expect.objectContaining({ body: 'Chegou gás ao posto!' }))
  })

  it('localização partilhada: quem partilhou para, e o mapa sai da conversa', async () => {
    const live = { kind: 'point' as const, label: null, active: true, expires_at: new Date(Date.now() + 40 * 60_000).toISOString(), ended_at: null, can_stop: true, latitude: -12.59, longitude: 13.41, accuracy: 10, station: null }
    const m = msg(4, '', { mine: true, location: live })
    svc.messages.mockResolvedValue(win([m]))
    svc.stopLocation.mockResolvedValue({ ...m, location: { ...live, active: false, can_stop: false, latitude: null, longitude: null, ended_at: new Date().toISOString() } })
    renderPage()

    expect(await screen.findByRole('link', { name: /Traçar rota/ })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: /Parar de partilhar/ }))

    expect(svc.stopLocation).toHaveBeenCalledWith(4)
    expect(await screen.findByText(/A partilha terminou/)).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: /Traçar rota/ })).not.toBeInTheDocument()
  })
})
