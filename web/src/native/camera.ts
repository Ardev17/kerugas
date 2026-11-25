import { hasNativePlugin } from '@/native/platform'

/**
 * Foto de perfil no app Android (Outubro 2026): tirar uma foto com a câmara
 * ou escolher da galeria (seletor de fotos do sistema,sem permissões
 * extra, e nada é gravado na galeria). O resultado segue para o mesmo ecrã
 * de recorte do site; o servidor valida e re-codifica como sempre.
 */
export const canUseNativeCamera = () => hasNativePlugin('Camera')

const OPTIONS = {
  quality: 90,
  targetWidth: 2048,
  targetHeight: 2048,
  correctOrientation: true,
  editable: 'no' as const,
  includeMetadata: false,
}

function isCancel(error: unknown): boolean {
  const message = (error as { message?: string })?.message ?? ''
  return /cancel/i.test(message)
}

async function toFile(webPath: string | undefined): Promise<File | null> {
  if (!webPath) return null
  const blob = await (await fetch(webPath)).blob()
  const type = blob.type || 'image/jpeg'
  const ext = type === 'image/png' ? 'png' : type === 'image/webp' ? 'webp' : 'jpg'
  return new File([blob], `foto.${ext}`, { type })
}

/** Devolve a foto escolhida, ou null se a pessoa cancelou. */
export async function pickNativePhoto(source: 'camera' | 'gallery'): Promise<File | null> {
  const { Camera, EncodingType, MediaTypeSelection } = await import('@capacitor/camera')
  try {
    if (source === 'camera') {
      const photo = await Camera.takePhoto({ ...OPTIONS, encodingType: EncodingType.JPEG, saveToGallery: false })
      return await toFile(photo.webPath)
    }
    const picked = await Camera.chooseFromGallery({ ...OPTIONS, mediaType: MediaTypeSelection.Photo, allowMultipleSelection: false, limit: 1 })
    return await toFile(picked.results[0]?.webPath)
  } catch (error) {
    if (isCancel(error)) return null
    throw error
  }
}
