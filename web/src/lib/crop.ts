/**
 * Matemática do recorte da foto de perfil (Outubro 2026), separada do
 * componente para poder ser testada.
 *
 * Modelo: a imagem é desenhada dentro de uma janela quadrada de `view` px.
 * Com zoom 1 o lado MENOR da imagem enche a janela ("cover"); `zoom` > 1
 * aproxima. `offset` é o deslocamento (px do ecrã) do centro da imagem em
 * relação ao centro da janela.
 */
export interface CropState {
  zoom: number
  x: number
  y: number
}

export const MIN_ZOOM = 1
export const MAX_ZOOM = 4

/** Escala (px do ecrã por px da imagem) para um dado zoom. */
export function displayScale(imgW: number, imgH: number, view: number, zoom: number): number {
  return (view / Math.min(imgW, imgH)) * zoom
}

/** Impede que a imagem deixe espaço vazio dentro da janela. */
export function clampCrop(imgW: number, imgH: number, view: number, state: CropState): CropState {
  const zoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Number.isFinite(state.zoom) ? state.zoom : 1))
  const s = displayScale(imgW, imgH, view, zoom)
  const maxX = Math.max(0, (imgW * s - view) / 2)
  const maxY = Math.max(0, (imgH * s - view) / 2)
  const clamp = (v: number, m: number) => Math.min(m, Math.max(-m, Number.isFinite(v) ? v : 0))
  return { zoom, x: clamp(state.x, maxX), y: clamp(state.y, maxY) }
}

/** Retângulo (em px da imagem original) que fica dentro da janela. Sempre quadrado. */
export function sourceRect(imgW: number, imgH: number, view: number, state: CropState) {
  const { zoom, x, y } = clampCrop(imgW, imgH, view, state)
  const s = displayScale(imgW, imgH, view, zoom)
  // `min`: evita que erros de vírgula flutuante passem a imagem por 1e-13 px.
  const size = Math.min(view / s, imgW, imgH)
  const sx = imgW / 2 - x / s - size / 2
  const sy = imgH / 2 - y / s - size / 2
  return {
    sx: Math.min(Math.max(0, sx), imgW - size),
    sy: Math.min(Math.max(0, sy), imgH - size),
    size,
  }
}
