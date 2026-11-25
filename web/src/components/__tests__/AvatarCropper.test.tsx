import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AvatarCropper } from '../AvatarCropper'

function loadImage(container: HTMLElement, w: number, h: number) {
  const img = container.querySelector('img') as HTMLImageElement
  Object.defineProperty(img, 'naturalWidth', { configurable: true, value: w })
  Object.defineProperty(img, 'naturalHeight', { configurable: true, value: h })
  fireEvent.load(img)
  return img
}

describe('AvatarCropper', () => {
  const drawImage = vi.fn()

  beforeEach(() => {
    globalThis.URL.createObjectURL = vi.fn(() => 'blob:foto')
    globalThis.URL.revokeObjectURL = vi.fn()
    drawImage.mockReset()
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({ drawImage, fillRect: vi.fn(), fillStyle: '', imageSmoothingEnabled: true, imageSmoothingQuality: 'low' } as never)
    vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(function (cb: BlobCallback, type?: string) {
      cb(new Blob(['x'], { type: type ?? 'image/png' }))
    })
  })
  afterEach(() => vi.restoreAllMocks())

  it('recorta um quadrado e entrega a imagem em JPEG', async () => {
    const onConfirm = vi.fn()
    const { container } = render(<AvatarCropper file={new File(['x'], 'f.jpg', { type: 'image/jpeg' })} onCancel={vi.fn()} onConfirm={onConfirm} />)

    const save = screen.getByRole('button', { name: 'Guardar foto' })
    expect(save).toBeDisabled()
    loadImage(container, 2000, 1000)
    await userEvent.click(save)

    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(1))
    expect((onConfirm.mock.calls[0][0] as Blob).type).toBe('image/jpeg')
    // quadrado central do lado menor (1000 px), redimensionado para 1000 px
    const [, sx, sy, sw, sh, dx, dy, dw, dh] = drawImage.mock.calls[0] as number[]
    expect(sx).toBeCloseTo(500)
    expect(sy).toBeCloseTo(0)
    expect([sw, sh, dx, dy, dw, dh]).toEqual([1000, 1000, 0, 0, 1000, 1000])
  })

  it('recusa imagens demasiado pequenas e mostra o erro do servidor', () => {
    const { container, rerender } = render(<AvatarCropper file={new File(['x'], 'f.png', { type: 'image/png' })} onCancel={vi.fn()} onConfirm={vi.fn()} />)
    loadImage(container, 40, 40)
    expect(screen.getByRole('alert')).toHaveTextContent('demasiado pequena')
    expect(screen.getByRole('button', { name: 'Guardar foto' })).toBeDisabled()

    rerender(<AvatarCropper file={new File(['y'], 'g.png', { type: 'image/png' })} error="A imagem tem de ter no máximo 5 MB." onCancel={vi.fn()} onConfirm={vi.fn()} />)
    expect(screen.getByRole('alert')).toHaveTextContent('5 MB')
  })

  it('Escape fecha', async () => {
    const onCancel = vi.fn()
    render(<AvatarCropper file={new File(['x'], 'f.png', { type: 'image/png' })} onCancel={onCancel} onConfirm={vi.fn()} />)
    await userEvent.keyboard('{Escape}')
    expect(onCancel).toHaveBeenCalled()
  })
})
