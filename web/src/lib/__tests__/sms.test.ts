import { describe, it, expect } from 'vitest'
import { smsInfo, toGsm7 } from '../sms'

/**
 * Tem de dar exatamente o mesmo que o servidor (SmsTextNormalizer +
 * SmsTextNormalizerTest.php),senão o Super Admin via um custo no
 * painel e pagava outro.
 */
describe('contagem de SMS no painel', () => {
  it('um SMS com acentos portugueses é cobrado como 1, não 2', () => {
    const info = smsInfo('KERUGÁS: Sem postos com gás em Lubango neste momento. Tente novamente mais tarde. Obrigado por usar o KeruGás.')

    expect(info.text).toBe('KERUGAS: Sem postos com gas em Lubango neste momento. Tente novamente mais tarde. Obrigado por usar o KeruGas.')
    expect(info).toMatchObject({ gsm7: true, length: 110, segments: 1 })
  })

  it('remove emojis e mantém é/à', () => {
    expect(toGsm7('Olá ☺️ até à sua porta! 🔥')).toBe('Ola até à sua porta!')
  })

  it('converte ç, õ, ê, í, ú e pontuação tipográfica', () => {
    expect(toGsm7('Ação, Botões, Você, Março,“já”…')).toBe('Acao, Botoes, Voce, Marco - "ja"...')
  })

  it('deteta links (a operadora recusa)', () => {
    expect(smsInfo('Visite www.kerugas.ao').hasLink).toBe(true)
    expect(smsInfo('Peca na app KeruGas').hasLink).toBe(false)
  })

  it('161 carateres passam a 2 SMS', () => {
    expect(smsInfo('a'.repeat(160)).segments).toBe(1)
    expect(smsInfo('a'.repeat(161)).segments).toBe(2)
  })
})

describe('contador da Central de Operações (acentos retirados, Novembro 2026)', () => {
  it('conta o texto já sem acentos: 160 por SMS', async () => {
    const { smsCount } = await import('../sms')
    const one = smsCount('KERUGÁS: ' + 'ã'.repeat(151))
    expect(one).toMatchObject({ length: 160, segments: 1, encoding: 'GSM-7', remaining: 0 })
    expect(one.unicodeChars).toEqual([])
    expect(smsCount('KERUGÁS: ' + 'a'.repeat(152))).toMatchObject({ length: 161, segments: 2, perSegment: 153 })
    expect(smsCount('').segments).toBe(0)
  })

  it('um símbolo que não existe no SMS normal (ex.: →) é avisado com a contagem de 70', async () => {
    const { smsCount } = await import('../sms')
    expect(smsCount('Lobito → Benguela')).toMatchObject({ encoding: 'UCS-2', singleLimit: 70 })
  })
})
