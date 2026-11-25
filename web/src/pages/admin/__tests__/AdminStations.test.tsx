import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminStations from '../AdminStations'

const svc = vi.hoisted(() => ({
  listStations: vi.fn(), deleteStation: vi.fn(), updateStationStatus: vi.fn(),   sendStationSubscriptionNotice: vi.fn(), listUsers: vi.fn(), createStation: vi.fn(), updateStation: vi.fn(),
  setStationOrdersOverride: vi.fn(), setStationListing: vi.fn(),
}))
const role = vi.hoisted(() => ({ value: 'SUPER_ADMIN' }))
vi.mock('@/services/adminService', () => ({ adminService: svc }))
vi.mock('@/services/adminGroupsService', () => ({ adminGroupsService: { groups: vi.fn().mockResolvedValue({ tabs: [], groups: [] }) } }))
vi.mock('@/services/territoryService', () => ({ territoryService: { listProvinces: vi.fn().mockResolvedValue([]), listMunicipalities: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => ({ user: { id: 1, role: role.value, province_id: 2, province: 'Benguela' } }) }))

const station = {
  id: 7, code: '00007', name: 'Posto Caxila', slug: 'caxila', province_id: 2, municipality_id: 3, province: 'Benguela', municipality: 'Lobito',
  address: null, reference_point: null, latitude: -12.3, longitude: 13.5, phone: null, sms_phone: null,
  operator_id: 4, operator_name: 'Ana, Bruno', operators: [{ id: 4, name: 'Ana' }, { id: 5, name: 'Bruno' }], operator_ids: [4, 5],
  opening_hours: null, status: 'ACTIVE', gas_available: true, queue_level: 'LOW', cylinder_types: null,
  confidence_score: 80, status_updated_at: null,
  orders_override: 'INHERIT', orders_override_reason: null, orders_override_at: null,
  listing_override: 'INHERIT', listing_override_reason: null, platform_owned: false, listing_enforced: false,
  listing_grace_until: null, is_listed: true,
}

/** A lista só é pedida depois de uma pesquisa. */
async function procurar() {
  await userEvent.type(screen.getByLabelText('Pesquisar postos por nome'), 'Caxila')
}

function renderPage() {
  return render(<QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}><AdminStations /></QueryClientProvider>)
}

describe('AdminStations', () => {
  beforeEach(() => {
    Object.values(svc).forEach((f) => f.mockReset())
    role.value = 'SUPER_ADMIN'
    svc.listStations.mockResolvedValue({ data: [station], meta: { current_page: 1, last_page: 1 } })
    svc.deleteStation.mockResolvedValue(undefined)
    svc.updateStationStatus.mockResolvedValue(station)
    svc.setStationListing.mockResolvedValue(station)
    svc.setStationOrdersOverride.mockResolvedValue({
      station: { ...station, orders_override: 'ALLOW' },
      operatorNotified: true, subscriptionActive: false, canReceiveOrders: true,
    })
  })

  it('pede confirmação antes de eliminar um posto', async () => {
    renderPage()
    await userEvent.type(screen.getByLabelText('Pesquisar postos por nome'), 'Caxila')
    const remove = await screen.findAllByRole('button', { name: 'Remover' })
    await userEvent.click(remove[0])

    const dialog = screen.getByRole('alertdialog', { name: 'Eliminar posto?' })
    expect(within(dialog).getByText('Posto Caxila')).toBeInTheDocument()
    expect(svc.deleteStation).not.toHaveBeenCalled()

    await userEvent.click(within(dialog).getByRole('button', { name: 'Cancelar' }))
    expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument()
    expect(svc.deleteStation).not.toHaveBeenCalled()

    await userEvent.click(remove[0])
    await userEvent.click(within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Eliminar' }))
    await waitFor(() => expect(svc.deleteStation).toHaveBeenCalledWith(7))
  })

  it('o Admin muda o estado do posto (ex.: Suspenso) sem poder editar nem apagar', async () => {
    role.value = 'ADMIN'
    renderPage()
    await userEvent.type(screen.getByLabelText('Pesquisar postos por nome'), 'Caxila')
    const buttons = await screen.findAllByRole('button', { name: 'Atualizar estado de Posto Caxila' })
    expect(screen.queryByRole('button', { name: 'Remover' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Editar' })).not.toBeInTheDocument()

    await userEvent.click(buttons[0])
    await userEvent.selectOptions(screen.getByLabelText('Estado do posto'), 'SUSPENDED')
    await userEvent.click(screen.getByRole('button', { name: 'Atualizar estado' }))
    await waitFor(() => expect(svc.updateStationStatus).toHaveBeenCalledWith(7, expect.objectContaining({ status: 'SUSPENDED' })))
  })

  it('mostra os vários operadores do posto', async () => {
    renderPage()
    await userEvent.type(screen.getByLabelText('Pesquisar postos por nome'), 'Caxila')
    expect((await screen.findAllByText(/Ana, Bruno/)).length).toBeGreaterThan(0)
  })

  // ---- interruptor dos pedidos (Novembro 2026) --------------------------

  it('abre o interruptor dos pedidos e mostra os três estados', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    const dialog = screen.getByRole('dialog', { name: 'Pedidos e presença' })
    expect(within(dialog).getByRole('radio', { name: /Segue a subscrição/ })).toBeChecked()
    expect(within(dialog).getByRole('radio', { name: /Recebe sempre/ })).toBeInTheDocument()
    expect(within(dialog).getByRole('radio', { name: /Nunca recebe/ })).toBeInTheDocument()
  })

  it('o motivo só aparece quando se sai do estado por omissão', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    expect(screen.queryByLabelText(/^Motivo/)).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('radio', { name: /Nunca recebe/ }))
    expect(screen.getByLabelText(/^Motivo/)).toBeInTheDocument()
  })

  it('guarda o estado com o motivo e diz que o operador foi avisado', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    await userEvent.click(screen.getByRole('radio', { name: /Recebe sempre/ }))
    await userEvent.type(screen.getByLabelText(/^Motivo/), 'Cortesia de lançamento')
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(svc.setStationOrdersOverride).toHaveBeenCalledWith(7, {
      override: 'ALLOW', reason: 'Cortesia de lançamento',
    }))
    expect(await screen.findByText(/Posto Caxila recebe pedidos\. O operador foi avisado/)).toBeInTheDocument()
  })

  it('sem mudar nada, o Guardar fica inactivo', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    expect(screen.getByRole('button', { name: 'Guardar' })).toBeDisabled()
  })

  it('um posto travado mostra-o na lista, sem ser preciso abrir nada', async () => {
    svc.listStations.mockResolvedValue({
      data: [{ ...station, orders_override: 'BLOCK', orders_override_reason: 'Em obras' }],
      meta: { current_page: 1, last_page: 1 },
    })
    renderPage()
    await procurar()

    expect((await screen.findAllByText('Pedidos: Nunca recebe')).length).toBeGreaterThan(0)
  })

  it('um Admin comum não vê o interruptor', async () => {
    role.value = 'ADMIN'
    renderPage()
    await procurar()
    await screen.findAllByText('Posto Caxila')

    expect(screen.queryByRole('button', { name: /Pedidos de Posto Caxila/ })).not.toBeInTheDocument()
  })

  // ---- presença na aplicação (Novembro 2026, a dormir) ------------------

  it('avisa que a cobrança da presença ainda está desligada', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    expect(screen.getByText('Mecanismo desligado')).toBeInTheDocument()
    expect(screen.getByText(/só passa a valer quando a cobrança for ligada/)).toBeInTheDocument()
  })

  it('guarda a presença sem tocar nos pedidos', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    await userEvent.click(screen.getByRole('button', { name: 'Não aparece' }))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(svc.setStationListing).toHaveBeenCalledWith(7, {
      override: 'BLOCK', platform_owned: false, reason: undefined,
    }))
    expect(svc.setStationOrdersOverride).not.toHaveBeenCalled()
    expect(await screen.findByText(/Presença de Posto Caxila actualizada/)).toBeInTheDocument()
  })

  it('marcar como posto próprio da KeruGás é guardado', async () => {
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    await userEvent.click(screen.getByRole('checkbox', { name: /Posto da própria KeruGás/ }))
    await userEvent.click(screen.getByRole('button', { name: 'Guardar' }))

    await waitFor(() => expect(svc.setStationListing).toHaveBeenCalledWith(7, {
      override: 'INHERIT', platform_owned: true, reason: undefined,
    }))
  })

  it('o Super Admin Provincial vê o posto próprio, mas não o pode mudar', async () => {
    role.value = 'PROVINCE_SUPER_ADMIN'
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    const caixa = screen.getByRole('checkbox', { name: /Posto da própria KeruGás/ })
    expect(caixa).toBeDisabled()
    expect(screen.getByText(/Só o Super Admin geral muda isto/)).toBeInTheDocument()
  })

  it('com a cobrança ligada, o texto muda e não há aviso de desligado', async () => {
    svc.listStations.mockResolvedValue({
      data: [{ ...station, listing_enforced: true }], meta: { current_page: 1, last_page: 1 },
    })
    renderPage()
    await procurar()
    await userEvent.click((await screen.findAllByRole('button', { name: /Pedidos de Posto Caxila/ }))[0])

    expect(screen.queryByText('Mecanismo desligado')).not.toBeInTheDocument()
    expect(screen.getByText(/não aparece no mapa nem nas pesquisas/)).toBeInTheDocument()
  })

  it('um posto em tolerância mostra o dia em que sai do mapa', async () => {
    svc.listStations.mockResolvedValue({
      data: [{ ...station, listing_enforced: true, listing_grace_until: '2027-03-04T00:00:00+01:00' }],
      meta: { current_page: 1, last_page: 1 },
    })
    renderPage()
    await procurar()

    expect((await screen.findAllByText(/sai do mapa a 04\/03/)).length).toBeGreaterThan(0)
  })
})
