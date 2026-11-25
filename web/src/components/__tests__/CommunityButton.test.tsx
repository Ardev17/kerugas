import { describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { CommunityButton } from '../CommunityButton'

const auth = { isAuthenticated: true }
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

describe('botão Vizinhos KeruGás', () => {
  it('leva ao chat da comunidade quando há sessão', () => {
    auth.isAuthenticated = true
    render(<MemoryRouter><CommunityButton /></MemoryRouter>)
    const link = screen.getByRole('link', { name: /Vizinhos KeruGás/ })
    expect(link.getAttribute('href')).toBe('/comunidade')
    expect(link.textContent).toContain('Vizinhos')
  })

  it('versão compacta mostra só o ícone, com nome acessível', () => {
    auth.isAuthenticated = true
    render(<MemoryRouter><CommunityButton variant="compact" /></MemoryRouter>)
    const link = screen.getByRole('link', { name: /Vizinhos KeruGás/ })
    expect(link.textContent).toBe('')
  })

  it('não aparece a visitantes sem sessão', () => {
    auth.isAuthenticated = false
    render(<MemoryRouter><CommunityButton /></MemoryRouter>)
    expect(screen.queryByRole('link')).toBeNull()
  })
})
