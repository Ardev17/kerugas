import { describe, it, expect } from 'vitest'
import { rampIndex, RAMP } from '../heat'

describe('escala do mapa de calor', () => {
  it('zero fica fora da escala; o máximo usa o tom mais escuro', () => {
    expect(rampIndex(0, 10)).toBe(-1)
    expect(rampIndex(1, 0)).toBe(-1)
    expect(rampIndex(10, 10)).toBe(RAMP.length - 1)
    expect(rampIndex(1, 10)).toBe(0)
    expect(rampIndex(5, 10)).toBe(2)
  })
})
