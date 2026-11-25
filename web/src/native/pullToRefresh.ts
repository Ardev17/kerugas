/** Distância (px de ecrã) a partir da qual largar o dedo recarrega. */
export const PULL_THRESHOLD = 72
/** Quanto o indicador pode descer no máximo. */
export const PULL_MAX = 110
/** Resistência: o indicador anda metade do que o dedo anda. */
export const PULL_RESISTANCE = 0.5

/**
 * Pode começar um "arrastar para recarregar" a partir deste toque? Só no
 * topo da página e nunca quando o gesto já tem outro dono: mapas, campos de
 * texto, janelas abertas ou listas com a sua própria rolagem a meio.
 */
export function canStartPull(target: EventTarget | null, doc: Document = document): boolean {
  const scroller = doc.scrollingElement ?? doc.documentElement
  if (scroller.scrollTop > 0) return false
  // Janela/menu aberto: a página por baixo fica bloqueada (overflow hidden).
  if (doc.body.style.overflow === 'hidden') return false

  let el = target instanceof Element ? target : null
  while (el && el !== doc.documentElement) {
    if (el.matches('input, textarea, select, [contenteditable="true"], [data-no-ptr], .leaflet-container')) return false
    // Uma lista com rolagem própria que não está no topo: o gesto é dela.
    if (el.scrollTop > 0) return false
    el = el.parentElement
  }
  return true
}
