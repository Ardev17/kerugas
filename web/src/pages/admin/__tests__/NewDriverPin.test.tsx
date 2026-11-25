import { describe, it, expect, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'

const svc = vi.hoisted(() => ({ createUser: vi.fn(), listDrivers: vi.fn() }))
vi.mock('@/services/adminService', () => ({ adminService: svc }))
vi.mock('@/services/adminGroupsService', () => ({ adminGroupsService: { groups: vi.fn().mockResolvedValue({ tabs: [], groups: [] }), list: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } }) } }))
vi.mock('@/services/territoryService', () => ({
  territoryService: {
    listProvinces: vi.fn().mockResolvedValue([{ id: 5, name: 'Huíla' }]),
    listMunicipalities: vi.fn().mockResolvedValue([{ id: 9, name: 'Lubango' }]),
  },
}))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: 'SUPER_ADMIN', province_id: 5 } }) }))

import AdminDrivers from '../AdminDrivers'

describe('Novo condutor,PIN de SMS', () => {
  it('exige um PIN diferente da palavra-passe e envia-o', async () => {
    svc.listDrivers.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
    svc.createUser.mockResolvedValue({})
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(<QueryClientProvider client={client}><MemoryRouter><AdminDrivers /></MemoryRouter></QueryClientProvider>)

    await userEvent.click(await screen.findByRole('button', { name: /Novo condutor|Adicionar condutor|Criar condutor/i }))
    await userEvent.type(screen.getByPlaceholderText('Nome'), 'Paulo')
    await userEvent.type(screen.getByPlaceholderText('Telefone'), '923111222')
    await userEvent.type(screen.getByPlaceholderText(/Palavra-passe inicial/), '48210000')
    await userEvent.selectOptions(screen.getByLabelText('Província do condutor'), '5')
    const modal = within(screen.getByText('Novo condutor', { selector: 'h3' }).closest('div[class*="max-w-sm"]') as HTMLElement)
    await modal.findByRole('option', { name: 'Lubango' })
    await userEvent.selectOptions(modal.getAllByRole('combobox')[1], '9')

    const criar = screen.getByRole('button', { name: 'Criar condutor' })
    expect(criar).toBeDisabled() // sem PIN

    await userEvent.type(screen.getByLabelText('PIN de SMS'), '48a2')
    expect(screen.getByLabelText('PIN de SMS')).toHaveValue('482')
    await userEvent.type(screen.getByLabelText('PIN de SMS'), '1')
    expect(criar).toBeEnabled()
    await userEvent.click(criar)
    await waitFor(() => expect(svc.createUser).toHaveBeenCalledTimes(1))
    expect(svc.createUser.mock.calls[0][0]).toMatchObject({ role: 'DRIVER', sms_pin: '4821', password: '48210000' })
  })
})
