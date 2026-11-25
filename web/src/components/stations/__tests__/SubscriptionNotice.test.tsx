import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import SubscriptionNotice from '../SubscriptionNotice'

function renderNotice(props: Parameters<typeof SubscriptionNotice>[0]) {
  return render(<MemoryRouter><SubscriptionNotice {...props} /></MemoryRouter>)
}

describe('SubscriptionNotice', () => {
  it('nomeia a zona e mostra o motivo escrito pelo administrador', () => {
    renderNotice({ zones: [{ name: 'Lobito', reason: 'Período gratuito terminado.' }] })

    expect(screen.getByRole('heading', { name: 'Lobito: pague a subscrição, para ver os dados' })).toBeInTheDocument()
    expect(screen.getByText('Período gratuito terminado.')).toBeInTheDocument()
  })

  it('sem motivo escrito, explica à mesma o que se passa', () => {
    renderNotice({ zones: [{ name: 'Lobito' }] })

    expect(screen.getByText(/precisa de uma subscrição activa/)).toBeInTheDocument()
  })

  it('com várias zonas, lista-as todas', () => {
    renderNotice({ zones: [{ name: 'Lobito' }, { name: 'Catumbela' }] })

    expect(screen.getByRole('heading', { name: 'Há zonas reservadas a assinantes' })).toBeInTheDocument()
    expect(screen.getByText('Lobito, Catumbela')).toBeInTheDocument()
  })

  it('o WhatsApp leva o nome da zona já escrito na mensagem', () => {
    renderNotice({ zones: [{ name: 'Lobito' }] })

    const link = screen.getByRole('link', { name: /Pedir subscrição pelo WhatsApp/ })
    expect(link).toHaveAttribute('href', expect.stringContaining('wa.me/244922761180'))
    expect(decodeURIComponent(link.getAttribute('href') ?? '')).toContain('postos do Lobito')
  })

  it('quando falta a sessão, convida a entrar antes de falar em pagar', () => {
    renderNotice({ zones: [{ name: 'Lobito' }], needsLogin: true })

    expect(screen.getByRole('link', { name: /Entrar na minha conta/ })).toHaveAttribute('href', '/login')
    expect(screen.getByText(/Se já tem subscrição, entre na sua conta/)).toBeInTheDocument()
  })

  it('quando é a conta que está bloqueada, não culpa o município', () => {
    renderNotice({ zones: [{ name: 'Lobito' }], accountBlocked: true })

    expect(screen.getByRole('heading', { name: 'A sua conta está sem acesso à consulta' })).toBeInTheDocument()
    expect(screen.queryByText(/reservada a assinantes/)).not.toBeInTheDocument()
  })

  it('mostra os dois números oficiais, clicáveis', () => {
    renderNotice({ zones: [{ name: 'Lobito' }] })

    expect(screen.getByRole('link', { name: /922 761 180/ })).toHaveAttribute('href', 'tel:+244922761180')
    expect(screen.getByRole('link', { name: /964 066 858/ })).toHaveAttribute('href', 'tel:+244964066858')
  })
})
