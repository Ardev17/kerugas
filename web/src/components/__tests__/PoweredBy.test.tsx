import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { PoweredBy } from '../PoweredBy'

describe('PoweredBy', () => {
  it('o nome do programador abre o WhatsApp dele', () => {
    render(<p><PoweredBy /></p>)
    const link = screen.getByRole('link', { name: /Aristides Chiovo/ })
    expect(link).toHaveAttribute('href', 'https://wa.me/244936268639')
    expect(link).toHaveAttribute('target', '_blank')
    expect(link).toHaveAttribute('rel', expect.stringContaining('noopener'))
  })
})
