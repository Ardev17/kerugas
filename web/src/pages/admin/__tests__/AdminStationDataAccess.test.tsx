import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Routes, Route } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminStationDataAccess from '../AdminStationDataAccess'
import type { StationDataMunicipality, StationDataPerson } from '@/services/adminService'

const auth = vi.hoisted(() => ({
  user: { id: 1, role: 'SUPER_ADMIN', name: 'Ardev', province: null } as Record<string, unknown>,
}))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

const svc = vi.hoisted(() => ({
  stationDataMunicipalities: vi.fn(),
  setStationDataMunicipality: vi.fn(),
  stationDataUsers: vi.fn(),
  setStationDataUser: vi.fn(),
}))
vi.mock('@/services/adminService', () => ({ adminService: svc }))

function municipio(over: Partial<StationDataMunicipality> = {}): StationDataMunicipality {
  return {
    id: 1, name: 'Lobito', province: 'Benguela',
    blocked: false, scheduled: false, blocked_from: null, reason: null, blocked_by: null,
    ...over,
  }
}

function pessoa(over: Partial<StationDataPerson> = {}): StationDataPerson {
  return {
    id: 7, name: 'Maria Chissova', phone: '+244922000111', municipality: 'Lobito',
    effect: 'INHERIT', active: false, expires_at: null, reason: null,
    ...over,
  }
}

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/admin/acesso-consulta']}>
        <Routes>
          <Route path="/admin/acesso-consulta" element={<AdminStationDataAccess />} />
          <Route path="/admin" element={<div>Dashboard</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

beforeEach(() => {
  vi.clearAllMocks()
  auth.user = { id: 1, role: 'SUPER_ADMIN', name: 'Ardev', province: null }
  svc.stationDataMunicipalities.mockResolvedValue([municipio()])
  svc.stationDataUsers.mockResolvedValue([pessoa()])
  svc.setStationDataMunicipality.mockResolvedValue(municipio({ blocked: true }))
  svc.setStationDataUser.mockResolvedValue(pessoa({ effect: 'ALLOW', active: true }))
})

describe('AdminStationDataAccess', () => {
  it('mostra o município aberto e o botão de fechar', async () => {
    renderPage()

    expect(await screen.findByText('Lobito')).toBeInTheDocument()
    expect(screen.getByText('Aberto')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Fechar/ })).toBeInTheDocument()
  })

  it('fechar pede o motivo e a data antes de gravar', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(await screen.findByRole('button', { name: /Fechar/ }))

    const motivo = screen.getByLabelText(/Motivo que o utilizador vai ler/)
    await user.type(motivo, 'Período gratuito terminado.')
    await user.click(screen.getByRole('button', { name: 'Confirmar' }))

    await waitFor(() => expect(svc.setStationDataMunicipality).toHaveBeenCalledWith(1, {
      blocked: true, from: undefined, reason: 'Período gratuito terminado.',
    }))
  })

  it('um município já fechado mostra o estado e oferece reabrir', async () => {
    svc.stationDataMunicipalities.mockResolvedValue([
      municipio({ blocked: true, blocked_from: '2026-11-18T10:00:00+01:00', reason: 'Só assinantes.' }),
    ])
    const user = userEvent.setup()
    renderPage()

    expect(await screen.findByText('Fechado')).toBeInTheDocument()
    expect(screen.getByText('“Só assinantes.”')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Reabrir/ }))
    await waitFor(() => expect(svc.setStationDataMunicipality).toHaveBeenCalledWith(1, { blocked: false }))
  })

  it('um bloqueio agendado aparece como agendado, não como fechado', async () => {
    svc.stationDataMunicipalities.mockResolvedValue([
      municipio({ blocked: false, scheduled: true, blocked_from: '2027-02-17T00:00:00+01:00' }),
    ])
    renderPage()

    expect(await screen.findByText('Agendado')).toBeInTheDocument()
    expect(screen.queryByText('Fechado')).not.toBeInTheDocument()
  })

  it('na aba das pessoas, bloquear é imediato', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('tab', { name: 'Pessoas' }))
    expect(await screen.findByText('Maria Chissova')).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Nunca pode' }))
    await waitFor(() => expect(svc.setStationDataUser).toHaveBeenCalledWith(7, { effect: 'DENY' }))
  })

  it('autorizar uma pessoa abre o prazo antes de gravar', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('tab', { name: 'Pessoas' }))
    await user.click(await screen.findByRole('button', { name: 'Pode sempre' }))

    // não grava enquanto não se confirmar
    expect(svc.setStationDataUser).not.toHaveBeenCalled()

    await user.type(screen.getByLabelText(/Nota interna/), 'pagou 30 dias')
    await user.click(screen.getByRole('button', { name: 'Autorizar' }))

    await waitFor(() => expect(svc.setStationDataUser).toHaveBeenCalledWith(7, {
      effect: 'ALLOW', expires_at: undefined, reason: 'pagou 30 dias',
    }))
  })

  it('uma excepção com o prazo já passado aparece como expirada', async () => {
    svc.stationDataUsers.mockResolvedValue([
      pessoa({ effect: 'ALLOW', active: false, expires_at: '2026-10-01T00:00:00+01:00' }),
    ])
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('tab', { name: 'Pessoas' }))
    expect(await screen.findByText('Prazo expirado')).toBeInTheDocument()
  })

  it('a procura por nome vai ao servidor com o termo', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.click(screen.getByRole('tab', { name: 'Pessoas' }))
    await user.type(screen.getByRole('searchbox'), 'Maria')
    await user.click(screen.getByRole('button', { name: 'Procurar' }))

    await waitFor(() => expect(svc.stationDataUsers).toHaveBeenCalledWith('Maria'))
  })

  it('quem não é Super Admin nem chega a ver a página', async () => {
    auth.user = { id: 2, role: 'ADMIN', name: 'Outro', province: 'Benguela' }
    renderPage()

    expect(await screen.findByText('Dashboard')).toBeInTheDocument()
    expect(svc.stationDataMunicipalities).not.toHaveBeenCalled()
  })
})
