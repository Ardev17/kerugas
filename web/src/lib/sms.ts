/**
 * Contagem de SMS no navegador (Setembro 2026),o mesmo que o servidor
 * faz (SmsTextNormalizer + SmsSegmentCounter), para quem escreve ver
 * logo quanto vai custar. Sem acentos fora do GSM-7 e sem emojis, uma
 * mensagem até 160 carateres é UM SMS; com um só "ã", passaria a UCS-2
 * (70 carateres por SMS).
 */
const MAP: Record<string, string> = {
  á: 'a', â: 'a', ã: 'a', Á: 'A', Â: 'A', Ã: 'A', À: 'A', ç: 'c',
  ê: 'e', ë: 'e', Ê: 'E', È: 'E', Ë: 'E', í: 'i', î: 'i', ï: 'i', Í: 'I', Î: 'I', Ì: 'I', Ï: 'I',
  ó: 'o', ô: 'o', õ: 'o', Ó: 'O', Ô: 'O', Õ: 'O', Ò: 'O', ú: 'u', û: 'u', Ú: 'U', Û: 'U', Ù: 'U',
  º: 'o', ª: 'a', '·': '-', '•': '-', '‘': "'", '’': "'", '“': '"', '”': '"', '–': '-', '—': '-', '…': '...',
}

const GSM7_BASIC = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà'
const GSM7_EXTENDED = '^{}\\[~]|€'

export function toGsm7(text: string): string {
  return Array.from(text)
    .map((ch) => MAP[ch] ?? ch)
    .join('')
    .replace(/(?:[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}]|\u{FE0F}|\u{200D})/gu, '')
    .replace(/[ \t]{2,}/g, ' ')
    .trim()
}

export function smsInfo(raw: string): { text: string; length: number; segments: number; gsm7: boolean; hasLink: boolean } {
  const text = toGsm7(raw)
  const chars = Array.from(text)
  const gsm7 = chars.every((ch) => GSM7_BASIC.includes(ch) || GSM7_EXTENDED.includes(ch))
  const length = gsm7 ? chars.reduce((n, ch) => n + (GSM7_EXTENDED.includes(ch) ? 2 : 1), 0) : chars.length
  const single = gsm7 ? 160 : 70
  const multi = gsm7 ? 153 : 67
  const segments = length === 0 ? 0 : length <= single ? 1 : Math.ceil(length / multi)
  const hasLink = /(https?:|www\.|\.com\b|\.ao\b|\.net\b|\.org\b)/i.test(text)
  return { text, length, segments, gsm7, hasLink }
}

/**
 * Contador da Central de Operações (Novembro 2026): conta o texto TAL COMO
 * vai ser enviado,sem acentos (o servidor tira-os, como `toGsm7`). Fica
 * em 160 caracteres por SMS (153 por parte). Se sobrar algum caracter fora
 * do GSM-7, a contagem mostra UCS-2 (70 / 67) para não enganar no custo.
 */
export interface SmsCount {
  length: number
  segments: number
  encoding: 'GSM-7' | 'UCS-2'
  /** Caracteres que cabem num SMS com esta codificação (160 ou 70). */
  singleLimit: number
  /** Caracteres por parte quando a mensagem tem mais de um SMS (153 ou 67). */
  perSegment: number
  /** Caracteres que ainda cabem até ao fim do SMS atual. */
  remaining: number
  /** Letras que obrigam a UCS-2 (ex.: á, ç, ã). */
  unicodeChars: string[]
}

export function smsCount(raw: string): SmsCount {
  const chars = Array.from(toGsm7(raw.replace(/\u00A0/g, ' ')))
  const unicodeChars = [...new Set(chars.filter((ch) => !GSM7_BASIC.includes(ch) && !GSM7_EXTENDED.includes(ch)))]
  const gsm7 = unicodeChars.length === 0
  const length = gsm7 ? chars.reduce((n, ch) => n + (GSM7_EXTENDED.includes(ch) ? 2 : 1), 0) : chars.length
  const singleLimit = gsm7 ? 160 : 70
  const perSegment = gsm7 ? 153 : 67
  const segments = length === 0 ? 0 : length <= singleLimit ? 1 : Math.ceil(length / perSegment)
  const capacity = segments <= 1 ? singleLimit : segments * perSegment
  return { length, segments, encoding: gsm7 ? 'GSM-7' : 'UCS-2', singleLimit, perSegment, remaining: capacity - length, unicodeChars }
}
