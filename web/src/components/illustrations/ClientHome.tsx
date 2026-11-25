import { Cylinder, CylinderGradient } from './parts'
import { C, useSvgIds } from './palette'
import { SKY, dayPart } from './driverData'

/**
 * "A minha casa" (Outubro 2026),cartão de boas-vindas do cliente. A casa
 * com o céu da hora real, a chama do fogão acesa pela janela e a botija à
 * porta. Com um pedido em curso, o camião KeruGás chega pela rua e pára à
 * porta.
 */
export function ClientHomeBanner({ hour, truckArriving, className = '' }: { hour: number; truckArriving: boolean; className?: string }) {
  const part = dayPart(hour)
  const sky = SKY[part]
  const night = part === 'night'
  const id = useSvgIds('sky', 'glow', 'cyl', 'wall', 'flame', 'win')
  const sunY = part === 'afternoon' ? 42 : night ? 40 : 58
  return (
    <svg viewBox="0 0 360 120" preserveAspectRatio="xMidYMax slice" className={className} aria-hidden="true">
      <defs>
        <linearGradient id={id.sky} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor={sky.top} />
          <stop offset="1" stopColor={sky.bottom} />
        </linearGradient>
        <radialGradient id={id.glow} cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor={night ? '#E2E8F0' : '#FFE08A'} stopOpacity="0.9" />
          <stop offset="1" stopColor={night ? '#E2E8F0' : '#FFB020'} stopOpacity="0" />
        </radialGradient>
        <linearGradient id={id.wall} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0" stopColor={night ? '#FDE68A' : '#FEF3C7'} />
          <stop offset="1" stopColor={night ? '#D97706' : '#FCD34D'} />
        </linearGradient>
        <linearGradient id={id.flame} x1="0" y1="1" x2="0" y2="0">
          <stop offset="0" stopColor="#FFC93C" />
          <stop offset="0.5" stopColor="#FF7A00" />
          <stop offset="1" stopColor="#C81E0A" />
        </linearGradient>
        <radialGradient id={id.win} cx="0.5" cy="0.7" r="0.7">
          <stop offset="0" stopColor="#FFF7D6" />
          <stop offset="1" stopColor={night ? '#FFB020' : '#FDE68A'} />
        </radialGradient>
        <CylinderGradient id={id.cyl} />
      </defs>

      {/* céu */}
      <rect width="360" height="120" fill={`url(#${id.sky})`} />
      {night &&
        [[20, 12], [60, 28], [96, 10], [140, 22], [180, 8], [330, 40], [300, 14], [44, 44]].map(([x, y], i) => (
          <circle key={i} cx={x} cy={y} r="1.2" fill="#fff" className="ill-twinkle" style={{ animationDelay: `${i * 0.35}s` }} />
        ))}
      <circle cx="72" cy={sunY} r="30" fill={`url(#${id.glow})`} />
      {night ? (
        <path d="M66 28 a12 12 0 1 0 14 18 a10 10 0 1 1 -14 -18 z" fill="#F1F5F9" />
      ) : (
        <circle cx="72" cy={sunY} r="11" fill="#FFD166" />
      )}
      {!night && (
        <g fill="#fff" opacity="0.75" className="ill-float">
          <ellipse cx="150" cy="40" rx="16" ry="6" />
          <ellipse cx="162" cy="36" rx="12" ry="6" />
        </g>
      )}

      {/* colinas e árvore */}
      <path d="M0 82 Q70 58 140 76 T280 70 T360 74 V120 H0 Z" fill={night ? '#0B1338' : '#15803D'} opacity={night ? 0.8 : 0.45} />
      <rect x="320" y="58" width="5" height="30" rx="2" fill="#7C2D12" />
      <circle cx="322" cy="54" r="14" fill={night ? '#14532D' : '#16A34A'} />
      <circle cx="312" cy="62" r="9" fill={night ? '#166534' : '#22C55E'} />

      {/* casa */}
      <g>
        <path d="M188 52 L232 24 L276 52 Z" fill="#B91C1C" />
        <path d="M188 52 L232 24 L276 52" fill="none" stroke="#7F1D1D" strokeWidth="2" />
        <rect x="194" y="52" width="76" height="44" fill={`url(#${id.wall})`} />
        <rect x="194" y="52" width="76" height="4" fill="#000" opacity="0.08" />
        {/* janela da cozinha com o fogão aceso */}
        <rect x="204" y="60" width="26" height="20" rx="2" fill={`url(#${id.win})`} stroke="#92400E" strokeWidth="2" />
        <rect x="208" y="73" width="18" height="5" rx="1" fill="#475569" />
        <g transform="translate(217 73)">
          <path d="M0 -10 c2.5 3.5 4 5 4 7.5 a4 4 0 0 1 -8 0 c0 -2.5 1.5 -4 4 -7.5 z" fill={`url(#${id.flame})`} className="fx-flicker-icon" style={{ transformBox: 'fill-box', transformOrigin: '50% 100%' }} />
        </g>
        <path d="M217 59 v21 M204 70 h26" stroke="#92400E" strokeWidth="1.2" opacity="0.5" />
        {/* porta */}
        <rect x="244" y="64" width="18" height="32" rx="2" fill="#7C2D12" />
        <circle cx="258" cy="81" r="1.5" fill={C.amber} />
        {/* fumo da chaminé */}
        <rect x="254" y="30" width="8" height="14" fill="#7F1D1D" />
        <g fill="#fff" opacity="0.6">
          <circle cx="258" cy="26" r="3" className="ill-rise" />
          <circle cx="258" cy="26" r="3" className="ill-rise" style={{ animationDelay: '1.2s' }} />
        </g>
      </g>
      {/* botija à porta */}
      <Cylinder x={278} y={96} s={0.62} grad={id.cyl} />

      {/* rua */}
      <rect y="96" width="360" height="24" fill={C.slate} />
      <g opacity="0.8">
        {Array.from({ length: 13 }).map((_, i) => <rect key={i} x={i * 30 + 4} y="107" width="14" height="2" fill="#fff" />)}
      </g>

      {/* o camião chega quando há um pedido em curso */}
      {truckArriving && (
        <g className="ill-arrive">
          <g transform="translate(150 82)">
            <g className="ill-bump">
              <rect x="0" y="4" width="28" height="14" rx="2" fill={C.blue} />
              <rect x="3" y="0" width="6" height="6" rx="2" fill={C.fire} />
              <rect x="11" y="0" width="6" height="6" rx="2" fill={C.amber} />
              <rect x="19" y="0" width="6" height="6" rx="2" fill={C.fire} />
              <path d="M28 7 h9 l6 6 v5 h-15 z" fill={C.cyan} />
              <circle cx="7" cy="19" r="3.6" fill={C.navy} />
              <circle cx="35" cy="19" r="3.6" fill={C.navy} />
              {night && <path d="M43 14 l16 -3 v9 z" fill="#FFE08A" opacity="0.5" />}
            </g>
          </g>
        </g>
      )}
    </svg>
  )
}
