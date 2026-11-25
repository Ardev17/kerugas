import { useEffect, useState, type KeyboardEvent, type MouseEvent } from 'react'
import { AvatarViewer } from '@/components/AvatarViewer'
import { initialsOf, safeAvatarUrl } from '@/lib/avatar'
import { useApiImage } from '@/lib/apiImage'

const SIZES = {
  xs: 'h-6 w-6 text-[10px]',
  sm: 'h-8 w-8 text-xs',
  md: 'h-10 w-10 text-sm',
  lg: 'h-14 w-14 text-xl',
  xl: 'h-24 w-24 text-3xl',
} as const

interface Props {
  name: string | null | undefined
  url?: string | null
  size?: keyof typeof SIZES
  className?: string
  /** Texto alternativo; por omissão a foto é decorativa (o nome já aparece ao lado). */
  alt?: string
  /** Tocar na foto abre-a em ecrã inteiro (Outubro 2026). Desligar onde a foto é um link (ex.: cabeçalho). */
  zoomable?: boolean
}

/**
 * Foto de perfil (Outubro 2026), com as iniciais como alternativa quando
 * não há foto ou a imagem não carrega.
 */
export function Avatar({ name, url, size = 'md', className = '', alt, zoomable = true }: Props) {
  // Pelo endereço da API (e, no app pelo túnel ngrok, descarregada à parte),ver lib/apiImage.
  const image$ = useApiImage(safeAvatarUrl(url))
  const src = image$.failed ? null : image$.src
  const [failed, setFailed] = useState(false)
  const [viewing, setViewing] = useState(false)

  useEffect(() => setFailed(false), [src])

  const base = `inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full ${SIZES[size]} ${className}`

  if (src && !failed) {
    const image = (
      <img
        src={src}
        alt={alt ?? ''}
        aria-hidden={alt ? undefined : true}
        loading="lazy"
        decoding="async"
        referrerPolicy="no-referrer"
        draggable={false}
        onError={() => setFailed(true)}
        className={`${base} bg-light object-cover`}
      />
    )
    if (!zoomable) return image

    // Muitas fotos estão dentro de botões/links (ex.: cartão do condutor que
    // expande): o toque na foto abre-a e não ativa o que está à volta. É um
    // <span role="button"> porque um <button> dentro de outro é inválido.
    const open = (e: MouseEvent | KeyboardEvent) => {
      e.preventDefault()
      e.stopPropagation()
      setViewing(true)
    }
    return (
      <>
        <span
          role="button"
          tabIndex={0}
          aria-label={`Ver a foto de ${name ?? 'utilizador'} em ecrã inteiro`}
          onClick={open}
          onKeyDown={(e) => {
            if (e.key === 'Enter' || e.key === ' ') open(e)
          }}
          className="inline-flex shrink-0 cursor-zoom-in rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"
        >
          {image}
        </span>
        {viewing && <AvatarViewer src={src} name={name ?? 'Utilizador'} onClose={() => setViewing(false)} />}
      </>
    )
  }

  return (
    <span className={`${base} bg-primary/10 font-bold text-primary`} aria-hidden={alt ? undefined : true} role={alt ? 'img' : undefined} aria-label={alt}>
      {initialsOf(name)}
    </span>
  )
}
