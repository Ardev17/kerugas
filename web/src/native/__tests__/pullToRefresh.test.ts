import { afterEach, describe, expect, it } from 'vitest'
import { canStartPull } from '@/native/pullToRefresh'

function mount(html: string): Element {
  document.body.innerHTML = html
  return document.body.firstElementChild as Element
}

afterEach(() => {
  document.body.innerHTML = ''
  document.body.style.overflow = ''
  ;(document.scrollingElement ?? document.documentElement).scrollTop = 0
})

describe('canStartPull', () => {
  it('permite no topo, sobre conteúdo normal', () => {
    expect(canStartPull(mount('<div><p id="a">olá</p></div>').querySelector('#a'))).toBe(true)
  })

  it('recusa sobre campos de texto, mapas e zonas marcadas', () => {
    expect(canStartPull(mount('<div><textarea></textarea></div>').querySelector('textarea'))).toBe(false)
    expect(canStartPull(mount('<div class="leaflet-container"><span id="a"></span></div>').querySelector('#a'))).toBe(false)
    expect(canStartPull(mount('<div data-no-ptr><span id="a"></span></div>').querySelector('#a'))).toBe(false)
  })

  it('recusa com uma janela aberta (página bloqueada)', () => {
    document.body.style.overflow = 'hidden'
    expect(canStartPull(mount('<div><p id="a">x</p></div>').querySelector('#a'))).toBe(false)
  })

  it('recusa quando a página não está no topo', () => {
    ;(document.scrollingElement ?? document.documentElement).scrollTop = 120
    const el = mount('<div><p id="a">x</p></div>').querySelector('#a')
    // jsdom não rola de verdade: força o valor lido.
    Object.defineProperty(document.scrollingElement ?? document.documentElement, 'scrollTop', { value: 120, configurable: true })
    expect(canStartPull(el)).toBe(false)
    Object.defineProperty(document.scrollingElement ?? document.documentElement, 'scrollTop', { value: 0, configurable: true, writable: true })
  })

  it('recusa dentro de uma lista com rolagem própria a meio', () => {
    const root = mount('<div id="list"><p id="a">x</p></div>')
    Object.defineProperty(root, 'scrollTop', { value: 40, configurable: true })
    expect(canStartPull(root.querySelector('#a'))).toBe(false)
  })
})
