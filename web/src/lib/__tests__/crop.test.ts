import { describe, expect, it } from 'vitest'
import { clampCrop, sourceRect, MAX_ZOOM } from '../crop'

describe('recorte da foto de perfil', () => {
  it('sem zoom nem deslocamento recorta o quadrado central do lado menor', () => {
    expect(sourceRect(1200, 800, 300, { zoom: 1, x: 0, y: 0 })).toEqual({ sx: 200, sy: 0, size: 800 })
  })

  it('zoom 2 recorta metade do lado, ao centro', () => {
    expect(sourceRect(1000, 1000, 250, { zoom: 2, x: 0, y: 0 })).toEqual({ sx: 250, sy: 250, size: 500 })
  })

  it('arrastar para a direita mostra a parte esquerda da imagem', () => {
    const r = sourceRect(1200, 800, 300, { zoom: 1, x: 75, y: 0 })
    expect(r.sx).toBe(0)
  })

  it('nunca deixa espaço vazio dentro da janela', () => {
    expect(clampCrop(1200, 800, 300, { zoom: 1, x: 9999, y: 9999 })).toEqual({ zoom: 1, x: 75, y: 0 })
    const r = sourceRect(1200, 800, 300, { zoom: 1, x: -9999, y: 0 })
    expect(r.sx + r.size).toBeCloseTo(1200)
  })

  it('limita o zoom e ignora valores inválidos', () => {
    expect(clampCrop(500, 500, 300, { zoom: 99, x: 0, y: 0 }).zoom).toBe(MAX_ZOOM)
    expect(clampCrop(500, 500, 300, { zoom: 0.1, x: 0, y: 0 }).zoom).toBe(1)
    expect(clampCrop(500, 500, 300, { zoom: Number.NaN, x: Number.NaN, y: 0 })).toEqual({ zoom: 1, x: 0, y: 0 })
  })
})
