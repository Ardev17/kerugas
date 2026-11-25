import { Cylinder, CylinderGradient } from './parts'
import { C, useSvgIds } from './palette'

/**
 * Boas-vindas dos Vizinhos (Outubro 2026): um bairro ao entardecer, janelas
 * acesas, uma botija à porta e balões de conversa a nascer sobre as casas.
 */
export function NeighborhoodWelcomeArt({ className = '' }: { className?: string }) {
  const id = useSvgIds('sky', 'sun', 'cyl', 'ground')
  const houses = [
    { x: 18, w: 56, h: 52, roof: 'flat', color: '#11264B' },
    { x: 82, w: 62, h: 70, roof: 'peak', color: '#11264B' },
    { x: 152, w: 54, h: 46, roof: 'flat', color: '#1E40AF' },
    { x: 214, w: 60, h: 64, roof: 'peak', color: '#1E3A6B' },
    { x: 282, w: 26, h: 40, roof: 'flat', color: '#11264B' },
  ]
  const bubbles = [
    { x: 48, y: 40, d: '0s', text: 'Há gás?' },
    { x: 178, y: 52, d: '1.5s', text: 'Há sim!' },
    { x: 244, y: 30, d: '3s', text: 'Obrigado!' },
  ]
  return (
    <svg viewBox="0 0 320 170" className={className} role="img" aria-label="Ilustração de um bairro com vizinhos a conversar">
      <defs>
        <linearGradient id={id.sky} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#1E1B4B" />
          <stop offset="0.55" stopColor="#FC7201" />
          <stop offset="1" stopColor="#FB923C" />
        </linearGradient>
        <radialGradient id={id.sun} cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor="#FFE08A" />
          <stop offset="1" stopColor="#FFB020" stopOpacity="0" />
        </radialGradient>
        <linearGradient id={id.ground} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor="#0F172A" />
          <stop offset="1" stopColor="#020617" />
        </linearGradient>
        <CylinderGradient id={id.cyl} />
      </defs>
      <rect width="320" height="170" rx="18" fill={`url(#${id.sky})`} />
      <circle cx="160" cy="132" r="64" fill={`url(#${id.sun})`} className="ill-blink" />
      <circle cx="160" cy="132" r="24" fill="#FFD166" opacity="0.9" />
      {[[30, 18], [70, 30], [120, 14], [260, 20], [296, 40], [210, 12]].map(([x, y], i) => (
        <circle key={i} cx={x} cy={y} r="1.3" fill="#fff" className="ill-twinkle" style={{ animationDelay: `${i * 0.4}s` }} />
      ))}
      {houses.map((h, i) => (
        <g key={i}>
          <rect x={h.x} y={140 - h.h} width={h.w} height={h.h} fill={h.color} />
          {h.roof === 'peak' && <path d={`M${h.x - 4} ${140 - h.h} L${h.x + h.w / 2} ${140 - h.h - 18} L${h.x + h.w + 4} ${140 - h.h} Z`} fill="#172554" />}
          {h.roof === 'flat' && <rect x={h.x - 3} y={140 - h.h - 4} width={h.w + 6} height="5" fill="#172554" />}
          {Array.from({ length: Math.max(1, Math.floor(h.w / 22)) }).map((_, j) => (
            <rect key={j} x={h.x + 8 + j * 22} y={140 - h.h + 12} width="10" height="10" rx="1.5" fill="#FFD166" className="ill-blink" style={{ animationDelay: `${(i + j) * 0.7}s` }} />
          ))}
          {h.w > 40 && <rect x={h.x + h.w - 18} y={118} width="11" height="22" rx="1.5" fill="#0B1338" />}
        </g>
      ))}
      <Cylinder x={136} y={140} s={0.48} grad={id.cyl} />
      <rect y="138" width="320" height="32" rx="0" fill={`url(#${id.ground})`} />
      <rect y="140" width="320" height="2" fill={C.fire} opacity="0.6" />
      {bubbles.map((b) => (
        <g key={b.text} className="ill-pop" style={{ animationDelay: b.d }}>
          <rect x={b.x - 30} y={b.y - 14} width="60" height="22" rx="11" fill="#fff" />
          <path d={`M${b.x - 4} ${b.y + 7} l4 7 l4 -7 z`} fill="#fff" />
          <text x={b.x} y={b.y + 1} textAnchor="middle" fontSize="10" fontWeight="700" fill={C.navy} fontFamily="system-ui, sans-serif">
            {b.text}
          </text>
        </g>
      ))}
    </svg>
  )
}

/** Conversa vazia: duas casas ligadas por uma linha a correr e um balão "…" no meio. */
export function EmptyChatArt({ className = '' }: { className?: string }) {
  const house = (x: number, color: string) => (
    <g>
      <path d={`M${x} 70 L${x + 22} 50 L${x + 44} 70 Z`} fill="#11264B" />
      <rect x={x + 4} y="70" width="36" height="30" rx="2" fill={color} />
      <rect x={x + 10} y="78" width="9" height="9" rx="1.5" fill="#FFD166" className="ill-blink" />
      <rect x={x + 25} y="84" width="9" height="16" rx="1.5" fill="#0B1338" />
    </g>
  )
  return (
    <svg viewBox="0 0 240 110" className={className} aria-hidden="true">
      <ellipse cx="120" cy="102" rx="110" ry="5" fill={C.blue} opacity="0.1" />
      {house(14, C.blue)}
      {house(182, '#1E3A6B')}
      <path d="M62 66 Q120 6 180 66" fill="none" stroke={C.cyan} strokeWidth="2.5" strokeLinecap="round" className="ill-dash" />
      <g className="ill-float">
        <rect x="96" y="20" width="48" height="26" rx="13" fill={C.fire} />
        <path d="M116 45 l4 7 l4 -7 z" fill={C.fire} />
        <g className="ill-dots" fill="#fff">
          <circle cx="110" cy="33" r="3" />
          <circle cx="120" cy="33" r="3" />
          <circle cx="130" cy="33" r="3" />
        </g>
      </g>
    </svg>
  )
}

/** Megafone com ondas,cabeçalho dos avisos fixados. */
export function MegaphoneArt({ size = 22, className = '' }: { size?: number; className?: string }) {
  return (
    <svg viewBox="0 0 32 24" width={size * 1.33} height={size} className={className} aria-hidden="true">
      <path d="M3 9 h5 l11 -6 v18 l-11 -6 h-5 a2 2 0 0 1 -2 -2 v-2 a2 2 0 0 1 2 -2 z" fill={C.fire} />
      <rect x="6" y="15" width="4" height="7" rx="1.5" fill="#B45309" />
      <path d="M23 8 q3 4 0 8" fill="none" stroke={C.amber} strokeWidth="2" strokeLinecap="round" className="ill-wave" />
      <path d="M26 5 q5 7 0 14" fill="none" stroke={C.amber} strokeWidth="2" strokeLinecap="round" className="ill-wave ill-wave-2" />
    </svg>
  )
}
