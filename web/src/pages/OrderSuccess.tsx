import { useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { ArrowRight } from 'lucide-react'
import { celebrate } from '@/components/fx/celebrate'
import { EmberField } from '@/components/fx/EmberField'
import { OrderPushPrompt } from '@/components/OrderPushPrompt'
import { readActiveOrder } from '@/lib/activeOrder'

/**
 * Página de sucesso (secção 195). Não promete horário de entrega
 * automaticamente,apenas confirma o registo e o próximo passo humano.
 */
export default function OrderSuccess() {
  const { reference } = useParams<{ reference: string }>()
  // Telefone guardado neste aparelho ao fazer o pedido (prova de posse dos avisos).
  const stored = readActiveOrder()
  const phone = stored && stored.reference === reference ? stored.phone : null

  // Festa quando o visto acaba de se desenhar (Outubro 2026).
  useEffect(() => {
    let stop = () => {}
    const t = window.setTimeout(() => {
      stop = celebrate()
    }, 650)
    return () => {
      window.clearTimeout(t)
      stop()
    }
  }, [])

  return (
    <div className="relative isolate overflow-hidden">
    <div className="fx-aurora" aria-hidden="true"><i /></div>
    <EmberField density={0.4} />
    <div className="relative mx-auto flex max-w-lg flex-col items-center px-4 py-16 text-center">
      {/* Visto que se desenha, com ondas de luz. */}
      <span className="fx-check h-24 w-24" aria-hidden="true">
        <span className="fx-check-fill" />
        <span className="fx-check-ring" />
        <span className="fx-check-ring fx-check-ring-2" />
        <svg viewBox="0 0 56 56" width="96" height="96">
          <circle className="fx-check-circle" cx="28" cy="28" r="26" fill="none" stroke="#16A34A" strokeWidth="3" />
          <path className="fx-check-mark" d="M16 29l8 8 16-17" fill="none" stroke="#16A34A" strokeWidth="4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </span>

      <div className="fx-stagger" style={{ ['--stagger-base' as string]: '0.7s' }}>
        <h1 className="mt-5 text-3xl font-extrabold text-dark">Pedido recebido com sucesso.</h1>
        <p className="mt-2 text-gray">Recebemos o seu pedido de gás.</p>

        <div className="fx-ring-border mt-7 w-full rounded-2xl">
          <div className="rounded-2xl bg-white p-5">
            <p className="text-xs uppercase tracking-[0.25em] text-gray">Referência</p>
            <p className="fx-gradient-text mt-1 text-2xl font-black tracking-wide">{reference}</p>
          </div>
        </div>
      </div>

      <p className="mt-6 text-sm text-gray">
        A nossa equipa entrará em contacto consigo para confirmar os detalhes e organizar a entrega.
      </p>

      {/* Outubro 2026: o acompanhamento é para toda a gente,com conta,
          pela conta; sem conta, pelo telefone guardado neste aparelho. */}
      {reference && <div className="w-full"><OrderPushPrompt reference={reference} phone={phone} /></div>}

      <div className="mt-8 flex w-full flex-col gap-2">
        {reference && (
          <Link
            to={`/pedido/${encodeURIComponent(reference)}`}
            className="fx-btn-fire group flex items-center justify-center gap-2 rounded-xl px-6 py-3.5 text-sm font-semibold text-white"
          >
            Acompanhar pedido
            <ArrowRight size={16} aria-hidden="true" className="transition-transform group-hover:translate-x-1" />
          </Link>
        )}
        <Link to="/" className="rounded-lg px-6 py-3 text-sm font-semibold text-gray">
          Voltar ao início
        </Link>
      </div>
    </div>
    </div>
  )
}
