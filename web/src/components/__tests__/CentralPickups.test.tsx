import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { CentralPickups } from '../admin/CentralPickups'

const role = vi.hoisted(() => ({ value: 'SUPER_ADMIN' }))
const svc = vi.hoisted(() => ({
  centralDrivers: vi.fn(),
  setCentralSwitch: vi.fn(),
  registerCentralPickup: vi.fn(),
  listStations: vi.fn(),
  registerSmsPickup: vi.fn(),
  resetSmsPin: vi.fn(),
  showSmsPin: vi.fn(),
}))
vi.mock('@/services/adminService', () => ({ adminService: svc }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: role.value } }) }))

const offDriver = { id: 7, name: 'Paulo Condutor', phone: '+244923111222', province: 'Benguela', central_active: false, central_expires_at: null, central_reason: null, central_enabled_by: null, sms_pin_set: false }
const onDriver = { ...offDriver, id: 8, name: 'Rita Condutora', central_active: true, central_expires_at: '2026-10-20T10:00:00Z', central_reason: 'Sem telemóvel', central_enabled_by: 'Ana Super' }

function renderIt() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(<QueryClientProvider client={client}><CentralPickups /></QueryClientProvider>)
}

describe('CentralPickups', () => {
  beforeEach(() => {
    role.value = 'SUPER_ADMIN'
    Object.values(svc).forEach((f) => f.mockReset())
    svc.centralDrivers.mockResolvedValue([offDriver, onDriver])
    svc.setCentralSwitch.mockResolvedValue({ id: 7, central_active: true, central_expires_at: null })
    svc.listStations.mockResolvedValue({ data: [{ id: 3, code: '00003', name: 'Posto Norte' }] })
    svc.registerCentralPickup.mockResolvedValue({})
  })

  it('o Super Admin liga o registo de um condutor com motivo obrigatório', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Ligar o registo pela central de Paulo/ }))
    const ligar = screen.getByRole('button', { name: 'Ligar' })
    expect(ligar).toBeDisabled()
    await userEvent.type(screen.getByLabelText('Motivo'), 'Sem telemóvel desde ontem')
    await userEvent.click(ligar)
    await waitFor(() => expect(svc.setCentralSwitch).toHaveBeenCalledWith(7, { enabled: true, reason: 'Sem telemóvel desde ontem', days: 7 }))
  })

  it('o Super Admin desliga um condutor ligado', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Desligar o registo pela central de Rita/ }))
    await waitFor(() => expect(svc.setCentralSwitch).toHaveBeenCalledWith(8, { enabled: false }))
  })

  it('o Admin não vê os botões de ligar/desligar, só o estado', async () => {
    role.value = 'ADMIN'
    renderIt()
    expect(await screen.findByText('Rita Condutora')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ligar o registo/ })).toBeNull()
    expect(screen.queryByRole('button', { name: /Desligar o registo/ })).toBeNull()
    expect(screen.getByText('Ligado')).toBeInTheDocument()
  })

  it('regista um carregamento só para condutores com o registo ligado', async () => {
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Registar carregamento/ }))
    const driverSelect = screen.getByLabelText('Condutor')
    expect(Array.from((driverSelect as HTMLSelectElement).options).map((o) => o.text)).toEqual(['Selecione…', 'Rita Condutora'])

    await userEvent.selectOptions(driverSelect, '8')
    await userEvent.selectOptions(await screen.findByLabelText('Posto'), '3')
    await userEvent.type(screen.getByLabelText('Garrafas'), '10')
    await userEvent.type(screen.getByLabelText(/Valor ao posto/), '12000')
    const registar = screen.getByRole('button', { name: 'Registar' })
    expect(registar).toBeDisabled()
    await userEvent.type(screen.getByLabelText(/Motivo/), 'Ligou do posto, sem telemóvel')
    await userEvent.click(registar)

    await waitFor(() => expect(svc.registerCentralPickup).toHaveBeenCalledTimes(1))
    expect(svc.registerCentralPickup.mock.calls[0][0]).toMatchObject({ driver_id: 8, station_id: 3, quantity: 10, amount: 12000, channel: 'PHONE' })
  })

  it('o botão de registar fica desligado sem nenhum condutor ligado', async () => {
    svc.centralDrivers.mockResolvedValue([offDriver])
    renderIt()
    await screen.findByText('Paulo Condutor')
    expect(screen.getByRole('button', { name: /Registar carregamento/ })).toBeDisabled()
  })

  it('regista um SMS recebido: número + texto colado, só com o formato certo', async () => {
    svc.registerSmsPickup.mockResolvedValue({})
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Registar SMS recebido/ }))
    const registar = screen.getByRole('button', { name: 'Registar' })
    await userEvent.type(screen.getByLabelText('Número de quem enviou'), '923111222')
    await userEvent.type(screen.getByLabelText('Texto do SMS'), 'CARREGAR dez 00023 4821')
    expect(registar).toBeDisabled()
    await userEvent.clear(screen.getByLabelText('Texto do SMS'))
    await userEvent.type(screen.getByLabelText('Texto do SMS'), 'CARREGAR 10 00023 4821')
    await userEvent.click(registar)
    await waitFor(() => expect(svc.registerSmsPickup).toHaveBeenCalledWith({ sender_phone: '923111222', sms_text: 'CARREGAR 10 00023 4821', amount: undefined }))
  })

  it('o Super Admin redefine o PIN de SMS de um condutor', async () => {
    svc.resetSmsPin.mockResolvedValue(undefined)
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: /Redefinir o PIN de SMS de Paulo/ }))
    const guardar = screen.getByRole('button', { name: 'Guardar' })
    expect(guardar).toBeDisabled()
    await userEvent.type(screen.getByLabelText(/PIN \(4 a 6/), '9o51')
    expect(screen.getByLabelText(/PIN \(4 a 6/)).toHaveValue('951')
    await userEvent.type(screen.getByLabelText(/PIN \(4 a 6/), '2')
    await userEvent.click(guardar)
    await waitFor(() => expect(svc.resetSmsPin).toHaveBeenCalledWith(7, '9512'))
  })

  it('o Admin não vê o botão de novo PIN', async () => {
    role.value = 'ADMIN'
    renderIt()
    await screen.findByText('Paulo Condutor')
    expect(screen.queryByRole('button', { name: /Redefinir o PIN/ })).toBeNull()
  })

  it('o Super Admin vê o PIN de carregamento do condutor e volta a escondê-lo', async () => {
    svc.centralDrivers.mockResolvedValue([{ ...offDriver, sms_pin_set: true, sms_pin_temporary: false }])
    svc.showSmsPin.mockResolvedValue({ pin: '9051', pin_set: true, temporary: false, message: null })
    renderIt()
    await userEvent.click(await screen.findByRole('button', { name: 'Ver o PIN de carregamento de Paulo Condutor' }))
    expect(await screen.findByText('9051')).toBeInTheDocument()
    expect(svc.showSmsPin).toHaveBeenCalledWith(7)
    await userEvent.click(screen.getByRole('button', { name: 'Esconder o PIN de Paulo Condutor' }))
    expect(screen.queryByText('9051')).not.toBeInTheDocument()
  })

  it('o Admin não vê o botão do PIN', async () => {
    role.value = 'ADMIN'
    svc.centralDrivers.mockResolvedValue([{ ...offDriver, sms_pin_set: true, sms_pin_temporary: false }])
    renderIt()
    expect(await screen.findByText(/Paulo Condutor/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Ver o PIN/ })).not.toBeInTheDocument()
  })
})
