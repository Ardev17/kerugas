import { beforeEach, describe, expect, it } from 'vitest'
import { clearActiveOrder, isOrderFinished, readActiveOrder, saveActiveOrder } from '../activeOrder'

describe('activeOrder', () => {
  beforeEach(() => localStorage.clear())

  it('guarda, lê e esquece o pedido', () => {
    expect(readActiveOrder()).toBeNull()
    saveActiveOrder({ reference: 'KG-1', phone: '923456789' })
    expect(readActiveOrder()).toEqual({ reference: 'KG-1', phone: '923456789' })
    clearActiveOrder()
    expect(readActiveOrder()).toBeNull()
  })

  it('ignora dados corrompidos', () => {
    localStorage.setItem('kerugas.activeOrder', '{nao-e-json')
    expect(readActiveOrder()).toBeNull()
    localStorage.setItem('kerugas.activeOrder', JSON.stringify({ reference: 'KG-1' }))
    expect(readActiveOrder()).toBeNull()
  })

  it('só considera terminados entregue, cancelado e falhado', () => {
    for (const s of ['DELIVERED', 'CANCELLED', 'FAILED']) expect(isOrderFinished(s)).toBe(true)
    for (const s of ['PENDING', 'RECEIVED', 'CONTACTING', 'CONFIRMED', 'ASSIGNED', 'OUT_FOR_DELIVERY']) expect(isOrderFinished(s)).toBe(false)
  })
})
