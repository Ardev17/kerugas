import { render, screen } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { FaqSection } from '@/components/FaqSection'
import { useAuthStore } from '@/stores/authStore'
import { FAQ_GERAL } from '@/seo/pages'
import type { AuthUser } from '@/types'

vi.mock('@/lib/errorReporting', () => ({ reportError: vi.fn() }))

function Boom(): JSX.Element {
  throw new Error('x is undefined')
}

describe('ErrorBoundary,detalhes técnicos só para a equipa', () => {
  afterEach(() => useAuthStore.setState({ user: null }))

  it('o público não vê a mensagem técnica', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    useAuthStore.setState({ user: { id: 1, role: 'USER' } as AuthUser })
    render(<ErrorBoundary><Boom /></ErrorBoundary>)
    expect(screen.getByText('Algo correu mal nesta página.')).toBeInTheDocument()
    expect(screen.queryByText('Detalhes')).not.toBeInTheDocument()
  })

  it('a equipa vê "Detalhes" com a mensagem', () => {
    vi.spyOn(console, 'error').mockImplementation(() => {})
    useAuthStore.setState({ user: { id: 2, role: 'ADMIN' } as AuthUser })
    render(<ErrorBoundary><Boom /></ErrorBoundary>)
    expect(screen.getByText('Detalhes')).toBeInTheDocument()
    expect(screen.getByText('x is undefined')).toBeInTheDocument()
  })
})

describe('FaqSection', () => {
  it('mostra todas as perguntas frequentes (as mesmas dos dados estruturados)', () => {
    render(<FaqSection />)
    expect(screen.getByRole('heading', { name: 'Perguntas frequentes' })).toBeInTheDocument()
    for (const f of FAQ_GERAL) expect(screen.getByText(f.q)).toBeInTheDocument()
  })
})
