import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import DeleteAccountInfo from '../DeleteAccountInfo'

describe('Página pública Eliminar conta', () => {
  it('explica como eliminar e o prazo', () => {
    render(<MemoryRouter><DeleteAccountInfo /></MemoryRouter>)
    expect(screen.getByRole('heading', { name: /Eliminar a sua conta/ })).toBeInTheDocument()
    expect(screen.getByText(/7 dias/)).toBeInTheDocument()
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })

  it('confirma a data depois do pedido', () => {
    render(<MemoryRouter initialEntries={['/eliminar-conta?agendada=2026-10-12T10:00:00Z']}><DeleteAccountInfo /></MemoryRouter>)
    expect(screen.getByRole('status')).toHaveTextContent('12 de outubro de 2026')
  })

  it('ignora datas inválidas', () => {
    render(<MemoryRouter initialEntries={['/eliminar-conta?agendada=<script>']}><DeleteAccountInfo /></MemoryRouter>)
    expect(screen.queryByRole('status')).not.toBeInTheDocument()
  })
})
