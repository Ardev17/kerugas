import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import Profile from '../Profile'

const baseUser = {
  id: 1, name: 'Ana Silva', phone: '+244923111000', email: 'ana@exemplo.ao', role: 'USER', status: 'ACTIVE',
  province: 'Huíla', municipality: 'Lubango', province_id: 5, municipality_id: 9, latitude: null, longitude: null, created_at: '2026-01-10T10:00:00Z',
}
const auth = vi.hoisted(() => ({ user: null as Record<string, unknown> | null }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))
const setSession = vi.hoisted(() => vi.fn())
vi.mock('@/stores/authStore', () => {
  const state = () => ({ token: 't0k', user: { id: 1 }, setSession, clearSession })
  const useAuthStore = (sel?: (s: Record<string, unknown>) => unknown) => (sel ? sel(state()) : state())
  useAuthStore.getState = state
  return { useAuthStore }
})
const svc = vi.hoisted(() => ({ update: vi.fn(), changePassword: vi.fn(), sessions: vi.fn(), endOtherSessions: vi.fn() }))
vi.mock('@/services/profileService', async (orig) => ({ ...(await orig<typeof import('@/services/profileService')>()), profileService: svc }))
vi.mock('@/services/territoryService', () => ({
  territoryService: {
    listProvinces: vi.fn().mockResolvedValue([{ id: 5, name: 'Huíla' }, { id: 6, name: 'Luanda' }]),
    listMunicipalities: vi.fn().mockResolvedValue([{ id: 9, name: 'Lubango' }, { id: 10, name: 'Viana' }]),
  },
}))
vi.mock('@/services/vizinhosService', () => ({ vizinhosService: { neighborhoods: vi.fn().mockResolvedValue([{ id: 3, name: 'Zango' }]) } }))
const avatarSvc = vi.hoisted(() => ({ upload: vi.fn(), remove: vi.fn(), removeForUser: vi.fn() }))
vi.mock('@/services/avatarService', () => ({ avatarService: avatarSvc }))
vi.mock('@/components/AvatarCropper', () => ({
  AvatarCropper: ({ onConfirm, error }: { onConfirm: (b: Blob) => void; error?: string | null }) => (
    <div data-testid="cropper">
      {error && <p>{error}</p>}
      <button onClick={() => onConfirm(new Blob(['x'], { type: 'image/webp' }))}>Guardar foto</button>
    </div>
  ),
}))
const accountSvc = vi.hoisted(() => ({ deletionStatus: vi.fn(), requestDeletion: vi.fn() }))
vi.mock('@/services/accountService', () => ({ accountService: accountSvc }))
const clearSession = vi.hoisted(() => vi.fn())
const navigateSpy = vi.hoisted(() => vi.fn())
vi.mock('react-router-dom', async (orig) => ({ ...(await orig<typeof import('react-router-dom')>()), useNavigate: () => navigateSpy }))
vi.mock('@/components/LocationPicker', () => ({ LocationPicker: () => <div data-testid="map" /> }))

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter><Profile /></MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('Meu perfil', () => {
  beforeEach(() => {
    auth.user = { ...baseUser }
    setSession.mockReset()
    svc.update.mockReset().mockImplementation(async (p: Record<string, unknown>) => ({ ...baseUser, ...p }))
    svc.changePassword.mockReset().mockResolvedValue({ other_sessions_ended: 2 })
    svc.sessions.mockReset().mockResolvedValue([
      { id: 1, name: 'kerugas-web', current: true, created_at: null, last_used_at: null },
      { id: 2, name: 'kerugas-web', current: false, created_at: null, last_used_at: null },
    ])
    svc.endOtherSessions.mockReset().mockResolvedValue({ other_sessions_ended: 1 })
  })

  it('muda o nome sem pedir palavra-passe e atualiza a sessão', async () => {
    renderPage()
    const name = screen.getByLabelText('Nome completo')
    await userEvent.clear(name)
    await userEvent.type(name, 'Ana Maria')
    expect(screen.queryByLabelText('Palavra-passe atual', { selector: '#p-current' })).not.toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: 'Guardar' })[0])

    await waitFor(() => expect(svc.update).toHaveBeenCalledWith({ name: 'Ana Maria' }))
    expect(setSession).toHaveBeenCalledWith(expect.objectContaining({ name: 'Ana Maria' }), 't0k')
  })

  it('mudar o telemóvel pede a palavra-passe atual', async () => {
    renderPage()
    const phone = screen.getByLabelText('Telemóvel')
    await userEvent.clear(phone)
    await userEvent.type(phone, '923 222 333')
    const save = screen.getAllByRole('button', { name: 'Guardar' })[0]
    expect(save).toBeDisabled()

    await userEvent.type(document.getElementById('p-current') as HTMLElement, 'Segredo123')
    await userEvent.click(save)
    await waitFor(() => expect(svc.update).toHaveBeenCalledWith({ name: 'Ana Silva', phone: '923 222 333', current_password: 'Segredo123' }))
  })

  it('mostra o erro do servidor junto do campo', async () => {
    svc.update.mockRejectedValue({ response: { status: 422, data: { message: 'x', errors: { email: ['Este email já está associado a outra conta.'] } } } })
    renderPage()
    const email = screen.getByLabelText('Email (opcional)')
    await userEvent.clear(email)
    await userEvent.type(email, 'outro@exemplo.ao')
    await userEvent.type(document.getElementById('p-current') as HTMLElement, 'Segredo123')
    await userEvent.click(screen.getAllByRole('button', { name: 'Guardar' })[0])
    expect(await screen.findByText('Este email já está associado a outra conta.')).toBeInTheDocument()
  })

  it('Admin, Provincial e Condutor não mudam a área de trabalho', async () => {
    auth.user = { ...baseUser, role: 'PROVINCE_SUPER_ADMIN' }
    renderPage()
    expect(screen.getByLabelText('Província')).toBeDisabled()
    expect(screen.getByLabelText('Município')).toBeDisabled()
    expect(screen.getByText(/só o Super Admin as pode alterar/)).toBeInTheDocument()
  })

  it('cliente muda o município e define/remove a localização exata', async () => {
    const getCurrentPosition = vi.fn((ok: PositionCallback) => ok({ coords: { latitude: -14.9171234, longitude: 13.4923456 } } as GeolocationPosition))
    Object.defineProperty(navigator, 'geolocation', { configurable: true, value: { getCurrentPosition } })
    renderPage()

    await screen.findByRole('option', { name: 'Viana' })
    await userEvent.selectOptions(screen.getByLabelText('Município'), '10')
    await userEvent.click(screen.getByRole('button', { name: /Usar a minha localização atual/ }))
    expect(screen.getByTestId('map')).toBeInTheDocument()
    await userEvent.click(screen.getAllByRole('button', { name: 'Guardar' })[1])

    await waitFor(() => expect(svc.update).toHaveBeenCalledWith({ province_id: 5, municipality_id: 10, neighborhood_id: null, latitude: -14.9171234, longitude: 13.4923456 }))
  })

  it('palavra-passe: só envia quando as regras são cumpridas', async () => {
    renderPage()
    const submit = screen.getByRole('button', { name: 'Alterar palavra-passe' })
    await userEvent.type(document.getElementById('pw-current') as HTMLElement, 'Segredo123')
    await userEvent.type(document.getElementById('pw-new') as HTMLElement, 'curta')
    expect(submit).toBeDisabled()

    await userEvent.clear(document.getElementById('pw-new') as HTMLElement)
    await userEvent.type(document.getElementById('pw-new') as HTMLElement, 'NovaSenha99')
    await userEvent.type(document.getElementById('pw-confirm') as HTMLElement, 'NovaSenha99')
    await userEvent.click(submit)

    await waitFor(() => expect(svc.changePassword).toHaveBeenCalledWith({ current_password: 'Segredo123', password: 'NovaSenha99', password_confirmation: 'NovaSenha99' }))
    expect(await screen.findByText(/2 outra\(s\) sessão\(ões\) foram terminadas/)).toBeInTheDocument()
  })

  it('termina as outras sessões', async () => {
    renderPage()
    await userEvent.click(await screen.findByRole('button', { name: /Terminar as outras sessões \(1\)/ }))
    await waitFor(() => expect(svc.endOtherSessions).toHaveBeenCalled())
  })

  it('foto de perfil: escolher → enquadrar → guardar atualiza a sessão', async () => {
    avatarSvc.upload.mockReset().mockResolvedValue({ ...baseUser, avatar_url: 'https://api.test/api/v1/avatars/a.webp' })
    renderPage()

    await userEvent.upload(screen.getByTestId('avatar-input'), new File(['x'], 'eu.jpg', { type: 'image/jpeg' }))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar foto' }))

    await waitFor(() => expect(avatarSvc.upload).toHaveBeenCalledTimes(1))
    expect(setSession).toHaveBeenCalledWith(expect.objectContaining({ avatar_url: 'https://api.test/api/v1/avatars/a.webp' }), 't0k')
    await waitFor(() => expect(screen.queryByTestId('cropper')).not.toBeInTheDocument())
  })

  it('foto de perfil: recusa formatos não suportados antes de enviar', async () => {
    renderPage()
    await userEvent.upload(screen.getByTestId('avatar-input'), new File(['<svg/>'], 'x.svg', { type: 'image/svg+xml' }), { applyAccept: false })
    expect(await screen.findByText(/Formato não suportado/)).toBeInTheDocument()
    expect(screen.queryByTestId('cropper')).not.toBeInTheDocument()
  })

  it('foto de perfil: remover pede confirmação', async () => {
    auth.user = { ...baseUser, avatar_url: 'https://api.test/api/v1/avatars/a.webp' }
    avatarSvc.remove.mockReset().mockResolvedValue({ ...baseUser, avatar_url: null })
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: /Remover/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Sim, remover' }))
    await waitFor(() => expect(avatarSvc.remove).toHaveBeenCalledTimes(1))
    expect(setSession).toHaveBeenCalledWith(expect.objectContaining({ avatar_url: null }), 't0k')
  })

  it('eliminar conta: mostra o que impede a eliminação', async () => {
    accountSvc.deletionStatus.mockReset().mockResolvedValue({ blockers: ['Ainda tem 3 garrafa(s) consigo.'], grace_days: 7 })
    renderPage()
    await userEvent.click(screen.getByRole('button', { name: 'Quero eliminar a minha conta' }))
    expect(await screen.findByText('Ainda tem 3 garrafa(s) consigo.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Eliminar a minha conta' })).not.toBeInTheDocument()
  })

  it('eliminar conta: pede palavra-passe e ELIMINAR, termina a sessão e mostra a data', async () => {
    accountSvc.deletionStatus.mockReset().mockResolvedValue({ blockers: [], grace_days: 7 })
    accountSvc.requestDeletion.mockReset().mockResolvedValue({ scheduled_for: '2026-10-12T10:00:00Z', grace_days: 7 })
    clearSession.mockReset()
    navigateSpy.mockReset()
    renderPage()

    await userEvent.click(screen.getByRole('button', { name: 'Quero eliminar a minha conta' }))
    const submit = await screen.findByRole('button', { name: 'Eliminar a minha conta' })
    await userEvent.type(screen.getByLabelText('Palavra-passe atual', { selector: '#del-password' }), 'Segredo123')
    expect(submit).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Escreva ELIMINAR para confirmar'), 'ELIMINAR')
    await userEvent.click(submit)

    await waitFor(() => expect(accountSvc.requestDeletion).toHaveBeenCalledWith({ current_password: 'Segredo123', confirmation: 'ELIMINAR', reason: undefined }))
    expect(clearSession).toHaveBeenCalled()
    expect(navigateSpy).toHaveBeenCalledWith('/eliminar-conta?agendada=2026-10-12T10%3A00%3A00Z', { replace: true })
  })

  it('eliminar conta: carregamentos por enviar (sem internet) impedem a eliminação', async () => {
    localStorage.setItem('kerugas.pickupQueue.v1', JSON.stringify([{ client_uuid: 'a', station_id: 1, quantity: 2, amount: 0, registered_at: 'x', station_name: 'P', queued_at: 'x', owner_id: 1 }]))
    accountSvc.deletionStatus.mockReset().mockResolvedValue({ blockers: [], grace_days: 7 })
    renderPage()
    await userEvent.click(screen.getByRole('button', { name: 'Quero eliminar a minha conta' }))
    expect(await screen.findByText(/1 carregamento\(s\) por enviar/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Eliminar a minha conta' })).not.toBeInTheDocument()
    localStorage.removeItem('kerugas.pickupQueue.v1')
  })
})
