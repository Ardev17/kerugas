import { Phone } from 'lucide-react'
import { CENTRAL_CONTACT_NUMBERS } from '@/constants/contact'

/**
 * Links de chamada direta para a central do KERUGAS,sempre um
 * `<a href="tel:...">`, nunca só texto, para que um clique/toque
 * abra logo o discador do telemóvel (pedido explícito: "de fácil
 * acesso como um link direto para o usuário clicar e chamar logo").
 */
export function CentralContactLinks({ variant = 'full' }: { variant?: 'full' | 'compact' }) {
  if (variant === 'compact') {
    return (
      <div className="flex flex-wrap gap-x-4 gap-y-1">
        {CENTRAL_CONTACT_NUMBERS.map((c) => (
          <a
            key={c.number}
            href={`tel:${c.number}`}
            className="flex items-center gap-1.5 text-sm text-gray hover:text-primary"
          >
            <Phone size={13} /> {c.display}
            {c.operator && <span className="text-xs text-gray/70">({c.operator})</span>}
          </a>
        ))}
      </div>
    )
  }

  return (
    <div className="grid gap-2 sm:grid-cols-3">
      {CENTRAL_CONTACT_NUMBERS.map((c) => (
        <a
          key={c.number}
          href={`tel:${c.number}`}
          className="flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2.5 text-sm font-medium text-primary hover:bg-primary/10"
        >
          <Phone size={15} />
          <span>
            {c.display}
            {c.operator && <span className="block text-xs font-normal text-gray">{c.operator}</span>}
          </span>
        </a>
      ))}
    </div>
  )
}
