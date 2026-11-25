import { C, useSvgIds } from './palette'
import { SKY, dayPart, type Achievement } from './driverData'

/**
 * Cabeçalho do painel do condutor (Outubro 2026): a estrada com o céu da
 * hora do dia,nascer do sol, sol alto, pôr do sol ou noite estrelada,e
 * um camião KeruGás a passar (só quando o condutor está disponível).
 */
export function DriverSkyBanner({ hour, driving, className = '' }: { hour: number; driving: boolean; className?: string }) {
  const part = dayPart(hour)
  const sky = SKY[part]
  const id = useSvgIds('sky', 'glow')
  const sunY = part === 'afternoon' ? 26 : part === 'night' ? 30 : 58
  return (
    <svg viewBox="0 0 360 96" preserveAspectRatio="xMidYMid slice" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={id.sky} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor={sky.top} />
          <stop offset="1" stopColor={sky.bottom} />
        </linearGradient>
        <radialGradient id={id.glow} cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor={part === 'night' ? '#E2E8F0' : '#FFE08A'} stopOpacity="0.9" />
          <stop offset="1" stopColor={part === 'night' ? '#E2E8F0' : '#FFB020'} stopOpacity="0" />
        </radialGradient>
      </defs>
      <rect width="360" height="96" fill={`url(#${id.sky})`} />
      {part === 'night' &&
        [[24, 14], [70, 30], [110, 10], [160, 24], [210, 8], [250, 28], [300, 16], [340, 34], [190, 40]].map(([x, y], i) => (
          <circle key={i} cx={x} cy={y} r="1.2" fill="#fff" className="ill-twinkle" style={{ animationDelay: `${i * 0.3}s` }} />
        ))}
      <circle cx="290" cy={sunY} r="30" fill={`url(#${id.glow})`} />
      {part === 'night' ? (
        <path d="M284 18 a12 12 0 1 0 14 18 a10 10 0 1 1 -14 -18 z" fill="#F1F5F9" />
      ) : (
        <circle cx="290" cy={sunY} r="11" fill="#FFD166" />
      )}
      <path d="M0 70 Q60 46 120 64 T240 60 T360 62 V96 H0 Z" fill={part === 'night' ? '#0B1338' : '#11264B'} opacity="0.55" />
      <path d="M0 76 Q90 60 180 72 T360 70 V96 H0 Z" fill={part === 'night' ? '#020617' : '#172554'} opacity="0.75" />
      <rect y="80" width="360" height="16" fill={C.slate} />
      <g className="ill-scroll" opacity="0.85">
        {Array.from({ length: 14 }).map((_, i) => <rect key={i} x={i * 30} y="87" width="14" height="2" fill="#fff" />)}
      </g>
      {driving && (
        <g className="ill-drive">
          <g transform="translate(0 64)">
            <g className="ill-bump">
              <rect x="0" y="4" width="26" height="13" rx="2" fill={C.blue} />
              <rect x="3" y="0" width="5" height="6" rx="2" fill={C.fire} />
              <rect x="10" y="0" width="5" height="6" rx="2" fill={C.amber} />
              <rect x="17" y="0" width="5" height="6" rx="2" fill={C.fire} />
              <path d="M26 7 h8 l6 6 v4 h-14 z" fill={C.cyan} />
              <circle cx="7" cy="18" r="3.4" fill={C.navy} />
              <circle cx="32" cy="18" r="3.4" fill={C.navy} />
              {part === 'night' && <path d="M40 13 l18 -4 v10 z" fill="#FFE08A" opacity="0.5" />}
            </g>
          </g>
        </g>
      )}
    </svg>
  )
}

/** Sem entregas: o camião estacionado à sombra de uma árvore, a dormir. */
export function ParkedTruckArt({ className = '' }: { className?: string }) {
  return (
    <svg viewBox="0 0 200 110" className={className} aria-hidden="true">
      <ellipse cx="100" cy="100" rx="90" ry="6" fill={C.blue} opacity="0.1" />
      <rect x="40" y="48" width="8" height="50" rx="3" fill="#92400E" />
      <circle cx="44" cy="40" r="26" fill="#16A34A" />
      <circle cx="28" cy="50" r="16" fill="#15803D" />
      <circle cx="60" cy="50" r="16" fill="#22C55E" />
      <g transform="translate(70 62)">
        <rect x="0" y="6" width="58" height="26" rx="4" fill={C.blue} />
        <rect x="6" y="0" width="12" height="10" rx="3" fill={C.fire} />
        <rect x="21" y="0" width="12" height="10" rx="3" fill={C.amber} />
        <rect x="36" y="0" width="12" height="10" rx="3" fill={C.fire} />
        <text x="29" y="24" textAnchor="middle" fontSize="9" fontWeight="800" fill="#fff" fontFamily="system-ui, sans-serif">KG</text>
        <path d="M58 11 h16 l11 12 v9 h-27 z" fill={C.cyan} />
        <path d="M61 14 h11 l8 8 h-19 z" fill="#E0F2FE" />
        <circle cx="14" cy="34" r="6.5" fill={C.navy} />
        <circle cx="70" cy="34" r="6.5" fill={C.navy} />
        <circle cx="14" cy="34" r="2.5" fill="#CBD5E1" />
        <circle cx="70" cy="34" r="2.5" fill="#CBD5E1" />
      </g>
      <g className="ill-zzz" fill={C.blue} fontWeight="800" fontFamily="system-ui, sans-serif">
        <text x="150" y="58" fontSize="12">z</text>
        <text x="160" y="46" fontSize="15">z</text>
        <text x="172" y="32" fontSize="18">Z</text>
      </g>
    </svg>
  )
}

const TONES: Record<Achievement['tone'], [string, string]> = {
  bronze: ['#F59E0B', '#B45309'],
  silver: ['#E2E8F0', '#64748B'],
  gold: ['#FDE047', '#CA8A04'],
  fire: ['#FFB020', '#DC2626'],
  star: ['#9DB4D6', '#FC7201'],
}

/** Medalha de uma conquista (bloqueada = cinzenta com cadeado). */
export function Medal({ achievement, size = 56 }: { achievement: Achievement; size?: number }) {
  const id = useSvgIds('m')
  const [a, b] = TONES[achievement.tone]
  const n = achievement.label.split(' ')[0]
  return (
    <svg viewBox="0 0 60 64" width={size} height={size * 1.07} aria-hidden="true" className={achievement.unlocked ? '' : 'opacity-40 grayscale'}>
      <defs>
        <linearGradient id={id.m} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={a} />
          <stop offset="1" stopColor={b} />
        </linearGradient>
      </defs>
      <path d="M18 2 h10 l4 18 h-10 z" fill={C.blue} />
      <path d="M42 2 h-10 l-4 18 h10 z" fill={C.red} />
      <circle cx="30" cy="40" r="20" fill={`url(#${id.m})`} stroke="#fff" strokeWidth="2.5" />
      <circle cx="30" cy="40" r="14" fill="none" stroke="#fff" strokeOpacity="0.55" strokeWidth="1.5" />
      {achievement.tone === 'star' ? (
        <path d="M30 30 l3 6.5 7 .8 -5.2 4.8 1.5 7 -6.3 -3.6 -6.3 3.6 1.5 -7 -5.2 -4.8 7 -.8 z" fill="#fff" />
      ) : (
        <text x="30" y="45" textAnchor="middle" fontSize={n.length > 2 ? 11 : 15} fontWeight="900" fill="#fff" fontFamily="system-ui, sans-serif">
          {n}
        </text>
      )}
      {achievement.unlocked && achievement.tone !== 'bronze' && (
        <circle cx="44" cy="28" r="2" fill="#fff" className="ill-twinkle" />
      )}
      {!achievement.unlocked && (
        <g transform="translate(47 55)">
          <circle cx="0" cy="1" r="8" fill="#fff" />
          <rect x="-4.5" y="-0.5" width="9" height="7" rx="1.5" fill={C.navy} />
          <path d="M-2.6 -0.5 v-2.2 a2.6 2.6 0 0 1 5.2 0 v2.2" fill="none" stroke={C.navy} strokeWidth="1.6" />
        </g>
      )}
    </svg>
  )
}
