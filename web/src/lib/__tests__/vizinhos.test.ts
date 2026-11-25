import { describe, expect, it } from 'vitest'
import { dayLabel, encodeMentions, mentionQuery, splitBody, timeAgo } from '../vizinhos'

describe('Vizinhos KeruGás,texto e menções', () => {
  it('parte o texto em pedaços e menções (nome vem do servidor, nunca o número)', () => {
    expect(splitBody('Olá @[12], viste o @[7]?', { '12': 'Ana', '7': 'Rui' })).toEqual([
      { type: 'text', text: 'Olá ' },
      { type: 'mention', id: 12, name: 'Ana' },
      { type: 'text', text: ', viste o ' },
      { type: 'mention', id: 7, name: 'Rui' },
      { type: 'text', text: '?' },
    ])
    expect(splitBody('sem menções', {})).toEqual([{ type: 'text', text: 'sem menções' }])
    expect(splitBody('@[99]', {})[0]).toMatchObject({ name: 'vizinho' })
  })

  it('troca @Nome por @[id] antes de enviar, começando pelos nomes mais compridos', () => {
    const picked = [{ id: 1, name: 'João' }, { id: 2, name: 'João Silva' }]
    expect(encodeMentions('Oi @João Silva e @João!', picked)).toBe('Oi @[2] e @[1]!')
    // não toca em palavras que só começam igual
    expect(encodeMentions('@Joãozinho', [{ id: 1, name: 'João' }])).toBe('@Joãozinho')
  })

  it('deteta a menção que se está a escrever', () => {
    expect(mentionQuery('Olá @Ma', 7)).toEqual({ query: 'Ma', start: 4 })
    expect(mentionQuery('@', 1)).toEqual({ query: '', start: 0 })
    expect(mentionQuery('email a@b', 9)).toBeNull()
    expect(mentionQuery('Olá @Ana e mais', 15)).toBeNull()
  })

  it('"há quanto tempo" em português', () => {
    const now = new Date('2026-10-06T12:00:00Z')
    expect(timeAgo('2026-10-06T11:59:40Z', now)).toBe('agora mesmo')
    expect(timeAgo('2026-10-06T11:55:00Z', now)).toBe('há 5 min')
    expect(timeAgo('2026-10-06T09:00:00Z', now)).toBe('há 3 h')
    expect(timeAgo('2026-10-05T09:00:00Z', now)).toBe('ontem')
    expect(timeAgo('2026-10-02T12:00:00Z', now)).toBe('há 4 dias')
  })

  it('separadores de dia', () => {
    const now = new Date(2026, 9, 6, 15, 0)
    expect(dayLabel(new Date(2026, 9, 6, 8, 0).toISOString(), now)).toBe('Hoje')
    expect(dayLabel(new Date(2026, 9, 5, 23, 0).toISOString(), now)).toBe('Ontem')
  })
})
