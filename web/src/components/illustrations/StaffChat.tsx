import { C, useSvgIds } from './palette'

/**
 * Ilustrações do Chat da Equipa (Outubro 2026): o país ligado por conversas.
 * Mesmo estilo e animações (ill-*) das outras ilustrações,respeitam o
 * "reduzir movimento" do sistema.
 */

export type StaffRoleKey = 'SUPER_ADMIN' | 'PROVINCE_SUPER_ADMIN' | 'ADMIN'

const ROLE_COLOR: Record<StaffRoleKey, string> = {
  SUPER_ADMIN: '#0F172A',
  PROVINCE_SUPER_ADMIN: C.blue,
  ADMIN: C.cyan,
}

/** Ícone do tipo de admin: coroa (Admin Geral), pino com bandeira (Provincial), escudo (Admin). */
export function RoleIcon({ role, size = 14, className = '' }: { role: StaffRoleKey | string | undefined; size?: number; className?: string }) {
  if (role === 'SUPER_ADMIN') {
    return (
      <svg viewBox="0 0 24 24" width={size} height={size} className={className} aria-hidden="true">
        <path d="M3 8 l4.5 4 L12 4 l4.5 8 L21 8 l-2 11 H5 z" fill={C.amber} stroke="#B45309" strokeWidth="1.2" strokeLinejoin="round" />
        <circle cx="12" cy="4" r="1.6" fill="#fff" />
        <circle cx="3" cy="8" r="1.4" fill="#fff" />
        <circle cx="21" cy="8" r="1.4" fill="#fff" />
        <rect x="5" y="19" width="14" height="2.2" rx="1" fill="#B45309" />
      </svg>
    )
  }
  if (role === 'PROVINCE_SUPER_ADMIN') {
    return (
      <svg viewBox="0 0 24 24" width={size} height={size} className={className} aria-hidden="true">
        <path d="M12 22 C7 16 5 12.5 5 9.5 a7 7 0 0 1 14 0 c0 3 -2 6.5 -7 12.5 z" fill={C.blue} />
        <path d="M10 6.2 v7.6" stroke="#fff" strokeWidth="1.6" strokeLinecap="round" />
        <path d="M10 6.4 h5.2 l-1.3 2 l1.3 2 H10 z" fill="#fff" />
      </svg>
    )
  }
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} className={className} aria-hidden="true">
      <path d="M12 2.5 l8 3 v6 c0 5 -3.4 8.6 -8 10 c-4.6 -1.4 -8 -5 -8 -10 v-6 z" fill={C.cyan} />
      <path d="M8.2 12.2 l2.6 2.6 l5 -5.2" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  )
}

/** Cabeçalho: três níveis da equipa (país, província, município) ligados por conversas. */
export function StaffHeroArt({ className = '' }: { className?: string }) {
  const id = useSvgIds('bg', 'glow')
  const nodes = [
    { x: 160, y: 40, role: 'SUPER_ADMIN' as const, label: 'Admin Geral', r: 17 },
    { x: 66, y: 104, role: 'PROVINCE_SUPER_ADMIN' as const, label: 'Provincial', r: 14 },
    { x: 254, y: 104, role: 'PROVINCE_SUPER_ADMIN' as const, label: 'Provincial', r: 14 },
    { x: 24, y: 142, role: 'ADMIN' as const, label: 'Admin', r: 11 },
    { x: 108, y: 142, role: 'ADMIN' as const, label: 'Admin', r: 11 },
    { x: 212, y: 142, role: 'ADMIN' as const, label: 'Admin', r: 11 },
    { x: 296, y: 142, role: 'ADMIN' as const, label: 'Admin', r: 11 },
  ]
  const links: Array<[number, number]> = [[0, 1], [0, 2], [1, 3], [1, 4], [2, 5], [2, 6], [1, 2]]
  return (
    <svg viewBox="0 0 320 170" className={className} role="img" aria-label="Ilustração da equipa KeruGás ligada por conversas, do Admin Geral aos administradores de cada município">
      <defs>
        <linearGradient id={id.bg} x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" stopColor="#0B1338" />
          <stop offset="0.6" stopColor="#11264B" />
          <stop offset="1" stopColor="#FC7201" />
        </linearGradient>
        <radialGradient id={id.glow} cx="0.5" cy="0.5" r="0.5">
          <stop offset="0" stopColor="#9DB4D6" stopOpacity="0.55" />
          <stop offset="1" stopColor="#9DB4D6" stopOpacity="0" />
        </radialGradient>
      </defs>
      <rect width="320" height="170" rx="18" fill={`url(#${id.bg})`} />
      {[[20, 20], [60, 56], [110, 18], [214, 22], [270, 50], [300, 18], [180, 80]].map(([x, y], i) => (
        <circle key={i} cx={x} cy={y} r="1.2" fill="#fff" className="ill-twinkle" style={{ animationDelay: `${i * 0.35}s` }} />
      ))}
      <circle cx="160" cy="40" r="52" fill={`url(#${id.glow})`} className="ill-blink" />
      {links.map(([a, b], i) => (
        <path
          key={i}
          d={`M${nodes[a].x} ${nodes[a].y} Q${(nodes[a].x + nodes[b].x) / 2} ${(nodes[a].y + nodes[b].y) / 2 - (a === 1 && b === 2 ? 22 : 8)} ${nodes[b].x} ${nodes[b].y}`}
          fill="none"
          stroke="#9DB4D6"
          strokeOpacity="0.75"
          strokeWidth="1.8"
          strokeLinecap="round"
          className="ill-dash"
          style={{ animationDelay: `${i * 0.2}s` }}
        />
      ))}
      {nodes.map((n, i) => (
        <g key={i}>
          <circle cx={n.x} cy={n.y} r={n.r + 4} fill="#fff" opacity="0.12" />
          <circle cx={n.x} cy={n.y} r={n.r} fill="#fff" />
          <circle cx={n.x} cy={n.y} r={n.r} fill="none" stroke={ROLE_COLOR[n.role] === '#0F172A' ? C.amber : ROLE_COLOR[n.role]} strokeWidth="2.5" />
          <g transform={`translate(${n.x - n.r * 0.62} ${n.y - n.r * 0.62})`}>
            <RoleIconPath role={n.role} s={n.r * 1.24} />
          </g>
        </g>
      ))}
      <g className="ill-pop" style={{ animationDelay: '0s' }}>
        <rect x="190" y="20" width="62" height="22" rx="11" fill="#fff" />
        <path d="M198 41 l-4 7 l10 -7 z" fill="#fff" />
        <text x="221" y="35" textAnchor="middle" fontSize="10" fontWeight="700" fill={C.navy} fontFamily="system-ui, sans-serif">Bom dia!</text>
      </g>
      <g className="ill-pop" style={{ animationDelay: '2s' }}>
        <rect x="26" y="38" width="54" height="20" rx="10" fill={C.fire} />
        <path d="M60 57 l2 6 l5 -6 z" fill={C.fire} />
        <g className="ill-dots" fill="#fff">
          <circle cx="43" cy="48" r="2.3" />
          <circle cx="53" cy="48" r="2.3" />
          <circle cx="63" cy="48" r="2.3" />
        </g>
      </g>
      <g className="ill-pop" style={{ animationDelay: '4s' }}>
        <rect x="128" y="112" width="64" height="20" rx="10" fill="#fff" />
        <path d="M156 131 l4 6 l3 -6 z" fill="#fff" />
        <text x="160" y="126" textAnchor="middle" fontSize="9.5" fontWeight="700" fill={C.navy} fontFamily="system-ui, sans-serif">Aberto ✓</text>
      </g>
    </svg>
  )
}

/** O desenho do ícone sem moldura (para encaixar dentro de outro SVG). */
function RoleIconPath({ role, s }: { role: StaffRoleKey; s: number }) {
  return (
    <svg viewBox="0 0 24 24" width={s} height={s} x={0} y={0}>
      {role === 'SUPER_ADMIN' && (
        <>
          <path d="M3 8 l4.5 4 L12 4 l4.5 8 L21 8 l-2 11 H5 z" fill={C.amber} stroke="#B45309" strokeWidth="1.2" strokeLinejoin="round" />
          <rect x="5" y="19" width="14" height="2.2" rx="1" fill="#B45309" />
        </>
      )}
      {role === 'PROVINCE_SUPER_ADMIN' && (
        <>
          <path d="M12 22 C7 16 5 12.5 5 9.5 a7 7 0 0 1 14 0 c0 3 -2 6.5 -7 12.5 z" fill={C.blue} />
          <path d="M10 6.4 h5.2 l-1.3 2 l1.3 2 H10 z" fill="#fff" />
          <path d="M10 6.2 v7.6" stroke="#fff" strokeWidth="1.6" strokeLinecap="round" />
        </>
      )}
      {role === 'ADMIN' && (
        <>
          <path d="M12 2.5 l8 3 v6 c0 5 -3.4 8.6 -8 10 c-4.6 -1.4 -8 -5 -8 -10 v-6 z" fill={C.cyan} />
          <path d="M8.2 12.2 l2.6 2.6 l5 -5.2" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </>
      )}
    </svg>
  )
}

/** Chat vazio: os três tipos de admin à volta de um balão "…". */
export function StaffEmptyArt({ className = '' }: { className?: string }) {
  const people = [
    { x: 40, role: 'SUPER_ADMIN' as const },
    { x: 120, role: 'PROVINCE_SUPER_ADMIN' as const },
    { x: 200, role: 'ADMIN' as const },
  ]
  return (
    <svg viewBox="0 0 240 110" className={className} aria-hidden="true">
      <ellipse cx="120" cy="102" rx="110" ry="5" fill={C.blue} opacity="0.1" />
      <path d="M40 74 H200" stroke={C.cyan} strokeWidth="2.5" strokeLinecap="round" className="ill-dash" />
      {people.map((p) => (
        <g key={p.x}>
          <circle cx={p.x} cy="74" r="20" fill="#fff" stroke={p.role === 'SUPER_ADMIN' ? C.amber : ROLE_COLOR[p.role]} strokeWidth="3" />
          <g transform={`translate(${p.x - 12} 62)`}><RoleIconPath role={p.role} s={24} /></g>
        </g>
      ))}
      <g className="ill-float">
        <rect x="96" y="8" width="48" height="26" rx="13" fill={C.fire} />
        <path d="M116 33 l4 7 l4 -7 z" fill={C.fire} />
        <g className="ill-dots" fill="#fff">
          <circle cx="110" cy="21" r="3" />
          <circle cx="120" cy="21" r="3" />
          <circle cx="130" cy="21" r="3" />
        </g>
      </g>
    </svg>
  )
}

/** Silenciado: altifalante com uma barra e ondas a apagar-se. */
export function StaffMutedArt({ size = 40, className = '' }: { size?: number; className?: string }) {
  return (
    <svg viewBox="0 0 40 40" width={size} height={size} className={className} aria-hidden="true">
      <circle cx="20" cy="20" r="19" fill={C.red} opacity="0.1" />
      <path d="M9 16 h5 l7 -6 v20 l-7 -6 h-5 a1.5 1.5 0 0 1 -1.5 -1.5 v-5 A1.5 1.5 0 0 1 9 16 z" fill={C.red} />
      <path d="M26 15 q4 5 0 10" fill="none" stroke={C.red} strokeWidth="2" strokeLinecap="round" className="ill-wave" />
      <path d="M7 33 L33 7" stroke="#fff" strokeWidth="5" strokeLinecap="round" />
      <path d="M7 33 L33 7" stroke={C.red} strokeWidth="2.6" strokeLinecap="round" />
    </svg>
  )
}

/** Aviso fixado: pino com um raio de atenção. */
export function PinnedNoticeArt({ size = 22, className = '' }: { size?: number; className?: string }) {
  return (
    <svg viewBox="0 0 24 24" width={size} height={size} className={className} aria-hidden="true">
      <path d="M8 3 h8 l-1 6 l3 3 v2 h-5 v7 l-1 1 l-1 -1 v-7 H6 v-2 l3 -3 z" fill={C.fire} />
      <path d="M10 5 h3" stroke="#fff" strokeWidth="1.4" strokeLinecap="round" opacity="0.8" />
      <path d="M3 6 l2 1.2 M21 6 l-2 1.2 M2.5 11 h2" stroke={C.amber} strokeWidth="1.6" strokeLinecap="round" className="ill-blink" />
    </svg>
  )
}

/** Acesso reservado: cadeado dentro de um escudo, para quem não é admin. */
export function StaffLockArt({ className = '' }: { className?: string }) {
  return (
    <svg viewBox="0 0 120 120" className={className} aria-hidden="true">
      <ellipse cx="60" cy="112" rx="40" ry="4" fill={C.blue} opacity="0.12" />
      <path d="M60 8 l42 15 v30 c0 28 -18 48 -42 58 C36 101 18 81 18 53 V23 z" fill={C.blue} />
      <path d="M60 16 l34 12 v25 c0 22 -14 39 -34 48 C40 92 26 75 26 53 V28 z" fill="#11264B" />
      <g className="ill-float">
        <path d="M46 52 v-8 a14 14 0 0 1 28 0 v8" fill="none" stroke="#fff" strokeWidth="6" strokeLinecap="round" />
        <rect x="40" y="52" width="40" height="30" rx="7" fill={C.amber} />
        <circle cx="60" cy="64" r="4.5" fill="#92400E" />
        <rect x="58" y="66" width="4" height="9" rx="2" fill="#92400E" />
      </g>
    </svg>
  )
}
