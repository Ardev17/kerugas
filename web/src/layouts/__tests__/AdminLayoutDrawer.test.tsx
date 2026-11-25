import { describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AdminLayout } from '../AdminLayout'

vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: 'SUPER_ADMIN', name: 'Ana' }, isAuthenticated: true }) }))
vi.mock('@/hooks/useAdminRealtime', () => ({ useAdminRealtime: () => {} }))

describe('menu lateral no telemóvel', () => {
  it('a lista de links rola por dentro e a página por trás fica parada', async () => {
    render(
      <QueryClientProvider client={new QueryClient()}>
        <MemoryRouter initialEntries={['/admin']}><AdminLayout /></MemoryRouter>
      </QueryClientProvider>,
    )
    await userEvent.click(screen.getByRole('button', { name: 'Abrir menu' }))

    const dialog = screen.getByRole('dialog', { name: 'Menu' })
    const nav = dialog.querySelector('nav') as HTMLElement
    expect(nav.className).toContain('overflow-y-auto')
    expect(nav.className).toContain('min-h-0')
    expect(dialog.textContent).toContain('Meu perfil') // último item existe no menu
    expect(document.body.style.overflow).toBe('hidden')

    await userEvent.click(within(dialog).getByRole('button', { name: 'Fechar menu' }))
    expect(document.body.style.overflow).toBe('')
  })
})
