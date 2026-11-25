import { useEffect, useRef } from 'react'
import { motionLevel } from '@/lib/motion'

interface Props {
  className?: string
  /** Densidade relativa (1 = normal). */
  density?: number
  /** Um toque/clique na zona solta uma explosão de faíscas. */
  interactive?: boolean
}

interface Spark {
  x: number
  y: number
  vx: number
  vy: number
  life: number
  max: number
  r: number
  hue: number
  burst: boolean
}

// Cores de brasa real: vermelho, laranja e amarelo.
const HUES = [4, 12, 20, 28, 36, 44]

/**
 * Brasas e faíscas a subir (Outubro 2026). Canvas leve:
 * - para quando sai do ecrã ou o separador fica escondido;
 * - `full` ≈ 70 brasas, `lite` ≈ 22, `off` não desenha nada;
 * - resolução limitada (1,5× / 1×) para não aquecer o telemóvel.
 */
export function EmberField({ className = '', density = 1, interactive = true }: Props) {
  const ref = useRef<HTMLCanvasElement>(null)

  useEffect(() => {
    const canvas = ref.current
    const level = motionLevel()
    if (!canvas || level === 'off') return
    const ctx = canvas.getContext('2d')
    if (!ctx) return

    const dpr = Math.min(window.devicePixelRatio || 1, level === 'full' ? 1.5 : 1)
    const target = Math.round((level === 'full' ? 70 : 22) * density)
    const sparks: Spark[] = []
    let w = 0
    let h = 0
    let raf = 0
    let visible = true
    let last = performance.now()

    const resize = () => {
      const rect = canvas.getBoundingClientRect()
      w = rect.width
      h = rect.height
      canvas.width = Math.max(1, Math.round(w * dpr))
      canvas.height = Math.max(1, Math.round(h * dpr))
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    }

    const spawn = (atBottom: boolean): Spark => ({
      x: Math.random() * w,
      y: atBottom ? h + 10 : Math.random() * h,
      vx: (Math.random() - 0.5) * 0.25,
      vy: -(0.25 + Math.random() * 0.7),
      life: 0,
      max: 260 + Math.random() * 320,
      r: 0.8 + Math.random() * 2.2,
      hue: HUES[Math.floor(Math.random() * HUES.length)],
      burst: false,
    })

    const burst = (x: number, y: number) => {
      const n = level === 'full' ? 34 : 14
      for (let i = 0; i < n; i++) {
        const a = (Math.PI * 2 * i) / n + Math.random() * 0.4
        const speed = 1.2 + Math.random() * 3.2
        sparks.push({ x, y, vx: Math.cos(a) * speed, vy: Math.sin(a) * speed - 1, life: 0, max: 50 + Math.random() * 50, r: 1 + Math.random() * 2, hue: HUES[i % HUES.length], burst: true })
      }
    }

    const frame = (now: number) => {
      raf = 0
      if (!visible) return
      const dt = Math.min(3, (now - last) / 16.67)
      last = now
      ctx.clearRect(0, 0, w, h)
      ctx.globalCompositeOperation = 'lighter'

      let ambient = 0
      for (let i = sparks.length - 1; i >= 0; i--) {
        const s = sparks[i]
        s.life += dt
        if (s.burst) {
          s.vx *= 0.96
          s.vy = s.vy * 0.96 + 0.04 * dt
        } else {
          ambient++
          s.vx += Math.sin((s.life + i * 13) / 40) * 0.006 * dt
        }
        s.x += s.vx * dt
        s.y += s.vy * dt
        const t = s.life / s.max
        if (t >= 1 || s.y < -20) {
          sparks.splice(i, 1)
          continue
        }
        const alpha = t < 0.15 ? t / 0.15 : 1 - (t - 0.15) / 0.85
        const glow = s.r * 4
        const g = ctx.createRadialGradient(s.x, s.y, 0, s.x, s.y, glow)
        g.addColorStop(0, `hsla(${s.hue}, 100%, 70%, ${0.9 * alpha})`)
        g.addColorStop(0.35, `hsla(${s.hue}, 100%, 55%, ${0.35 * alpha})`)
        g.addColorStop(1, `hsla(${s.hue}, 100%, 50%, 0)`)
        ctx.fillStyle = g
        ctx.beginPath()
        ctx.arc(s.x, s.y, glow, 0, Math.PI * 2)
        ctx.fill()
      }
      while (ambient < target) {
        sparks.push(spawn(sparks.length > target / 2))
        ambient++
      }
      raf = requestAnimationFrame(frame)
    }

    const start = () => {
      if (!raf && visible && !document.hidden) {
        last = performance.now()
        raf = requestAnimationFrame(frame)
      }
    }
    const stop = () => {
      if (raf) cancelAnimationFrame(raf)
      raf = 0
    }

    resize()
    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(resize) : null
    ro?.observe(canvas)
    const io = typeof IntersectionObserver !== 'undefined'
      ? new IntersectionObserver(([entry]) => {
          visible = entry.isIntersecting
          if (visible) start()
          else stop()
        })
      : null
    io?.observe(canvas)
    const onVisibility = () => (document.hidden ? stop() : start())
    document.addEventListener('visibilitychange', onVisibility)

    const host = canvas.parentElement
    const onDown = (e: PointerEvent) => {
      // Só dentro da zona e nunca por cima de um botão/link (não rouba o clique).
      if ((e.target as HTMLElement | null)?.closest('a,button,input,select,textarea,label')) return
      const rect = canvas.getBoundingClientRect()
      burst(e.clientX - rect.left, e.clientY - rect.top)
      start()
    }
    if (interactive) host?.addEventListener('pointerdown', onDown)

    start()
    return () => {
      stop()
      ro?.disconnect()
      io?.disconnect()
      document.removeEventListener('visibilitychange', onVisibility)
      host?.removeEventListener('pointerdown', onDown)
    }
  }, [density, interactive])

  return <canvas ref={ref} aria-hidden="true" className={`pointer-events-none absolute inset-0 h-full w-full ${className}`} />
}
