import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { DeliveryRoad } from '../DeliveryRoad'

describe('DeliveryRoad', () => {
  it('descreve o estado da viagem para leitores de ecrã', () => {
    const { rerender } = render(<DeliveryRoad progress={0} moving={false} delivered={false} />)
    expect(screen.getByRole('img', { name: /a ser preparado no posto/i })).toBeInTheDocument()
    rerender(<DeliveryRoad progress={0.6} moving delivered={false} />)
    expect(screen.getByRole('img', { name: /a caminho/i })).toHaveClass('is-moving')
    rerender(<DeliveryRoad progress={1} moving={false} delivered />)
    expect(screen.getByRole('img', { name: /chegou a casa/i })).toHaveClass('is-delivered')
  })
})
