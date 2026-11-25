import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { LocationGroupsView } from '../LocationGroupsView'

const svc = vi.hoisted(() => ({ list: vi.fn() }))
vi.mock('@/services/adminGroupsService', async (orig) => ({ ...(await orig<typeof import('@/services/adminGroupsService')>()), adminGroupsService: svc }))

const groups = [
  { province_id: 5, province: 'Huíla', count: 3, municipalities: [{ municipality_id: 9, municipality: 'Lubango', count: 3 }, { municipality_id: null, municipality: 'Sem município', count: 0 }] },
  { province_id: null, province: 'Sem província', count: 1, municipalities: [] },
]

function renderView(withMunicipalities = true) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <LocationGroupsView<{ id: number; name: string }>
        resource="users"
        queryKey={['t']}
        params={{ role: 'DRIVER' }}
        groups={groups}
        withMunicipalities={withMunicipalities}
        renderItem={(u) => <p key={u.id}>{u.name}</p>}
        emptyText="Vazio"
      />
    </QueryClientProvider>,
  )
}

describe('LocationGroupsView', () => {
  beforeEach(() => {
    svc.list.mockReset().mockImplementation(async (_r: string, p: { page: number }) => ({
      data: [{ id: p.page, name: `Pessoa ${p.page}` }],
      meta: { current_page: p.page, last_page: 2 },
    }))
  })

  it('com várias províncias começam fechadas; abrir carrega só aquele município, com "Mostrar mais"', async () => {
    renderView()
    expect(svc.list).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: /Huíla/ })).toHaveAttribute('aria-expanded', 'false')

    await userEvent.click(screen.getByRole('button', { name: /Huíla/ }))
    await userEvent.click(screen.getByRole('button', { name: /Lubango/ }))
    expect(await screen.findByText('Pessoa 1')).toBeInTheDocument()
    expect(svc.list).toHaveBeenCalledWith('users', { role: 'DRIVER', province_id: 5, municipality_id: 9, page: 1 })

    await userEvent.click(screen.getByRole('button', { name: 'Mostrar mais' }))
    expect(await screen.findByText('Pessoa 2')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mostrar mais' })).not.toBeInTheDocument()
  })

  it('"Sem província" pede os itens sem província (none)', async () => {
    renderView(false)
    await userEvent.click(screen.getByRole('button', { name: /Sem província/ }))
    await screen.findByText('Pessoa 1')
    expect(svc.list).toHaveBeenCalledWith('users', { role: 'DRIVER', province_id: 'none', page: 1 })
  })
})
