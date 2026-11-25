import axios from 'axios'
import { restoreService } from '@/services/restoreService'

const PHOTO_DIRS = ['avatars', 'community']
const PHOTO_EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif']

/**
 * Caminho de uma foto escolhida por pasta, a partir de "avatars/" ou
 * "community/" (igual ao servidor,RestoreService::photoPath). null = ignorar.
 *   "Cópia/fotos/avatars/ab.webp" → "avatars/ab.webp"
 */
export function photoRelativePath(path: string): string | null {
  const parts = path.replace(/\\/g, '/').split('/').filter((p) => p !== '' && p !== '.')
  let start = -1
  parts.forEach((p, i) => {
    if (PHOTO_DIRS.includes(p)) start = i
  })
  if (start < 0 || start === parts.length - 1) return null
  const rel = parts.slice(start).join('/')
  const ext = rel.split('.').pop()?.toLowerCase() ?? ''
  if (rel.includes('..') || !/^[A-Za-z0-9._/-]+$/.test(rel) || !PHOTO_EXT.includes(ext)) return null
  return rel
}

export type UploadKind = 'sql' | 'zip' | 'photo'

/**
 * Envia um ficheiro em partes. Se a ligação cair, tenta de novo (até 5 vezes
 * seguidas) e retoma exatamente onde o servidor ficou (resposta 409).
 */
export async function uploadInChunks(
  sessionId: string,
  kind: UploadKind,
  file: Blob,
  name: string,
  chunkBytes: number,
  onBytes: (sent: number) => void,
  signal?: { cancelled: boolean },
): Promise<void> {
  let offset = 0
  let failures = 0
  const size = file.size
  do {
    if (signal?.cancelled) throw new Error('cancelled')
    const end = Math.min(size, offset + chunkBytes)
    const form = new FormData()
    form.append('kind', kind)
    form.append('name', name)
    form.append('offset', String(offset))
    form.append('final', end >= size ? '1' : '0')
    form.append('chunk', file.slice(offset, end), 'chunk')
    try {
      const { received } = await restoreService.chunk(sessionId, form)
      offset = received
      failures = 0
      onBytes(offset)
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 409) {
        const received = (error.response.data as { data?: { received?: number } })?.data?.received
        if (typeof received === 'number') {
          offset = received
          onBytes(offset)
          continue
        }
      }
      // 422 = o servidor recusou o ficheiro: não adianta repetir.
      if (axios.isAxiosError(error) && error.response && error.response.status < 500 && error.response.status !== 429) throw error
      failures += 1
      if (failures > 5) throw error
      await new Promise((r) => setTimeout(r, 800 * failures))
    }
  } while (offset < size)
}
