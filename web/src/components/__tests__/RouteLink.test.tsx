import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { RouteLink } from '../RouteLink'

describe('RouteLink', () => {
  it('abre a rota do Google Maps num novo separador, de forma segura', () => {
    render(<RouteLink latitude={-14.9177} longitude={13.4925}>Traçar rota</RouteLink>)

    const link = screen.getByRole('link', { name: 'Traçar rota' })
    expect(link).toHaveAttribute('href', 'https://www.google.com/maps/dir/?api=1&destination=-14.9177,13.4925')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link.getAttribute('rel')).toContain('noopener')
    expect(link.getAttribute('rel')).toContain('noreferrer')
  })

  it('aplica a className recebida', () => {
    render(<RouteLink latitude={1} longitude={2} className="btn">Ir</RouteLink>)
    expect(screen.getByRole('link')).toHaveClass('btn')
  })

  it.each([
    ['nulos', null, null],
    ['undefined', undefined, undefined],
    ['NaN', NaN, 13],
    ['fora dos limites', 120, 13],
    ['texto', '-14.9', '13.4'],
  ])('não mostra nada com coordenadas %s (nunca um link para "null,null")', (_label, lat, lng) => {
    const { container } = render(<RouteLink latitude={lat} longitude={lng}>Traçar rota</RouteLink>)
    expect(container).toBeEmptyDOMElement()
  })

  it('aceita 0,0 (coordenada válida)', () => {
    render(<RouteLink latitude={0} longitude={0}>Ir</RouteLink>)
    expect(screen.getByRole('link')).toHaveAttribute('href', expect.stringContaining('destination=0,0'))
  })
})
