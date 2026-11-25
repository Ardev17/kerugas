import { describe, expect, it } from 'vitest'
import { fireEvent, render, screen } from '@testing-library/react'
import { HoneypotField } from '../HoneypotField'
import { useHoneypot } from '@/hooks/useHoneypot'

function Form({ onSubmit }: { onSubmit: (website: string | undefined) => void }) {
  const honeypot = useHoneypot()
  return (
    <form onSubmit={(e) => { e.preventDefault(); onSubmit(honeypot.website) }}>
      <label>Nome<input /></label>
      <HoneypotField {...honeypot.fieldProps} />
      <button type="submit">Enviar</button>
    </form>
  )
}

describe('HoneypotField (anti-bot)', () => {
  it('é invisível para pessoas: fora do ecrã, fora do Tab, fora dos leitores de ecrã e sem name', () => {
    render(<Form onSubmit={() => {}} />)

    // os leitores de ecrã só veem o campo verdadeiro
    expect(screen.getAllByRole('textbox')).toHaveLength(1)
    expect(screen.getByRole('textbox', { name: 'Nome' })).toBeInTheDocument()

    const trap = screen.getByTestId('honeypot')
    expect(trap).toHaveAttribute('aria-hidden', 'true')
    expect(trap.style.position).toBe('absolute')
    expect(trap.style.left).toBe('-9999px')
    const input = trap.querySelector('input')!
    expect(input.tabIndex).toBe(-1)
    expect(input).not.toHaveAttribute('name') // o preenchimento automático não lhe toca
    expect(input).toHaveAttribute('autocomplete', 'off')
  })

  it('uma pessoa envia sem o campo; um bot que o preenche fica marcado', () => {
    const sent: (string | undefined)[] = []
    render(<Form onSubmit={(w) => sent.push(w)} />)

    fireEvent.click(screen.getByRole('button', { name: 'Enviar' }))
    fireEvent.change(screen.getByTestId('honeypot').querySelector('input')!, { target: { value: 'http://spam' } })
    fireEvent.click(screen.getByRole('button', { name: 'Enviar' }))

    expect(sent).toEqual([undefined, 'http://spam'])
  })
})
