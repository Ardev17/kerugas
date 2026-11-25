import { afterEach, describe, expect, it } from 'vitest'
import { applyTextAssist, installTextAssist, wantsTextAssist } from '@/lib/textAssist'

function field(html: string): HTMLInputElement | HTMLTextAreaElement {
  document.body.innerHTML = html
  return document.body.firstElementChild as HTMLInputElement | HTMLTextAreaElement
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('wantsTextAssist', () => {
  it('liga em áreas de texto e campos de texto livre', () => {
    expect(wantsTextAssist(field('<textarea></textarea>'))).toBe(true)
    expect(wantsTextAssist(field('<input type="text" id="comment" />'))).toBe(true)
    expect(wantsTextAssist(field('<input id="station-name" />'))).toBe(true)
  })

  it('deixa de fora telefone, PIN, e-mail, palavras-passe e números', () => {
    for (const html of [
      '<input type="password" />', '<input type="email" />', '<input type="tel" />', '<input type="number" />',
      '<input inputmode="numeric" />', '<input inputmode="decimal" />', '<input inputmode="tel" />',
      '<input id="rp-pin" />', '<input id="sms-phone" />', '<input name="email" />', '<input autocomplete="off" />',
      '<input autocomplete="one-time-code" />', '<input type="checkbox" />', '<input readonly />', '<input data-no-assist />',
    ]) {
      expect(wantsTextAssist(field(html)), html).toBe(false)
    }
  })
})

describe('applyTextAssist', () => {
  it('escreve os atributos que faltam', () => {
    const t = field('<textarea></textarea>') as HTMLTextAreaElement
    applyTextAssist(t)
    expect(t.getAttribute('spellcheck')).toBe('true')
    expect(t.getAttribute('autocorrect')).toBe('on')
    expect(t.getAttribute('autocapitalize')).toBe('sentences')
  })

  it('põe maiúscula em cada palavra nos campos de nome', () => {
    const i = field('<input id="p-name" />') as HTMLInputElement
    applyTextAssist(i)
    expect(i.getAttribute('autocapitalize')).toBe('words')
  })

  it('nunca mexe no que já está definido', () => {
    const i = field('<input spellcheck="false" autocapitalize="characters" autocorrect="off" />') as HTMLInputElement
    applyTextAssist(i)
    expect(i.getAttribute('spellcheck')).toBe('false')
    expect(i.getAttribute('autocapitalize')).toBe('characters')
    expect(i.getAttribute('autocorrect')).toBe('off')
  })
})

describe('installTextAssist', () => {
  it('apanha também os campos que aparecem depois', async () => {
    const off = installTextAssist()
    const t = document.createElement('textarea')
    document.body.appendChild(t)
    await Promise.resolve()
    await new Promise((r) => setTimeout(r, 0))
    expect(t.getAttribute('autocorrect')).toBe('on')
    off()
  })
})
