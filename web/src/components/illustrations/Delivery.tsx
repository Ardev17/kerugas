import { Cylinder, CylinderGradient } from './parts'
import { C, useSvgIds } from './palette'

export type DeliveryStage = 'received' | 'contacting' | 'confirmed' | 'on_the_way' | 'delivered'

const LABEL: Record<DeliveryStage, string> = {
  received: 'Pedido recebido',
  contacting: 'A equipa está a contactá-lo',
  confirmed: 'Pedido confirmado',
  on_the_way: 'O gás está a caminho',
  delivered: 'Gás entregue',
}

/**
 * Uma ilustração para cada estado do pedido (Outubro 2026): prancheta com
 * visto → telefone a vibrar → botija com selo → camião na cidade → porta
 * de casa com a botija e um coração.
 */
export function OrderStageArt({ stage, className = '' }: { stage: DeliveryStage; className?: string }) {
  const id = useSvgIds('bg', 'cyl', 'clip')
  const tint: Record<DeliveryStage, [string, string]> = {
    received: ['#DBEAFE', '#93C5FD'],
    contacting: ['#CFFAFE', '#67E8F9'],
    confirmed: ['#DCFCE7', '#86EFAC'],
    on_the_way: ['#FFEDD5', '#FDBA74'],
    delivered: ['#DCFCE7', '#4ADE80'],
  }
  return (
    <svg viewBox="0 0 100 100" className={className} role="img" aria-label={LABEL[stage]}>
      <defs>
        <radialGradient id={id.bg} cx="0.5" cy="0.4" r="0.6">
          <stop offset="0" stopColor={tint[stage][0]} />
          <stop offset="1" stopColor={tint[stage][1]} />
        </radialGradient>
        <CylinderGradient id={id.cyl} />
        <clipPath id={id.clip}>
          <circle cx="50" cy="50" r="46" />
        </clipPath>
      </defs>
      <circle cx="50" cy="50" r="46" fill={`url(#${id.bg})`} />

      {stage === 'received' && (
        <g className="ill-float">
          <rect x="30" y="22" width="40" height="54" rx="5" fill="#fff" stroke={C.blue} strokeWidth="2.5" />
          <rect x="40" y="17" width="20" height="10" rx="3" fill={C.blue} />
          <rect x="37" y="36" width="20" height="3" rx="1.5" fill="#CBD5E1" />
          <rect x="37" y="45" width="26" height="3" rx="1.5" fill="#CBD5E1" />
          <rect x="37" y="54" width="16" height="3" rx="1.5" fill="#CBD5E1" />
          <circle cx="62" cy="66" r="11" fill={C.green} />
          <path d="M56 66 l4 4 l8 -8" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" className="ill-draw" />
        </g>
      )}

      {stage === 'contacting' && (
        <g>
          <circle cx="50" cy="50" r="22" fill="none" stroke={C.cyan} strokeWidth="2" className="ill-ring" />
          <circle cx="50" cy="50" r="22" fill="none" stroke={C.cyan} strokeWidth="2" className="ill-ring" style={{ animationDelay: '0.8s' }} />
          <g className="ill-shake">
            <rect x="37" y="24" width="26" height="52" rx="6" fill={C.navy} />
            <rect x="40" y="30" width="20" height="36" rx="2" fill={C.cyan} />
            <circle cx="50" cy="71" r="2.2" fill="#64748B" />
            <path d="M44 42 q6 -6 12 0 M46 47 q4 -3 8 0" fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" />
            <circle cx="50" cy="52" r="2" fill="#fff" />
          </g>
        </g>
      )}

      {stage === 'confirmed' && (
        <g>
          <g className="ill-float">
            <Cylinder x={46} y={80} s={1.05} grad={id.cyl} />
          </g>
          <g className="ill-pop" style={{ animationDuration: '4s' }}>
            <circle cx="66" cy="34" r="13" fill={C.green} stroke="#fff" strokeWidth="3" />
            <path d="M60 34 l4 4 l8 -8" fill="none" stroke="#fff" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
          </g>
        </g>
      )}

      {stage === 'on_the_way' && (
        <g clipPath={`url(#${id.clip})`}>
          <g className="ill-scroll" fill="#FB923C" opacity="0.55">
            {[0, 60, 120].map((o) => (
              <g key={o} transform={`translate(${o} 0)`}>
                <rect x="4" y="34" width="12" height="30" />
                <rect x="18" y="26" width="14" height="38" />
                <rect x="34" y="40" width="10" height="24" />
                <rect x="46" y="30" width="12" height="34" />
              </g>
            ))}
          </g>
          <rect x="0" y="64" width="100" height="40" fill={C.slate} />
          <g className="ill-scroll">
            {[0, 20, 40, 60, 80, 100, 120, 140].map((x) => <rect key={x} x={x} y="72" width="10" height="2.5" fill="#fff" opacity="0.8" />)}
          </g>
          <g transform="translate(20 38)">
          <g className="ill-bump">
            <rect x="0" y="6" width="38" height="20" rx="3" fill={C.blue} />
            <rect x="5" y="0" width="8" height="10" rx="3" fill={C.fire} />
            <rect x="15" y="0" width="8" height="10" rx="3" fill={C.amber} />
            <rect x="25" y="0" width="8" height="10" rx="3" fill={C.fire} />
            <path d="M38 10 h12 l9 9 v7 h-21 z" fill={C.cyan} />
            <path d="M41 12 h8 l6 6 h-14 z" fill="#E0F2FE" />
            <circle cx="10" cy="27" r="5" fill={C.navy} />
            <circle cx="48" cy="27" r="5" fill={C.navy} />
            <circle cx="10" cy="27" r="2" fill="#CBD5E1" />
            <circle cx="48" cy="27" r="2" fill="#CBD5E1" />
          </g>
          </g>
        </g>
      )}

      {stage === 'delivered' && (
        <g>
          <rect x="44" y="24" width="32" height="54" rx="3" fill="#7C2D12" />
          <rect x="48" y="28" width="24" height="46" rx="2" fill="#9A3412" />
          <circle cx="68" cy="52" r="2" fill={C.amber} />
          <rect x="22" y="78" width="60" height="4" rx="2" fill="#15803D" opacity="0.5" />
          <Cylinder x={33} y={79} s={0.9} grad={id.cyl} />
          <path
            className="ill-rise"
            d="M33 24 c-3 -5 -10 -2 -8 3 c1 3 8 8 8 8 s7 -5 8 -8 c2 -5 -5 -8 -8 -3 z"
            fill={C.red}
          />
        </g>
      )}
    </svg>
  )
}

/** Pedido cancelado: a botija de volta à prateleira, sem alarme. */
export function CancelledArt({ className = '' }: { className?: string }) {
  const id = useSvgIds('cyl')
  return (
    <svg viewBox="0 0 100 100" className={className} aria-hidden="true">
      <defs>
        <CylinderGradient id={id.cyl} />
      </defs>
      <circle cx="50" cy="50" r="46" fill="#F1F5F9" />
      <rect x="18" y="70" width="64" height="5" rx="2" fill="#94A3B8" />
      <rect x="22" y="75" width="4" height="12" fill="#94A3B8" />
      <rect x="74" y="75" width="4" height="12" fill="#94A3B8" />
      <g opacity="0.75">
        <Cylinder x={38} y={70} s={0.8} grad={id.cyl} />
        <Cylinder x={62} y={70} s={0.8} grad={id.cyl} />
      </g>
      <g className="ill-float">
        <path d="M70 18 a10 10 0 1 0 10 14 a8 8 0 1 1 -10 -14 z" fill="#CBD5E1" />
      </g>
    </svg>
  )
}
