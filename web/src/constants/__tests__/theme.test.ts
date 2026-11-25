import { describe, expect, it } from 'vitest'
import { markerStatusKey, markerStatuses, theme } from '../theme'

describe('estado do posto no mapa', () => {
  it('sem gás é sempre cinzento, mesmo com fila registada', () => {
    const key = markerStatusKey({ gas_available: false, queue_level: 'HIGH' })
    expect(key).toBe('NO_GAS')
    expect(markerStatuses[key].color).toBe(theme.gray)
  })

  it('com gás, a cor segue a fila e o texto diz o mesmo', () => {
    expect(markerStatuses[markerStatusKey({ gas_available: true, queue_level: 'LOW' })]).toEqual({ color: theme.success, label: 'Gás disponível · fila baixa' })
    expect(markerStatuses[markerStatusKey({ gas_available: true, queue_level: 'MEDIUM' })].color).toBe(theme.warning)
    expect(markerStatuses[markerStatusKey({ gas_available: true, queue_level: 'HIGH' })]).toEqual({ color: theme.danger, label: 'Gás disponível · fila alta' })
  })

  it('com gás e sem fila conhecida tem cor própria (não finge ser fila baixa)', () => {
    const key = markerStatusKey({ gas_available: true, queue_level: null })
    expect(key).toBe('UNKNOWN')
    expect(markerStatuses[key].color).not.toBe(theme.success)
  })

  it('cada estado tem uma cor distinta (a legenda nunca repete cores)', () => {
    const colors = Object.values(markerStatuses).map((s) => s.color)
    expect(new Set(colors).size).toBe(colors.length)
  })
})
