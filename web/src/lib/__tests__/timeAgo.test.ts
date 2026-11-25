import { describe, expect, it } from 'vitest'
import { elapsedLabel, lastUpdateLabel } from '../timeAgo'

const now = new Date('2026-10-02T12:00:00Z')
const ago = (ms: number) => new Date(now.getTime() - ms).toISOString()
const MIN = 60_000, H = 60 * MIN, D = 24 * H

describe('elapsedLabel', () => {
  it('menos de 1 minuto', () => expect(elapsedLabel(ago(20_000), now)).toBe('há menos de 1 minuto'))
  it('minutos', () => {
    expect(elapsedLabel(ago(MIN), now)).toBe('há 1 minuto')
    expect(elapsedLabel(ago(9 * MIN), now)).toBe('há 9 minutos')
  })
  it('horas e minutos', () => {
    expect(elapsedLabel(ago(5 * H + 9 * MIN), now)).toBe('há 5 horas e 9 minutos')
    expect(elapsedLabel(ago(H), now)).toBe('há 1 hora')
  })
  it('dias e horas, sem minutos', () => {
    expect(elapsedLabel(ago(6 * D), now)).toBe('há 6 dias')
    expect(elapsedLabel(ago(D + 3 * H + 40 * MIN), now)).toBe('há 1 dia e 3 horas')
  })
  it('data no futuro (relógio desfasado) não dá valor negativo', () => {
    expect(elapsedLabel(new Date(now.getTime() + 5 * MIN), now)).toBe('há menos de 1 minuto')
  })
})

describe('lastUpdateLabel', () => {
  it('prefixa com "Última atualização"', () => expect(lastUpdateLabel(ago(5 * H + 9 * MIN), now)).toBe('Última atualização há 5 horas e 9 minutos'))
  it('sem data', () => expect(lastUpdateLabel(null, now)).toBe('Sem atualização registada'))
})
