import { describe, expect, it } from 'vitest'
import { formatKz, formatMinutes, formatPercent, trend } from '../metrics'

describe('formatMinutes', () => {
  it.each([
    [null, '—'],
    [0, '0 min'],
    [8.4, '8 min'],
    [59.6, '1 h'],
    [60, '1 h'],
    [95, '1 h 35 min'],
    [1500, '25 h'],
  ])('%s → %s', (input, expected) => {
    expect(formatMinutes(input)).toBe(expected)
  })
})

describe('formatPercent / formatKz', () => {
  it('formata percentagens e valores', () => {
    expect(formatPercent(null)).toBe('—')
    expect(formatPercent(40)).toContain('40')
    expect(formatPercent(33.3)).toMatch(/33[,.]3%/)
    expect(formatKz(2500)).toMatch(/^2\D?500 Kz$/)
    expect(formatKz(0)).toBe('0 Kz')
  })
})

describe('trend', () => {
  it('compara com o período anterior', () => {
    expect(trend(150, 100)).toEqual({ percent: 50, direction: 'up' })
    expect(trend(50, 100)).toEqual({ percent: -50, direction: 'down' })
    expect(trend(100, 100)).toEqual({ percent: 0, direction: 'flat' })
  })

  it('sem período anterior não inventa uma percentagem', () => {
    expect(trend(10, 0)).toEqual({ percent: null, direction: 'up' })
    expect(trend(0, 0)).toEqual({ percent: null, direction: 'none' })
  })
})
