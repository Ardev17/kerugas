/** Lê um valor em Kz escrito pelo utilizador (aceita vírgula). null se vazio/inválido/negativo. */
export function parseKz(text: string): number | null {
  const t = text.trim().replace(/\s/g, '').replace(',', '.')
  if (t === '') return null
  const n = Number(t)
  return Number.isFinite(n) && n >= 0 ? n : null
}
