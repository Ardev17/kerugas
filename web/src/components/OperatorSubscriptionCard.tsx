import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Copy, CreditCard } from 'lucide-react'
import { operatorService } from '@/services/operatorService'

function formatDate(iso: string) {
  return new Date(iso).toLocaleDateString('pt-PT', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

/**
 * Subscrição do posto, no painel do operador (Outubro 2026): se está
 * ativa e, quando falta atualizar, como pagar (IBAN + contacto para o
 * comprovativo). Não mostra nada enquanto carrega ou se falhar — nunca
 * atrapalha a atualização do estado do posto.
 */
export function OperatorSubscriptionCard() {
  const [copied, setCopied] = useState(false)
  const { data } = useQuery({
    queryKey: ['operator-subscription'],
    queryFn: operatorService.subscription,
    retry: false,
  })

  if (!data) return null

  const { payment } = data
  // Desde Novembro de 2026 é uma coisa só: a presença paga traz o analytics
  // dentro. Pede-se acção quando o posto já saiu do mapa E durante os dias de
  // tolerância — é aí que o aviso serve de alguma coisa.
  const emTolerancia = data.listing_enforced && data.visible && !!data.grace_until
  const needsAction = (data.listing_enforced && !data.visible) || emTolerancia

  const copyIban = async () => {
    try {
      await navigator.clipboard.writeText((payment.iban ?? '').replace(/\s+/g, ''))
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      // sem permissão de área de transferência: o IBAN continua visível para copiar à mão
    }
  }

  // Tudo regularizado → não mostra nada. O cartão só aparece quando há
  // acção pendente (posto fora do mapa ou em período de tolerância).
  if (!needsAction) return null

  return (
    <section aria-label="Subscrição" className="mt-6 rounded-xl border border-warning/40 bg-warning/5 p-5">
      <p className="flex items-center gap-2 font-semibold text-dark">
        <CreditCard size={16} className="text-warning" /> Subscrição do posto
      </p>
      <p className="mt-1 text-sm text-gray">
        {data.grace_until
          ? `A subscrição do seu posto expirou. Renove até ${formatDate(data.grace_until)} para o posto continuar visível na aplicação e manter o analytics.`
          : 'O seu posto não está visível na aplicação. Atualize a subscrição para voltar ao mapa e ter o analytics.'}
      </p>
      {data.subscribed && data.valid_until && (
        <p className="mt-1 text-xs text-gray">Válida até {formatDate(data.valid_until)}.</p>
      )}

      <div className="mt-4 space-y-1.5 rounded-lg bg-white p-3 text-sm">
        {payment.bank_name && <p className="text-gray">Banco: <span className="font-medium text-dark">{payment.bank_name}</span></p>}
        {payment.account_holder && <p className="text-gray">Titular: <span className="font-medium text-dark">{payment.account_holder}</span></p>}
        {payment.iban ? (
          <div className="flex items-center justify-between gap-2">
            <p className="break-all text-gray">IBAN: <span className="font-mono font-medium text-dark">{payment.iban}</span></p>
            <button
              type="button"
              onClick={() => void copyIban()}
              aria-label="Copiar IBAN"
              className="flex shrink-0 items-center gap-1 rounded-md border border-gray/20 px-2 py-1 text-xs font-semibold text-dark"
            >
              <Copy size={12} /> {copied ? 'Copiado' : 'Copiar'}
            </button>
          </div>
        ) : (
          <p className="text-gray">Peça o IBAN ao nosso contacto abaixo.</p>
        )}
        {payment.price && <p className="text-gray">Valor mensal: <span className="font-medium text-dark">{payment.price}</span></p>}
        <p className="text-gray">Depois de pagar, envie o comprovativo para <span className="font-medium text-dark">{payment.contact}</span>.</p>
      </div>
    </section>
  )
}
