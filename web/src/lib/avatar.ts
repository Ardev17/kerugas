/** Foto de perfil (Outubro 2026),utilitários partilhados. */

/** Formatos aceites (o servidor volta a verificar pelo conteúdo e re-codifica). */
export const AVATAR_ACCEPT = 'image/jpeg,image/png,image/webp'
const ACCEPTED = AVATAR_ACCEPT.split(',')

/** A foto original pode ser grande: é reduzida no navegador antes de ser enviada. */
export const AVATAR_MAX_INPUT_BYTES = 20 * 1024 * 1024

/** Verificação rápida no navegador, antes de abrir o ecrã de recorte. */
export function validateAvatarFile(file: File): string | null {
  if (!ACCEPTED.includes(file.type)) return 'Formato não suportado. Use uma foto JPG, PNG ou WebP.'
  if (file.size > AVATAR_MAX_INPUT_BYTES) return 'A foto é demasiado grande (máximo 20 MB).'
  if (file.size === 0) return 'O ficheiro está vazio.'
  return null
}

export function initialsOf(name: string | null | undefined): string {
  const parts = (name ?? '').trim().split(/\s+/).filter(Boolean)
  return parts.slice(0, 2).map((p) => p[0]?.toUpperCase()).join('') || '?'
}

/** Só aceita URLs http(s),nunca `javascript:`/`data:` vindos de fora. */
export function safeAvatarUrl(url: string | null | undefined): string | null {
  if (!url) return null
  try {
    const parsed = new URL(url, window.location.origin)
    return parsed.protocol === 'https:' || parsed.protocol === 'http:' ? parsed.href : null
  } catch {
    return null
  }
}
