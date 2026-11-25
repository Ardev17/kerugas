import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { RoomChat } from '../RoomChat'
import type { RoomOverview } from '@/types/roomChat'
import type { VzMessage, VzWindow } from '@/types/vizinhos'

const svc = vi.hoisted(() => ({
  overview: vi.fn(), messages: vi.fn(), send: vi.fn(), react: vi.fn(), edit: vi.fn(), remove: vi.fn(),
  stopLocation: vi.fn(), mentionable: vi.fn(), centralMe: vi.fn(), centralThreads: vi.fn(),
}))
vi.mock('@/components/vizinhos/LocationMiniMap', () => ({ default: () => <div data-testid="mini-map" /> }))
vi.mock('@/services/roomChatService', () => ({ roomChatService: svc }))
vi.mock('@/lib/echo', () => ({ getEcho: () => null }))

function overview(canWrite = true): RoomOverview {
  return {
    room: { type: 'drivers', id: 3, title: 'Condutores · Lobito', person: null },
    me: { can_write: canWrite, is_staff: false, moderates: false },
    emojis: ['👍', '❤️'],
    limits: { max_body: 1000, edit_minutes: 15, max_mentions: 5, location_minutes: [15, 30, 60, 120] },
  }
}

function msg(id: number, body: string, extra: Partial<VzMessage> = {}): VzMessage {
  return {
    id, municipality_id: 3, status: 'visible', topic: null, neighborhood: null, official: false, pinned: false, edited: false,
    created_at: new Date().toISOString(), author: { id: 10 + id, name: `Condutor ${id}`, avatar_url: null, badge: null, neighborhood: null, role: 'DRIVER', type_label: 'Condutor' },
    body, mentions: {}, image_url: null, has_image: false, reply_to: null, reactions: {}, my_reaction: null, reactors: [], mine: false, mentions_me: false,
    can_edit: false, can_delete: false, can_report: false, can_moderate: false, removal_reason: null, location: null, ...extra,
  }
}

const win = (data: VzMessage[]): VzWindow => ({ data, meta: { has_older: false, has_newer: false, oldest_id: data[0]?.id ?? null, newest_id: data[data.length - 1]?.id ?? null, municipality_id: 3 } })

function renderChat(props: Partial<Parameters<typeof RoomChat>[0]> = {}) {
  Element.prototype.scrollIntoView = vi.fn()
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><RoomChat type="drivers" roomId={3} {...props} /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Conversa dos condutores', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset())
    svc.overview.mockResolvedValue(overview())
    svc.mentionable.mockResolvedValue([])
  })

  it('mostra as mensagens com o tipo do autor e envia texto', async () => {
    svc.messages.mockResolvedValue(win([msg(1, 'Alguém conhece a Rua 5?')]))
    svc.send.mockResolvedValue(msg(2, 'Eu conheço', { mine: true }))
    renderChat()

    expect(await screen.findByText('Alguém conhece a Rua 5?')).toBeInTheDocument()
    expect(screen.getByText('Condutor')).toBeInTheDocument()

    await userEvent.type(screen.getByLabelText('Mensagem'), 'Eu conheço')
    await userEvent.click(screen.getByRole('button', { name: /Enviar/ }))
    await waitFor(() => expect(svc.send).toHaveBeenCalledWith('drivers', 3, expect.objectContaining({ body: 'Eu conheço' })))
    expect(await screen.findByText('Eu conheço')).toBeInTheDocument()
  })

  it('tem botões para foto e localização', async () => {
    svc.messages.mockResolvedValue(win([]))
    renderChat()
    expect(await screen.findByRole('button', { name: /Foto/ })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Localização/ })).toBeInTheDocument()
  })

  it('mostra quem reagiu a uma mensagem', async () => {
    svc.messages.mockResolvedValue(win([msg(1, 'Bom dia', {
      reactions: { '👍': 2 },
      reactors: [
        { user_id: 5, name: 'Ana', avatar_url: null, emoji: '👍', me: false },
        { user_id: 6, name: 'Rui', avatar_url: null, emoji: '👍', me: true },
      ],
    })]))
    renderChat()

    await userEvent.click(await screen.findByRole('button', { name: 'Ver quem reagiu (2)' }))
    const sheet = screen.getByRole('dialog', { name: '2 reações' })
    expect(within(sheet).getByText('Ana')).toBeInTheDocument()
    expect(within(sheet).getByText('Rui (você)')).toBeInTheDocument()
  })

  it('sem permissão para escrever mostra o aviso em vez do compositor', async () => {
    svc.overview.mockResolvedValue(overview(false))
    svc.messages.mockResolvedValue(win([]))
    renderChat({ readOnlyText: 'Pode responder assim que a Central lhe escrever.' })

    expect(await screen.findByText('Pode responder assim que a Central lhe escrever.')).toBeInTheDocument()
    expect(screen.queryByLabelText('Mensagem')).not.toBeInTheDocument()
  })
})
