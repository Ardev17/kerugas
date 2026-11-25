import { beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import AdminSubscriptions from '../AdminSubscriptions'
import type { Subscription, SubscriptionSettings } from '@/types'

const auth = vi.hoisted(() => ({ user: { role: 'SUPER_ADMIN' } as { role: string } | null }))
vi.mock('@/hooks/useAuth', () => ({ useAuth: () => auth }))

const svc = vi.hoisted(() => ({
  list: vi.fn(), activate: vi.fn(), cancel: vi.fn(), lookup: vi.fn(), settings: vi.fn(), updateSettings: vi.fn(),
}))
vi.mock('@/services/subscriptionService', () => ({ subscriptionService: svc }))
const listStations = vi.hoisted(() => vi.fn())
vi.mock('@/services/adminService', () => ({ adminService: { listStations } }))

const sub = (over: Partial<Subscription> = {}): Subscription => ({
  id: 1, type: 'SMS_CONSULT', type_label: 'Consulta de gás por SMS', phone: '+244923123456', station: null,
  state: 'ACTIVE', starts_at: '2026-10-04T10:00:00Z', expires_at: '2026-11-04T10:00:00Z', months: 1,
  amount_kz: 5000, payment_reference: 'WhatsApp 04/10', notes: null, activated_by: 'Super', cancelled_at: null,
  created_at: '2026-10-04T10:00:00Z', ...over,
})

const listResult = (rows: Subscription[] = [sub()]) => ({
  data: rows,
  meta: { current_page: 1, last_page: 1, total: rows.length, summary: { active_sms: 3, active_stations: 2, expiring_7d: 1, stations_analytics_off: 4 } },
})

const settings = (over: Partial<SubscriptionSettings> = {}): SubscriptionSettings => ({
  sms_enforcement: false, free_consults_per_month: 3, bank_name: null, account_holder: null, iban: null,
  contact: null, price_sms_kz: null, price_analytics_kz: null, price_station_listing_week_kz: null, listing_enforcement: false, listing_grace_days: 4, ...over,
})

function renderPage() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/admin/subscriptions']}>
        <Routes>
          <Route path="/admin/subscriptions" element={<AdminSubscriptions />} />
          <Route path="/admin" element={<p>Dashboard do admin</p>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('AdminSubscriptions', () => {
  beforeEach(() => {
    Object.values(svc).forEach((m) => m.mockReset())
    listStations.mockReset()
    auth.user = { role: 'SUPER_ADMIN' }
    svc.list.mockResolvedValue(listResult())
    svc.settings.mockResolvedValue(settings())
    svc.lookup.mockResolvedValue({ phone: '+244923123456', status: { enforced: true, limit: 3, used: 3, remaining: 0, subscribed: false, valid_until: null, blocked: true, suggested_template_code: 'SUBSCRIPTION_REQUIRED' }, history: [] })
    listStations.mockResolvedValue({ data: [{ id: 7, name: 'Posto Central', operator_name: 'Maria' }], meta: { current_page: 1, last_page: 1 } })
  })

  it('manda quem não é Super Admin de volta ao painel', async () => {
    auth.user = { role: 'ADMIN' }
    renderPage()

    expect(await screen.findByText('Dashboard do admin')).toBeInTheDocument()
    expect(svc.list).not.toHaveBeenCalled()
  })

  it('mostra o resumo e a lista com estado e validade', async () => {
    renderPage()

    expect(await screen.findAllByText('+244923123456')).not.toHaveLength(0)
    expect(screen.getAllByText('Ativa').length).toBeGreaterThan(0)
    expect(screen.getByText('Clientes subscritos (SMS)').previousElementSibling?.textContent).toBe('3')
    expect(screen.getByText('A terminar em 7 dias').previousElementSibling?.textContent).toBe('1')
  })

  it('lista vazia explica o que fazer', async () => {
    svc.list.mockResolvedValue(listResult([]))
    renderPage()

    expect(await screen.findByText(/ainda não há subscrições/i)).toBeInTheDocument()
  })

  it('filtra por estado no servidor', async () => {
    renderPage()
    await screen.findAllByText('+244923123456')

    fireEvent.change(screen.getByLabelText('Estado'), { target: { value: 'expired' } })

    await waitFor(() => expect(svc.list).toHaveBeenLastCalledWith(expect.objectContaining({ state: 'expired', page: 1 })))
  })

  it('ativa uma subscrição de SMS: mostra as consultas gastas e envia o pedido', async () => {
    svc.activate.mockResolvedValue({
      subscription: sub({ expires_at: '2026-11-04T10:00:00Z' }),
      meta: { confirmation_sms: 'sent' },
    })
    renderPage()
    await screen.findAllByText('+244923123456')

    fireEvent.click(screen.getByRole('button', { name: /ativar subscrição/i }))
    const dialog = await screen.findByRole('dialog', { name: /ativar subscrição/i })

    fireEvent.change(within(dialog).getByLabelText('Telemóvel do cliente'), { target: { value: '923123456' } })
    expect(await within(dialog).findByText(/3 de 3/)).toBeInTheDocument()
    expect(within(dialog).getByText(/sem subscrição ativa/i)).toBeInTheDocument()

    fireEvent.change(within(dialog).getByPlaceholderText(/transferência bai/i), { target: { value: 'Comprovativo X' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Ativar subscrição' }))

    await waitFor(() => expect(svc.activate).toHaveBeenCalledTimes(1))
    expect(svc.activate).toHaveBeenCalledWith(expect.objectContaining({
      type: 'SMS_CONSULT', phone: '923123456', months: 1, payment_reference: 'Comprovativo X', send_confirmation_sms: true,
    }))
    expect(await screen.findByRole('status')).toHaveTextContent(/SMS de confirmação enviado/)
  })

  it('só deixa ativar quando há telemóvel ou posto', async () => {
    renderPage()
    await screen.findAllByText('+244923123456')
    fireEvent.click(screen.getByRole('button', { name: /ativar subscrição/i }))
    const dialog = await screen.findByRole('dialog')

    expect(within(dialog).getByRole('button', { name: 'Ativar subscrição' })).toBeDisabled()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Presença na aplicação' }))
    expect(within(dialog).getByRole('button', { name: 'Ativar subscrição' })).toBeDisabled()

    await within(dialog).findByRole('option', { name: /Posto Central/ })
    fireEvent.change(within(dialog).getByLabelText('Posto'), { target: { value: '7' } })
    expect(within(dialog).getByRole('button', { name: 'Ativar subscrição' })).toBeEnabled()
  })

  it('a presença é cobrada por semanas e traz o analytics incluído', async () => {
    svc.activate.mockResolvedValue({ subscription: sub({ type: 'STATION_LISTING' }), meta: { operator_notified: true } })
    renderPage()
    await screen.findAllByText('+244923123456')
    fireEvent.click(screen.getByRole('button', { name: /ativar subscrição/i }))
    const dialog = await screen.findByRole('dialog')

    fireEvent.click(within(dialog).getByRole('button', { name: 'Presença na aplicação' }))
    await within(dialog).findByRole('option', { name: /Posto Central/ })
    fireEvent.change(within(dialog).getByLabelText('Posto'), { target: { value: '7' } })

    // a nota explica o que a presença traz, e tem de estar visível antes de gravar
    expect(within(dialog).getByText(/analytics e a exportação incluídos/)).toBeInTheDocument()

    fireEvent.click(within(dialog).getByRole('button', { name: 'Ativar subscrição' }))

    await waitFor(() => expect(svc.activate).toHaveBeenCalled())
    expect(svc.activate).toHaveBeenCalledWith(expect.objectContaining({ type: 'STATION_LISTING', station_id: 7, weeks: 2 }))
    expect(await screen.findByRole('status')).toHaveTextContent(/operador foi avisado/i)
  })

  it('cancela uma subscrição depois de confirmar', async () => {
    svc.cancel.mockResolvedValue(sub({ state: 'CANCELLED' }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    renderPage()
    await screen.findAllByText('+244923123456')

    fireEvent.click(screen.getAllByRole('button', { name: 'Cancelar' })[0])

    await waitFor(() => expect(svc.cancel).toHaveBeenCalledWith(1))
  })

  it('não cancela se o Super Admin recusar a confirmação', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    renderPage()
    await screen.findAllByText('+244923123456')

    fireEvent.click(screen.getAllByRole('button', { name: 'Cancelar' })[0])

    expect(svc.cancel).not.toHaveBeenCalled()
  })

  it('definições: guarda a obrigatoriedade, o IBAN e deixa os preços por definir', async () => {
    svc.updateSettings.mockImplementation(async (p) => settings({ ...p, iban: 'AO06 0040 0000 1234 5678 9012 3', sms_enforcement: true }))
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /definições e pagamento/i }))

    fireEvent.click(await screen.findByLabelText(/exigir subscrição depois das consultas grátis/i))
    fireEvent.change(screen.getByLabelText('IBAN'), { target: { value: 'AO06 0040 0000 1234 5678 9012 3' } })
    fireEvent.click(screen.getByRole('button', { name: /guardar definições/i }))

    await waitFor(() => expect(svc.updateSettings).toHaveBeenCalledTimes(1))
    expect(svc.updateSettings).toHaveBeenCalledWith(expect.objectContaining({
      sms_enforcement: true, free_consults_per_month: 3, iban: 'AO06 0040 0000 1234 5678 9012 3', price_sms_kz: 0, price_station_listing_week_kz: null, listing_enforcement: false, listing_grace_days: 4,
    }))
    expect(await screen.findByText(/definições guardadas/i)).toBeInTheDocument()
  })

  it('definições: a obrigatoriedade vem desligada e explica o efeito', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /definições e pagamento/i }))

    const toggle = await screen.findByLabelText(/exigir subscrição depois das consultas grátis/i)
    expect(toggle).not.toBeChecked()
    expect(screen.getByText(/ninguém é bloqueado/i)).toBeInTheDocument()
  })

  it('o interruptor da presença está nas definições e começa desligado', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /definições e pagamento/i }))

    const caixa = await screen.findByRole('checkbox', { name: /Exigir subscrição para o posto aparecer na aplicação/ })
    expect(caixa).not.toBeChecked()
    expect(screen.getByLabelText('Dias de tolerância da presença')).toHaveValue(4)
    expect(screen.getByLabelText('Preço semanal da presença na aplicação')).toBeInTheDocument()
    expect(screen.getByText(/sai assim que carregar aqui/)).toBeInTheDocument()
  })

  it('guarda o interruptor da presença e a tolerância', async () => {
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: /definições e pagamento/i }))

    fireEvent.click(await screen.findByRole('checkbox', { name: /Exigir subscrição para o posto aparecer na aplicação/ }))
    fireEvent.change(screen.getByLabelText('Dias de tolerância da presença'), { target: { value: '7' } })
    fireEvent.click(screen.getByRole('button', { name: /guardar definições/i }))

    await waitFor(() => expect(svc.updateSettings).toHaveBeenCalledWith(
      expect.objectContaining({ listing_enforcement: true, listing_grace_days: 7 }),
    ))
  })
})
