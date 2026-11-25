import { describe, expect, it } from 'vitest'
import { render, screen } from '@testing-library/react'
import { CountUp } from '../CountUp'

describe('CountUp', () => {
  it('sem animação (testes / reduzir movimento) mostra logo o valor final', () => {
    render(<CountUp value={2.1} decimals={1} suffix="%" />)
    expect(screen.getByText('2,1%')).toBeInTheDocument()
  })
})
