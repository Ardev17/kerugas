import { useCallback, useEffect, useId, useRef, useState, type KeyboardEvent, type PointerEvent, type WheelEvent } from 'react'
import { createPortal } from 'react-dom'
import { ZoomIn, ZoomOut, X } from 'lucide-react'
import { clampCrop, displayScale, sourceRect, MAX_ZOOM, MIN_ZOOM, type CropState } from '@/lib/crop'

const VIEW_MAX = 288
const VIEW_MIN = 200

/** Lado do quadro de recorte: cabe no ecrã do telemóvel (margens + padding da janela). */
function fitView(): number {
  if (typeof window === 'undefined') return VIEW_MAX
  return Math.round(Math.max(VIEW_MIN, Math.min(VIEW_MAX, window.innerWidth - 80)))
}
const OUTPUT_MAX = 1024
const OUTPUT_MIN = 256

function canvasToBlob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality))
}

interface Props {
  file: File
  busy?: boolean
  error?: string | null
  onCancel: () => void
  onConfirm: (blob: Blob) => void
}

/**
 * Passo de enquadramento da foto de perfil (Outubro 2026): arrastar para
 * posicionar e zoom para aproximar, num círculo. O resultado é um quadrado
 * redesenhado num canvas (máx. 1024 px) — só essa versão é enviada, sem os
 * metadados da foto original (EXIF/GPS). O servidor re-codifica de novo.
 */
export function AvatarCropper({ file, busy = false, error, onCancel, onConfirm }: Props) {
  const titleId = useId()
  const [VIEW, setView] = useState(fitView)
  const imgRef = useRef<HTMLImageElement>(null)
  const drag = useRef<{ id: number; x: number; y: number; start: CropState } | null>(null)
  const [src, setSrc] = useState<string | null>(null)
  const [dims, setDims] = useState<{ w: number; h: number } | null>(null)
  const [state, setState] = useState<CropState>({ zoom: 1, x: 0, y: 0 })
  const [localError, setLocalError] = useState<string | null>(null)
  const [working, setWorking] = useState(false)

  useEffect(() => {
    const url = URL.createObjectURL(file)
    setSrc(url)
    setDims(null)
    setState({ zoom: 1, x: 0, y: 0 })
    setLocalError(null)
    return () => URL.revokeObjectURL(url)
  }, [file])

  useEffect(() => {
    const onResize = () => setView(fitView())
    window.addEventListener('resize', onResize)
    // Impede a página de fundo de deslocar enquanto a janela está aberta.
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('resize', onResize)
      document.body.style.overflow = prev
    }
  }, [])

  useEffect(() => {
    if (dims) setState((st) => clampCrop(dims.w, dims.h, VIEW, st))
  }, [VIEW, dims])

  useEffect(() => {
    const onKey = (e: globalThis.KeyboardEvent) => {
      if (e.key === 'Escape' && !busy) onCancel()
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [busy, onCancel])

  const update = useCallback(
    (next: CropState) => {
      if (dims) setState(clampCrop(dims.w, dims.h, VIEW, next))
    },
    [dims, VIEW],
  )

  const onLoad = () => {
    const img = imgRef.current
    if (!img) return
    if (img.naturalWidth < 64 || img.naturalHeight < 64) {
      setLocalError('A imagem é demasiado pequena (mínimo 64 × 64 px).')
      return
    }
    setDims({ w: img.naturalWidth, h: img.naturalHeight })
  }

  const onPointerDown = (e: PointerEvent<HTMLDivElement>) => {
    if (!dims) return
    e.currentTarget.setPointerCapture?.(e.pointerId)
    drag.current = { id: e.pointerId, x: e.clientX, y: e.clientY, start: state }
  }
  const onPointerMove = (e: PointerEvent<HTMLDivElement>) => {
    const d = drag.current
    if (!d || d.id !== e.pointerId) return
    update({ ...d.start, x: d.start.x + e.clientX - d.x, y: d.start.y + e.clientY - d.y })
  }
  const onPointerUp = () => {
    drag.current = null
  }
  const onWheel = (e: WheelEvent<HTMLDivElement>) => {
    update({ ...state, zoom: state.zoom * (e.deltaY < 0 ? 1.1 : 1 / 1.1) })
  }
  const onKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    const step = 12
    const moves: Record<string, Partial<CropState>> = {
      ArrowLeft: { x: state.x - step },
      ArrowRight: { x: state.x + step },
      ArrowUp: { y: state.y - step },
      ArrowDown: { y: state.y + step },
      '+': { zoom: state.zoom * 1.1 },
      '=': { zoom: state.zoom * 1.1 },
      '-': { zoom: state.zoom / 1.1 },
    }
    const move = moves[e.key]
    if (move) {
      e.preventDefault()
      update({ ...state, ...move })
    }
  }

  const confirm = async () => {
    const img = imgRef.current
    if (!img || !dims) return
    setWorking(true)
    setLocalError(null)
    try {
      const { sx, sy, size } = sourceRect(dims.w, dims.h, VIEW, state)
      const out = Math.round(Math.min(OUTPUT_MAX, Math.max(OUTPUT_MIN, size)))
      const canvas = document.createElement('canvas')
      canvas.width = out
      canvas.height = out
      const ctx = canvas.getContext('2d')
      if (!ctx) throw new Error('canvas')
      // Fundo branco: zonas transparentes de um PNG ficariam pretas no JPEG.
      ctx.fillStyle = '#ffffff'
      ctx.fillRect(0, 0, out, out)
      ctx.imageSmoothingEnabled = true
      ctx.imageSmoothingQuality = 'high'
      ctx.drawImage(img, sx, sy, size, size, 0, 0, out, out)

      // JPEG: todos os navegadores geram e qualquer servidor (GD) lê — o envio
      // não depende de o servidor ter suporte WebP. O servidor re-codifica.
      const blob = await canvasToBlob(canvas, 'image/jpeg', 0.9)
      if (!blob || blob.size === 0) throw new Error('blob')
      onConfirm(blob)
    } catch {
      setLocalError('Não foi possível preparar a foto neste dispositivo. Tente outra imagem.')
    } finally {
      setWorking(false)
    }
  }

  const s = dims ? displayScale(dims.w, dims.h, VIEW, state.zoom) : 0
  const shownError = localError ?? error ?? null

  // Portal para o <body>: um cartão da página com transform/backdrop-filter
  // prendia a janela dentro dele, e o resto da página ficava por cima.
  return createPortal(
    <div className="fixed inset-0 z-[1000] overflow-y-auto overscroll-contain bg-dark/60" role="presentation">
      <div className="flex min-h-full items-center justify-center p-4">
      <div role="dialog" aria-modal="true" aria-labelledby={titleId} className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
        <div className="mb-3 flex items-center justify-between">
          <h2 id={titleId} className="text-lg font-bold text-dark">Enquadrar a foto</h2>
          <button type="button" onClick={onCancel} disabled={busy} aria-label="Fechar" className="rounded-lg p-1 text-gray hover:bg-light disabled:opacity-50">
            <X size={18} />
          </button>
        </div>
        <p className="mb-3 text-sm text-gray">Arraste para posicionar e use o zoom para aproximar.</p>

        <div
          className="relative mx-auto touch-none select-none overflow-hidden rounded-xl bg-dark outline-none focus-visible:ring-2 focus-visible:ring-primary"
          style={{ width: VIEW, height: VIEW, cursor: dims ? 'grab' : 'default' }}
          tabIndex={0}
          role="group"
          aria-label="Área de recorte. Use as setas para mover e + ou − para o zoom."
          data-testid="crop-area"
          onPointerDown={onPointerDown}
          onPointerMove={onPointerMove}
          onPointerUp={onPointerUp}
          onPointerCancel={onPointerUp}
          onWheel={onWheel}
          onKeyDown={onKeyDown}
        >
          {src && (
            <img
              ref={imgRef}
              src={src}
              alt=""
              draggable={false}
              onLoad={onLoad}
              onError={() => setLocalError('Não foi possível abrir esta imagem. Use uma foto JPG, PNG ou WebP.')}
              className="pointer-events-none absolute max-w-none"
              style={
                dims
                  ? { width: dims.w * s, height: dims.h * s, left: VIEW / 2 - (dims.w * s) / 2 + state.x, top: VIEW / 2 - (dims.h * s) / 2 + state.y }
                  : { opacity: 0 }
              }
            />
          )}
          <div className="pointer-events-none absolute inset-3 rounded-full" style={{ boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.55)' }} aria-hidden />
        </div>

        <div className="mt-4 flex items-center gap-3">
          <ZoomOut size={16} className="text-gray" aria-hidden />
          <input
            type="range"
            min={MIN_ZOOM}
            max={MAX_ZOOM}
            step={0.01}
            value={state.zoom}
            disabled={!dims}
            onChange={(e) => update({ ...state, zoom: Number(e.target.value) })}
            aria-label="Zoom"
            className="flex-1 accent-primary"
          />
          <ZoomIn size={16} className="text-gray" aria-hidden />
        </div>

        {shownError && <p role="alert" className="mt-3 text-sm text-danger">{shownError}</p>}

        <div className="mt-5 flex justify-end gap-2">
          <button type="button" onClick={onCancel} disabled={busy} className="rounded-lg border border-gray/20 px-4 py-2 text-sm font-medium text-dark hover:bg-light disabled:opacity-50">
            Cancelar
          </button>
          <button
            type="button"
            onClick={() => void confirm()}
            disabled={!dims || busy || working}
            className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90 disabled:opacity-50"
          >
            {busy || working ? 'A guardar...' : 'Guardar foto'}
          </button>
        </div>
      </div>
      </div>
    </div>,
    document.body,
  )
}
