import { beforeEach, describe, expect, it, vi } from 'vitest'

const cam = vi.hoisted(() => ({ takePhoto: vi.fn(), chooseFromGallery: vi.fn() }))
vi.mock('@capacitor/camera', () => ({ Camera: cam, EncodingType: { JPEG: 0 }, MediaTypeSelection: { Photo: 0 } }))
vi.mock('@/native/platform', () => ({ hasNativePlugin: () => true }))

import { pickNativePhoto } from '../camera'

describe('foto com a câmara/galeria do telemóvel', () => {
  beforeEach(() => {
    cam.takePhoto.mockReset()
    cam.chooseFromGallery.mockReset()
    globalThis.fetch = vi.fn().mockResolvedValue({ blob: async () => new Blob(['x'], { type: 'image/jpeg' }) }) as never
  })

  it('tira foto sem a gravar na galeria e devolve um ficheiro JPEG', async () => {
    cam.takePhoto.mockResolvedValue({ webPath: 'blob:capacitor/1' })
    const file = await pickNativePhoto('camera')
    expect(cam.takePhoto).toHaveBeenCalledWith(expect.objectContaining({ saveToGallery: false, correctOrientation: true, includeMetadata: false }))
    expect(file?.type).toBe('image/jpeg')
  })

  it('escolhe uma só foto da galeria', async () => {
    cam.chooseFromGallery.mockResolvedValue({ results: [{ webPath: 'blob:capacitor/2' }] })
    const file = await pickNativePhoto('gallery')
    expect(cam.chooseFromGallery).toHaveBeenCalledWith(expect.objectContaining({ allowMultipleSelection: false, limit: 1 }))
    expect(file).not.toBeNull()
  })

  it('cancelar não é um erro', async () => {
    cam.takePhoto.mockRejectedValue(new Error('User cancelled photos app'))
    await expect(pickNativePhoto('camera')).resolves.toBeNull()
  })
})
