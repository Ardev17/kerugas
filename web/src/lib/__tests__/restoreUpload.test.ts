import { describe, expect, it, vi, beforeEach } from 'vitest'
import { AxiosError, type AxiosResponse } from 'axios'

const chunk = vi.fn()
vi.mock('@/services/restoreService', () => ({ restoreService: { chunk: (...a: unknown[]) => chunk(...a) } }))

import { photoRelativePath, uploadInChunks } from '../restoreUpload'

describe('photoRelativePath', () => {
  it('guarda o caminho a partir de avatars/ ou community/', () => {
    expect(photoRelativePath('Cópia/fotos/avatars/ab.webp')).toBe('avatars/ab.webp')
    expect(photoRelativePath('community/2026/10/x.jpg')).toBe('community/2026/10/x.jpg')
  })
  it('ignora o que não é foto dessas pastas', () => {
    expect(photoRelativePath('docs/a.jpg')).toBeNull()
    expect(photoRelativePath('avatars/../.env')).toBeNull()
    expect(photoRelativePath('avatars/script.php')).toBeNull()
    expect(photoRelativePath('avatars')).toBeNull()
  })
})

function conflict(received: number) {
  return new AxiosError('conflito', '409', undefined, undefined, { status: 409, data: { data: { received } } } as AxiosResponse)
}

describe('uploadInChunks', () => {
  beforeEach(() => {
    chunk.mockReset()
  })

  it('envia em partes e retoma onde o servidor ficou (409)', async () => {
    const file = new Blob(['0123456789'])
    const offsets: string[] = []
    chunk.mockImplementation(async (_id: string, form: FormData) => {
      const off = form.get('offset') as string
      offsets.push(off)
      if (off === '4' && offsets.filter((o) => o === '4').length === 1) throw conflict(2)
      const piece = form.get('chunk') as Blob
      return { received: Number(off) + piece.size }
    })
    const progress: number[] = []
    await uploadInChunks('s', 'sql', file, 'a.sql', 4, (n) => progress.push(n))
    expect(offsets).toEqual(['0', '4', '2', '6'])
    expect(progress[progress.length - 1]).toBe(10)
  })

  it('não repete quando o servidor recusa o ficheiro (422)', async () => {
    chunk.mockRejectedValue(new AxiosError('x', '422', undefined, undefined, { status: 422, data: {} } as AxiosResponse))
    await expect(uploadInChunks('s', 'sql', new Blob(['ab']), 'a.txt', 4, () => {})).rejects.toBeTruthy()
    expect(chunk).toHaveBeenCalledTimes(1)
  })
})
