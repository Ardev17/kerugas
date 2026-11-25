import { Home, Store } from 'lucide-react'

interface Props {
  /** 0 = no posto … 1 = em casa. */
  progress: number
  /** O camião está mesmo a andar (estado "A caminho"). */
  moving: boolean
  delivered: boolean
}

/**
 * A viagem do gás (Outubro 2026): uma estrada do posto até casa, com o
 * camião KeruGás a avançar conforme o estado do pedido. Quando está "A
 * caminho" as rodas giram, a estrada corre e sai fumo; quando é entregue,
 * a casa acende-se. Puro SVG + CSS.
 */
export function DeliveryRoad({ progress, moving, delivered }: Props) {
  const p = Math.max(0, Math.min(1, progress))
  return (
    <div
      className={`fx-road ${moving ? 'is-moving' : ''} ${delivered ? 'is-delivered' : ''}`}
      role="img"
      aria-label={delivered ? 'O gás chegou a casa' : moving ? 'O camião está a caminho' : 'O pedido está a ser preparado no posto'}
    >
      <div className="fx-road-sky" aria-hidden="true" />
      <span className="fx-road-end fx-road-start" aria-hidden="true">
        <Store size={18} />
      </span>
      <span className="fx-road-end fx-road-home" aria-hidden="true">
        <Home size={18} />
      </span>
      <div className="fx-road-track" aria-hidden="true">
        <div className="fx-road-dashes" />
        <div className="fx-road-trail" style={{ transform: `scaleX(${p})` }} />
      </div>
      <div className="fx-road-truck" style={{ left: `calc(8% + 40px + ${p} * (84% - 136px))` }} aria-hidden="true">
        <span className="fx-road-smoke" />
        <span className="fx-road-smoke fx-road-smoke-2" />
        <svg viewBox="0 0 64 40" width="56" height="35">
          {/* carroçaria com as botijas */}
          <rect x="2" y="8" width="36" height="20" rx="4" fill="#FC7201" />
          <rect x="7" y="3" width="8" height="10" rx="3" fill="#FF6B1A" />
          <rect x="17" y="3" width="8" height="10" rx="3" fill="#FFB020" />
          <rect x="27" y="3" width="8" height="10" rx="3" fill="#FF6B1A" />
          <text x="20" y="22.5" textAnchor="middle" fontSize="7" fontWeight="800" fill="#fff" fontFamily="system-ui">KG</text>
          {/* cabina */}
          <path d="M38 12h12l8 9v7H38z" fill="#FFB020" />
          <path d="M41 14h8l5 6H41z" fill="#E0F2FE" />
          <rect x="56" y="22" width="4" height="3" rx="1" fill="#FFB020" className="fx-road-light" />
          {/* rodas */}
          <g className="fx-road-wheel" style={{ transformOrigin: '14px 30px' }}>
            <circle cx="14" cy="30" r="6" fill="#0F172A" />
            <circle cx="14" cy="30" r="2.5" fill="#CBD5E1" />
            <rect x="13.4" y="24.5" width="1.2" height="11" fill="#475569" />
          </g>
          <g className="fx-road-wheel" style={{ transformOrigin: '48px 30px' }}>
            <circle cx="48" cy="30" r="6" fill="#0F172A" />
            <circle cx="48" cy="30" r="2.5" fill="#CBD5E1" />
            <rect x="47.4" y="24.5" width="1.2" height="11" fill="#475569" />
          </g>
        </svg>
      </div>
    </div>
  )
}
