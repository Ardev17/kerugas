import { describe, expect, it, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent } from '@testing-library/react'
import { isRatingPromptDismissed } from '@/lib/activeOrder'
import { TrackOrderButton } from '../TrackOrderButton'
import { useActiveOrder } from '@/hooks/useActiveOrder'

vi.mock('@/hooks/useActiveOrder', () => ({ useActiveOrder: vi.fn() }))

const mocked = vi.mocked(useActiveOrder)

function renderAt(path: string) {
  return render(
    <QueryClientProvider client={new QueryClient()}>
      <MemoryRouter initialEntries={[path]}>
        <TrackOrderButton />
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('TrackOrderButton', () => {
  beforeEach(() => mocked.mockReset())

  it('aparece com um pedido em curso e leva ao acompanhamento', () => {
    mocked.mockReturnValue({ reference: 'KG-20261002-000001', statusLabel: 'A caminho', kind: 'track' })
    renderAt('/')
    const link = screen.getByRole('link', { name: /acompanhar pedido/i })
    expect(link).toHaveAttribute('href', '/pedido/KG-20261002-000001')
    expect(screen.getByText('A caminho')).toBeInTheDocument()
  })

  it('não aparece sem pedido em curso (nunca pediu, ou já terminou)', () => {
    mocked.mockReturnValue(null)
    renderAt('/')
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('não aparece na própria página de acompanhamento', () => {
    mocked.mockReturnValue({ reference: 'KG-1', statusLabel: 'Confirmado', kind: 'track' })
    renderAt('/pedido/KG-1')
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('não aparece nos Vizinhos (taparia o botão Enviar)', () => {
    mocked.mockReturnValue({ reference: 'KG-1', statusLabel: 'Confirmado', kind: 'track' })
    renderAt('/comunidade')
    expect(screen.queryByRole('link')).toBeNull()
  })

  it('depois da entrega mostra "Avaliar o condutor" e leva à avaliação', () => {
    mocked.mockReturnValue({ reference: 'KG-1', statusLabel: 'Como foi a entrega?', kind: 'rate', phone: '923456789' })
    renderAt('/')
    expect(screen.getByRole('link', { name: 'Avaliar o condutor' })).toHaveAttribute('href', '/avaliar/KG-1')
    expect(screen.queryByRole('link', { name: /acompanhar/i })).toBeNull()
  })

  it('"Agora não" dispensa o convite para avaliar', () => {
    localStorage.clear()
    mocked.mockReturnValue({ reference: 'KG-9', statusLabel: 'Como foi a entrega?', kind: 'rate' })
    renderAt('/')
    fireEvent.click(screen.getByRole('button', { name: 'Agora não' }))
    expect(isRatingPromptDismissed('KG-9')).toBe(true)
  })

  it('não aparece na página de avaliação', () => {
    mocked.mockReturnValue({ reference: 'KG-1', statusLabel: 'Como foi a entrega?', kind: 'rate' })
    renderAt('/avaliar/KG-1')
    expect(screen.queryByRole('link')).toBeNull()
  })
})
