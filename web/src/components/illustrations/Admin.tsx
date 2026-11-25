import { Cylinder, CylinderGradient } from './parts'
import { C, useSvgIds } from './palette'

// ---------------------------------------------------------------- mapa de Angola

/**
 * Contorno simplificado de Angola (longitude, latitude),desenho estilizado,
 * não cartografia oficial. Inclui o enclave de Cabinda.
 */
const ANGOLA: [number, number][] = [
  [12.25, -6.1], [12.9, -5.95], [13.45, -5.88], [16.3, -5.9], [16.6, -6.1], [17.2, -7.2], [17.6, -8.1], [18.5, -8.0],
  [19.4, -7.6], [19.5, -7.0], [20.3, -6.95], [20.6, -7.3], [21.8, -7.3], [21.9, -8.6], [21.75, -9.6], [22.3, -11.0],
  [23.9, -10.9], [24.05, -11.6], [23.95, -12.95], [22.0, -13.0], [22.0, -16.2], [23.4, -17.6], [21.4, -18.0],
  [18.9, -17.8], [16.0, -17.4], [13.5, -16.95], [11.75, -17.25], [11.8, -16.6], [12.15, -15.85], [12.35, -14.6],
  [12.55, -13.65], [13.55, -12.3], [13.85, -11.0], [13.25, -9.8], [13.05, -8.7], [12.85, -7.6], [12.35, -6.6],
]
const CABINDA: [number, number][] = [
  [12.2, -5.77], [12.55, -5.7], [12.85, -5.05], [13.1, -4.72], [12.65, -4.42], [12.05, -4.42], [11.8, -4.95], [12.0, -5.6],
]

const LON0 = 11.4
const LAT0 = -4.2
const K = 13 // píxeis por grau

function project(lon: number, lat: number): [number, number] {
  return [(lon - LON0) * K, (LAT0 - lat) * K]
}

const toPath = (pts: [number, number][]) =>
  pts.map(([lon, lat], i) => {
    const [x, y] = project(lon, lat)
    return `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`
  }).join(' ') + ' Z'

export interface MapPoint {
  id: number
  latitude: number
  longitude: number
  gas_available: boolean
}

/**
 * Mapa de Angola com os postos REAIS (Outubro 2026): cada ponto é um posto;
 * os que têm gás pulsam a verde. Cabeçalho do painel admin.
 */
export function AngolaMapArt({ points, className = '' }: { points: MapPoint[]; className?: string }) {
  const id = useSvgIds('land', 'glow')
  const visible = points.filter((p) => p.latitude <= -4 && p.latitude >= -18.5 && p.longitude >= 11.5 && p.longitude <= 24.5)
  return (
    <svg viewBox="0 0 176 190" className={className} role="img" aria-label={`Mapa de Angola com ${visible.length} postos`}>
      <defs>
        <linearGradient id={id.land} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor={C.blue} stopOpacity="0.35" />
          <stop offset="1" stopColor={C.cyan} stopOpacity="0.18" />
        </linearGradient>
        <filter id={id.glow} x="-20%" y="-20%" width="140%" height="140%">
          <feGaussianBlur stdDeviation="2.5" />
        </filter>
      </defs>
      <path d={toPath(ANGOLA)} fill={C.blue} opacity="0.35" filter={`url(#${id.glow})`} />
      <path d={toPath(ANGOLA)} fill={`url(#${id.land})`} stroke={C.cyan} strokeWidth="1.2" strokeLinejoin="round" />
      <path d={toPath(CABINDA)} fill={`url(#${id.land})`} stroke={C.cyan} strokeWidth="1.2" strokeLinejoin="round" />
      {visible.map((p) => {
        const [x, y] = project(p.longitude, p.latitude)
        const color = p.gas_available ? '#22C55E' : '#94A3B8'
        return (
          <g key={p.id}>
            {p.gas_available && <circle cx={x} cy={y} r="4" fill={color} opacity="0.5" className="ill-pin" style={{ animationDelay: `${(p.id % 7) * 0.3}s` }} />}
            <circle cx={x} cy={y} r="2.2" fill={color} stroke="#fff" strokeWidth="0.8" />
          </g>
        )
      })}
    </svg>
  )
}

// ---------------------------------------------------------------- ecrãs vazios / erro

/**
 * Ecrãs vazios (Outubro 2026): `clear` = escudo com visto ("tudo limpo",
 * ex.: sem denúncias); `inbox` = tabuleiro vazio com brilhos (nada pendente).
 */
export function EmptyArt({ variant = 'inbox', className = '' }: { variant?: 'inbox' | 'clear'; className?: string }) {
  return (
    <svg viewBox="0 0 160 110" className={className} aria-hidden="true">
      <ellipse cx="80" cy="100" rx="56" ry="6" fill={C.blue} opacity="0.1" />
      {variant === 'clear' ? (
        <g className="ill-float">
          <path d="M80 14 l34 12 v26 c0 22 -15 36 -34 44 c-19 -8 -34 -22 -34 -44 v-26 z" fill={C.green} />
          <path d="M80 22 l26 9 v21 c0 17 -11 28 -26 34 c-15 -6 -26 -17 -26 -34 v-21 z" fill="#4ADE80" />
          <path d="M67 54 l9 9 l18 -19" fill="none" stroke="#fff" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" />
        </g>
      ) : (
        <g className="ill-float">
          <path d="M40 58 l12 -30 h56 l12 30 v28 a6 6 0 0 1 -6 6 h-68 a6 6 0 0 1 -6 -6 z" fill="#CBD5E1" />
          <path d="M40 58 h26 a14 8 0 0 0 28 0 h26 v28 a6 6 0 0 1 -6 6 h-68 a6 6 0 0 1 -6 -6 z" fill="#94A3B8" />
          <path d="M52 28 h56 l12 30 h-26 a14 8 0 0 1 -28 0 h-26 z" fill="#E2E8F0" opacity="0.6" />
        </g>
      )}
      {[[28, 26], [132, 20], [138, 64], [22, 70]].map(([x, y], i) => (
        <path key={i} d={`M${x} ${y - 6} l1.6 4.4 4.4 1.6 -4.4 1.6 -1.6 4.4 -1.6 -4.4 -4.4 -1.6 4.4 -1.6 z`} fill={i % 2 ? C.amber : C.cyan} className="ill-twinkle" style={{ animationDelay: `${i * 0.5}s` }} />
      ))}
    </svg>
  )
}

/** "Algo correu mal": a botija com uma chave inglesa,estamos a tratar disso. */
export function ErrorArt({ className = '' }: { className?: string }) {
  const id = useSvgIds('cyl')
  return (
    <svg viewBox="0 0 160 120" className={className} aria-hidden="true">
      <defs>
        <CylinderGradient id={id.cyl} />
      </defs>
      <ellipse cx="80" cy="108" rx="50" ry="6" fill={C.red} opacity="0.12" />
      <g transform="rotate(-12 70 104)">
        <Cylinder x={70} y={106} s={1.7} grad={id.cyl} />
      </g>
      <g className="ill-shake">
        <path
          d="M104 40 a12 12 0 0 1 16 -12 l-7 7 2 6 6 2 7 -7 a12 12 0 0 1 -12 16 l-22 22 a5 5 0 0 1 -7 -7 z"
          fill="#64748B"
        />
      </g>
      <g fill={C.amber} className="ill-twinkle">
        <path d="M44 28 l3 8 -6 -4 z" />
        <path d="M120 82 l7 2 -6 3 z" />
      </g>
    </svg>
  )
}

// ---------------------------------------------------------------- cofre (cópias de segurança)

/** Cofre com a chama da marca: o disco gira enquanto uma cópia está a ser feita. */
export function VaultArt({ busy, className = '' }: { busy: boolean; className?: string }) {
  const id = useSvgIds('body', 'flame')
  return (
    <svg viewBox="0 0 120 110" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={id.body} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#334155" />
          <stop offset="1" stopColor="#0F172A" />
        </linearGradient>
        <linearGradient id={id.flame} x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stopColor="#FF7A00" />
          <stop offset="1" stopColor="#C81E0A" />
        </linearGradient>
      </defs>
      <ellipse cx="60" cy="102" rx="44" ry="5" fill={C.blue} opacity="0.15" />
      <rect x="14" y="10" width="92" height="86" rx="12" fill={`url(#${id.body})`} />
      <rect x="22" y="18" width="76" height="70" rx="8" fill="none" stroke="#475569" strokeWidth="2" />
      {busy && <circle cx="60" cy="53" r="30" fill={C.cyan} opacity="0.25" className="ill-ring" />}
      <g className={busy ? 'ill-spin' : ''}>
        <circle cx="60" cy="53" r="20" fill="#1E293B" stroke={busy ? C.cyan : '#64748B'} strokeWidth="3" />
        {[0, 45, 90, 135, 180, 225, 270, 315].map((a) => (
          <rect key={a} x="59" y="35" width="2" height="5" fill="#94A3B8" transform={`rotate(${a} 60 53)`} />
        ))}
      </g>
      <path d="M60 41 c4 6 9 9 9 16 a9 9 0 0 1 -18 0 c0 -4 2 -6 3 -8 1 3 2 4 4 4 -1 -5 0 -9 2 -12 z" fill={`url(#${id.flame})`} />
      <rect x="24" y="92" width="12" height="6" rx="2" fill="#334155" />
      <rect x="84" y="92" width="12" height="6" rx="2" fill="#334155" />
      <rect x="98" y="44" width="6" height="18" rx="3" fill="#94A3B8" />
    </svg>
  )
}

// ---------------------------------------------------------------- 404

/** Página não encontrada: uma botija perdida no mapa, com um ponto de interrogação. */
export function LostCylinderArt({ className = '' }: { className?: string }) {
  const id = useSvgIds('cyl')
  return (
    <svg viewBox="0 0 240 170" className={className} aria-hidden="true">
      <defs>
        <CylinderGradient id={id.cyl} />
      </defs>
      <path d="M20 40 l60 -20 l80 20 l60 -20 v110 l-60 20 l-80 -20 l-60 20 z" fill={C.blue} opacity="0.12" />
      <path d="M80 20 v110 M160 40 v110" stroke={C.blue} strokeOpacity="0.2" strokeWidth="2" />
      <path d="M40 120 C70 90 60 60 100 70 S150 40 190 60" fill="none" stroke={C.fire} strokeWidth="3" strokeLinecap="round" className="ill-dash" />
      <circle cx="40" cy="120" r="5" fill={C.fire} />
      <g className="ill-float">
        <Cylinder x={120} y={130} s={1.25} grad={id.cyl} />
      </g>
      <g className="ill-pop" style={{ animationDuration: '3.6s' }}>
        <circle cx="160" cy="62" r="20" fill="#fff" stroke={C.blue} strokeWidth="3" />
        <text x="160" y="72" textAnchor="middle" fontSize="28" fontWeight="900" fill={C.blue} fontFamily="system-ui, sans-serif">?</text>
      </g>
    </svg>
  )
}
