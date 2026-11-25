import { beforeEach, describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { OperatorSubscriptionCard } from '../OperatorSubscriptionCard'
import type { OperatorSubscriptionInfo } from '@/types'

const subscription = vi.hoisted(() => vi.fn())
vi.mock('@/services/operatorService', () => ({ operatorService: { subscription } }))

const info = (over: Partial<OperatorSubscriptionInfo> = {}): OperatorSubscriptionInfo => ({
  visible: false, listing_enforced: true, grace_until: null,
  subscribed: false,
  valid_until: null,
  payment: { bank_name: 'BAI', account_holder: 'KeruGás Lda', iban: 'AO06 0040 0000 1234 5678 9012 3', contact: '+244923000000', price: null },
  ...over,
})

function renderCard() {
  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <OperatorSubscriptionCard />
    </QueryClientProvider>,
  )
}

describe('OperatorSubscriptionCard', () => {
  beforeEach(() => { subscription.mockReset() })

  it('posto sem presença: pede para actualizar a subscrição e mostra como pagar', async () => {
    subscription.mockResolvedValue(info())
    renderCard()

    expect(await screen.findByText(/não está visível na aplicação/i)).toBeInTheDocument()
    expect(screen.getByText('AO06 0040 0000 1234 5678 9012 3')).toBeInTheDocument()
    expect(screen.getByText('BAI')).toBeInTheDocument()
    expect(screen.getByText('+244923000000')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /copiar iban/i })).toBeInTheDocument()
  })

  it('sem preço definido não inventa nenhum valor', async () => {
    subscription.mockResolvedValue(info())
    renderCard()

    await screen.findByText(/não está visível na aplicação/i)
    expect(screen.queryByText(/valor mensal/i)).not.toBeInTheDocument()
    expect(screen.queryByText(/Kz/)).not.toBeInTheDocument()
  })

  it('com preço definido mostra-o', async () => {
    subscription.mockResolvedValue(info({ payment: { ...info().payment, price: '10.000 Kz' } }))
    renderCard()

    expect(await screen.findByText('10.000 Kz')).toBeInTheDocument()
  })

  it('sem IBAN configurado manda pedir ao contacto', async () => {
    subscription.mockResolvedValue(info({ payment: { ...info().payment, iban: null } }))
    renderCard()

    expect(await screen.findByText(/peça o iban/i)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /copiar iban/i })).not.toBeInTheDocument()
  })

  it('presença em dia: não mostra nada (cartão oculto)', async () => {
    subscription.mockResolvedValue(info({ visible: true, listing_enforced: false, grace_until: null, subscribed: true, valid_until: '2026-11-04T10:00:00Z' }))
    const { container } = renderCard()

    await waitFor(() => expect(subscription).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it('com a cobrança desligada e sem subscrição: não mostra nada', async () => {
    subscription.mockResolvedValue(info({ visible: true, listing_enforced: false }))
    const { container } = renderCard()

    await waitFor(() => expect(subscription).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })

  it('nos dias de tolerância avisa até quando renovar e mostra como pagar', async () => {
    subscription.mockResolvedValue(info({ visible: true, grace_until: '2026-11-22T10:00:00Z' }))
    renderCard()

    expect(await screen.findByText(/expirou\. Renove até 22\/11\/2026/)).toBeInTheDocument()
    expect(screen.getByText('AO06 0040 0000 1234 5678 9012 3')).toBeInTheDocument()
  })

  it('não mostra nada se o pedido falhar (nunca atrapalha o painel)', async () => {
    subscription.mockRejectedValue(new Error('falhou'))
    const { container } = renderCard()

    await waitFor(() => expect(subscription).toHaveBeenCalled())
    expect(container).toBeEmptyDOMElement()
  })
})
