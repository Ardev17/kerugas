import { motionLevel } from '@/lib/motion'

const COLORS = ['#FC7201', '#FFB020', '#FF6B1A', '#FFB020', '#22C55E', '#A855F7', '#FFFFFF']

interface Piece {
  x: number
  y: number
  vx: number
  vy: number
  rot: number
  vr: number
  w: number
  h: number
  color: string
  spark: boolean
  life: number
}

/**
 * Festa no ecrã (Outubro 2026): confetes e faíscas disparados de dois
 * canhões nos cantos de baixo + uma explosão no centro. Um canvas
 * temporário, por cima de tudo, que se apaga sozinho (~3,5 s). Não faz
 * nada com "reduzir movimento". Devolve uma função para cancelar.
 */
export function celebrate(options: { origin?: { x: number; y: number } } = {}): () => void {
  if (typeof document === 'undefined') return () => {}
  const level = motionLevel()
  if (level === 'off') return () => {}

  const canvas = document.createElement('canvas')
  canvas.setAttribute('aria-hidden', 'true')
  canvas.className = 'fx-celebrate'
  document.body.appendChild(canvas)
  const ctx = canvas.getContext('2d')
  if (!ctx) {
    canvas.remove()
    return () => {}
  }

  const dpr = Math.min(window.devicePixelRatio || 1, level === 'full' ? 1.5 : 1)
  const w = window.innerWidth
  const h = window.innerHeight
  canvas.width = w * dpr
  canvas.height = h * dpr
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0)

  const pieces: Piece[] = []
  const add = (x: number, y: number, angle: number, spread: number, n: number, power: number) => {
    for (let i = 0; i < n; i++) {
      const a = angle + (Math.random() - 0.5) * spread
      const v = power * (0.55 + Math.random() * 0.6)
      const spark = Math.random() < 0.3
      pieces.push({
        x,
        y,
        vx: Math.cos(a) * v,
        vy: Math.sin(a) * v,
        rot: Math.random() * Math.PI,
        vr: (Math.random() - 0.5) * 0.4,
        w: spark ? 3 : 6 + Math.random() * 6,
        h: spark ? 3 : 8 + Math.random() * 8,
        color: COLORS[Math.floor(Math.random() * COLORS.length)],
        spark,
        life: 0,
      })
    }
  }
  const n = level === 'full' ? 90 : 40
  add(0, h, -Math.PI / 3, 0.7, n, 22)
  add(w, h, (-Math.PI * 2) / 3, 0.7, n, 22)
  const o = options.origin ?? { x: w / 2, y: h * 0.38 }
  add(o.x, o.y, -Math.PI / 2, Math.PI * 2, Math.round(n * 0.8), 12)

  let raf = 0
  let last = performance.now()
  const started = last
  const tick = (now: number) => {
    const dt = Math.min(2.5, (now - last) / 16.67)
    last = now
    ctx.clearRect(0, 0, w, h)
    for (const p of pieces) {
      p.life += dt
      p.vx *= 0.985
      p.vy = p.vy * 0.985 + 0.38 * dt
      p.x += p.vx * dt
      p.y += p.vy * dt
      p.rot += p.vr * dt
      const fade = Math.max(0, 1 - (now - started) / 3500)
      ctx.save()
      ctx.globalAlpha = fade
      ctx.translate(p.x, p.y)
      if (p.spark) {
        ctx.globalCompositeOperation = 'lighter'
        ctx.fillStyle = p.color
        ctx.shadowColor = p.color
        ctx.shadowBlur = 8
        ctx.beginPath()
        ctx.arc(0, 0, p.w, 0, Math.PI * 2)
        ctx.fill()
      } else {
        ctx.rotate(p.rot)
        // "Vira" o confete para parecer papel a cair.
        ctx.scale(1, Math.cos(p.life / 6))
        ctx.fillStyle = p.color
        ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h)
      }
      ctx.restore()
    }
    if (now - started < 3600) raf = requestAnimationFrame(tick)
    else canvas.remove()
  }
  raf = requestAnimationFrame(tick)

  return () => {
    cancelAnimationFrame(raf)
    canvas.remove()
  }
}
