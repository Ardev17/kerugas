import { describe, it, expect } from 'vitest'
import { internalPathFromUrl, safeInternalPath } from '../deepLinks'

/**
 * Proteção contra "open redirect": links que chegam de fora (push, SMS,
 * WhatsApp) nunca podem levar a pessoa para outro site.
 */
describe('links externos e caminhos das notificações', () => {
  it('aceita caminhos internos do KeruGás', () => {
    expect(safeInternalPath('/order?repetir=1')).toBe('/order?repetir=1')
    expect(safeInternalPath('/avaliar/KG-20261001-000142')).toBe('/avaliar/KG-20261001-000142')
  })

  it('recusa tudo o que poderia sair do site', () => {
    expect(safeInternalPath('//evil.com')).toBeNull()
    expect(safeInternalPath('/\\evil.com')).toBeNull()
    expect(safeInternalPath('https://evil.com')).toBeNull()
    expect(safeInternalPath('javascript:alert(1)')).toBeNull()
    expect(safeInternalPath('/ok\u0000')).toBeNull()
  })

  it('só abre links do próprio domínio, por HTTPS', () => {
    expect(internalPathFromUrl('https://www.kerugas.ao/pedido/KG-1')).toBe('/pedido/KG-1')
    expect(internalPathFromUrl('https://kerugas.ao/map')).toBe('/map')
    expect(internalPathFromUrl('http://www.kerugas.ao/map')).toBeNull()
    expect(internalPathFromUrl('https://kerugas.ao.evil.com/map')).toBeNull()
    expect(internalPathFromUrl('não é url')).toBeNull()
  })
})
