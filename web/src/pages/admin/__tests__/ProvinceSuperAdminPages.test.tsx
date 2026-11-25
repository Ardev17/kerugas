import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminUsers from '../AdminUsers'
import AdminSubscriptions from '../AdminSubscriptions'
import AdminNotifications from '../AdminNotifications'

const auth = vi.hoisted(() => ({
  user: { id: 1, role: 'PROVINCE_SUPER_ADMIN', province: 'Huíla', province_id: 5 } as Record<string, unknown>,
}))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

const admin = vi.hoisted(() => ({ listUsers: vi.fn(), updateUser: vi.fn(), listStations: vi.fn() }))
vi.mock('@/services/adminService', () => ({ adminService: admin }))
const groupsSvc = vi.hoisted(() => ({ groups: vi.fn(), list: vi.fn() }))
vi.mock('@/services/adminGroupsService', async (orig) => ({ ...(await orig<typeof import('@/services/adminGroupsService')>()), adminGroupsService: groupsSvc }))
vi.mock('@/services/territoryService', () => ({
  territoryService: { listProvinces: vi.fn().mockResolvedValue([{ id: 5, name: 'Huíla' }, { id: 6, name: 'Luanda' }]), listMunicipalities: vi.fn().mockResolvedValue([]) },
}))
const subs = vi.hoisted(() => ({ list: vi.fn(), settings: vi.fn(), lookup: vi.fn(), activate: vi.fn(), cancel: vi.fn(), updateSettings: vi.fn() }))
vi.mock('@/services/subscriptionService', () => ({ subscriptionService: subs }))
vi.mock('@/services/notificationCenterService', () => ({
  notificationCenterService: {
    campaigns: vi.fn().mockResolvedValue([]), broadcasts: vi.fn().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1 } }),
    previewBroadcast: vi.fn(), storeBroadcast: vi.fn(), cancelBroadcast: vi.fn(), updateCampaign: vi.fn(), testCampaign: vi.fn(), runCampaign: vi.fn(),
  },
}))

function wrap(ui: React.ReactNode) {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter>{ui}</MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Super Admin Provincial no painel', () => {
  beforeEach(() => {
    admin.listUsers.mockReset().mockResolvedValue({
      data: [
        { id: 2, name: 'Admin Huíla', phone: '923', role: 'ADMIN', status: 'ACTIVE', province_id: 5, municipality_id: null, municipality: 'Lubango' },
        { id: 3, name: 'Outro Provincial', phone: '924', role: 'PROVINCE_SUPER_ADMIN', status: 'ACTIVE', province_id: 5, municipality_id: null, municipality: 'Lubango' },
        { id: 4, name: 'Cliente', phone: '925', role: 'USER', status: 'ACTIVE', province_id: 5, municipality_id: null, municipality: 'Lubango' },
      ],
      meta: { current_page: 1, last_page: 1 },
    })
    subs.list.mockReset().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0, summary: { active_sms: 0, active_stations: 1, expiring_7d: 0, stations_analytics_off: 0 } } })
    subs.settings.mockReset().mockResolvedValue({})
    groupsSvc.groups.mockReset().mockResolvedValue({ tabs: [{ key: 'ADMIN', count: 1 }, { key: 'USER', count: 1 }], groups: [] })
    groupsSvc.list.mockReset().mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1 } })
  })

  it('utilizadores: gere Admins (promover, limite SMS) mas não contas de Super Admin nem a província', async () => {
    wrap(<AdminUsers />)
    // A pesquisa mostra a lista simples (sem grupos).
    await userEvent.type(screen.getByLabelText('Pesquisar utilizadores por nome ou telefone'), 'a')

    const roleOfClient = await screen.findByLabelText('Alterar papel de Cliente')
    const options = within(roleOfClient).getAllByRole('option').map((o) => o.textContent)
    expect(options).toContain('Admin')
    expect(options).toContain('Condutor')
    expect(options).not.toContain('Super Admin')
    expect(options).not.toContain('Super Admin Provincial')

    expect(screen.getByLabelText('Alterar papel de Outro Provincial')).toBeDisabled()
    expect(screen.getByLabelText('Permitir que Admin Huíla promova utilizadores')).toBeInTheDocument()
    expect(screen.getByLabelText('Limite diário de SMS de Admin Huíla')).toBeInTheDocument()

    await userEvent.selectOptions(roleOfClient, 'ADMIN')
    expect(screen.getByLabelText('Província de Cliente')).toBeDisabled()
  })

  it('utilizadores: abas por categoria e secções por província → município', async () => {
    groupsSvc.groups.mockImplementation(async (_r: string, params: { role?: string }) => ({
      tabs: [{ key: 'SUPER_ADMIN', count: 0 }, { key: 'ADMIN', count: 1 }, { key: 'DRIVER', count: 2 }, { key: 'USER', count: 0 }],
      groups: params.role === 'DRIVER'
        ? [{ province_id: 5, province: 'Huíla', count: 2, municipalities: [{ municipality_id: 9, municipality: 'Lubango', count: 2 }] }]
        : [{ province_id: 5, province: 'Huíla', count: 1, municipalities: [] }],
    }))
    groupsSvc.list.mockImplementation(async (_r: string, params: { role?: string }) => ({
      data: params.role === 'DRIVER'
        ? [{ id: 7, name: 'Condutor Lubango', phone: '926', role: 'DRIVER', status: 'ACTIVE', province_id: 5, municipality_id: 9, municipality: 'Lubango' }]
        : [{ id: 2, name: 'Admin Huíla', phone: '923', role: 'ADMIN', status: 'ACTIVE', province_id: 5, municipality_id: null, municipality: 'Lubango' }],
      meta: { current_page: 1, last_page: 1 },
    }))
    wrap(<AdminUsers />)

    // abre na primeira categoria com contas (Admins) e, com uma só província, já aberta
    expect(await screen.findByRole('tab', { name: /Admins\s*1/, selected: true })).toBeInTheDocument()
    expect(await screen.findByText('Admin Huíla')).toBeInTheDocument()
    expect(groupsSvc.list).toHaveBeenCalledWith('users', expect.objectContaining({ role: 'ADMIN', province_id: 5 }))

    await userEvent.click(screen.getByRole('tab', { name: /^Condutores \d/ }))
    expect(await screen.findByText('Condutor Lubango')).toBeInTheDocument()
    expect(groupsSvc.list).toHaveBeenCalledWith('users', expect.objectContaining({ role: 'DRIVER', province_id: 5, municipality_id: 9 }))
  })

  it('subscrições: só postos, sem SMS de clientes nem definições de preço/IBAN', async () => {
    wrap(<AdminSubscriptions />)

    expect(await screen.findByText(/Analytics dos postos da província de/)).toBeInTheDocument()
    expect(screen.queryByText('Definições e pagamento')).not.toBeInTheDocument()
    expect(screen.queryByText('Clientes subscritos (SMS)')).not.toBeInTheDocument()
  })

  it('notificações: envio limitado à província, sem campanhas automáticas', async () => {
    wrap(<AdminNotifications />)

    expect(screen.queryByRole('tab', { name: /Campanhas automáticas/ })).not.toBeInTheDocument()
    expect(screen.getByRole('tab', { name: /Enviar notificação/ })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText(/Envios aos utilizadores da província de/)).toBeInTheDocument()
  })
})
