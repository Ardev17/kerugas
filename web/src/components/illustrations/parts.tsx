import { C } from './palette'

/**
 * Peças partilhadas das ilustrações (Outubro 2026),estilo "Fogo e
 * energia": azul da marca, ciano, laranja/âmbar do fogo. Tudo em SVG
 * (nítido em qualquer ecrã, poucos KB) e animado só com CSS (globals.css,
 * secção ILUSTRAÇÕES), que pára com "reduzir movimento".
 */
/** Botija de gás (origem no centro da base). `s` = escala. */
export function Cylinder({ x, y, s = 1, grad, className = '' }: { x: number; y: number; s?: number; grad: string; className?: string }) {
  return (
    <g transform={`translate(${x} ${y}) scale(${s})`} className={className}>
      <ellipse cx="0" cy="1" rx="13" ry="3" fill="#000" opacity="0.18" />
      <rect x="-12" y="-34" width="24" height="34" rx="9" fill={`url(#${grad})`} />
      <rect x="-12" y="-24" width="24" height="3" fill="#000" opacity="0.12" />
      <rect x="-12" y="-12" width="24" height="3" fill="#000" opacity="0.12" />
      <rect x="-7" y="-41" width="14" height="8" rx="2" fill={C.slate} />
      <rect x="-3" y="-46" width="6" height="6" rx="1.5" fill="#94A3B8" />
      <rect x="-9" y="-31" width="4" height="20" rx="2" fill="#fff" opacity="0.28" />
    </g>
  )
}

/** Gradiente laranja da botija. */
export function CylinderGradient({ id }: { id: string }) {
  return (
    <linearGradient id={id} x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stopColor="#FF8A3D" />
      <stop offset="0.55" stopColor={C.fire} />
      <stop offset="1" stopColor="#D9480F" />
    </linearGradient>
  )
}
