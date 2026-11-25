import { useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'
import { X } from 'lucide-react'
import { useBackHandler } from '@/native/backButton'
import { useApiImage } from '@/lib/apiImage'

/**
 * Foto de perfil em ecrã inteiro (Outubro 2026). Fecha com o ✕, tocando
 * fora da foto, com Escape ou com o botão voltar do Android. Fica num
 * portal no <body> para nunca ser cortada por cartões ou menus.
 */
export function AvatarViewer({ src, name, onClose, shape = 'square' }: { src: string; name: string; onClose: () => void; shape?: 'square' | 'photo' }) {
  useBackHandler(true, onClose)
  const image = useApiImage(src)
  const close = useRef(onClose)
  close.current = onClose

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') close.current()
    }
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = previous
    }
  }, [])

  return createPortal(
    <div
      role="dialog"
      aria-modal="true"
      aria-label={`Foto de ${name}`}
      className="fixed inset-0 z-[3000] flex flex-col items-center justify-center bg-black/90 p-4"
      style={{ height: '100dvh' }}
      onClick={onClose}
    >
      <button
        type="button"
        onClick={onClose}
        aria-label="Fechar foto"
        className="absolute right-4 top-4 rounded-full bg-white/10 p-2 text-white hover:bg-white/20"
        style={{ top: 'max(1rem, env(safe-area-inset-top))' }}
      >
        <X size={22} />
      </button>
      {image.src ? (
        <img
          src={image.src}
          alt={`Foto de ${name}`}
          referrerPolicy="no-referrer"
          draggable={false}
          onClick={(e) => e.stopPropagation()}
          className={
            shape === 'photo'
              ? 'max-h-[80dvh] w-auto max-w-[90vw] rounded-2xl object-contain shadow-2xl' // foto inteira, sem cortar
              : 'aspect-square w-full max-w-[min(90vw,80dvh)] rounded-2xl object-cover shadow-2xl'
          }
        />
      ) : (
        <p className="text-sm text-white/80">{image.failed ? 'Não foi possível abrir a foto.' : 'A carregar a foto...'}</p>
      )}
      <p className="mt-4 text-center text-base font-semibold text-white">{name}</p>
    </div>,
    document.body,
  )
}
