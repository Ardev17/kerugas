import { Link } from 'react-router-dom'
import { Lock, LogIn, MessageCircle, Phone } from 'lucide-react'
import { CENTRAL_CONTACT_NUMBERS } from '@/constants/contact'

/**
 * Consulta fechada num município (Novembro 2026).
 *
 * Quando um município passa a pago, os seus postos deixam de vir na lista. Sem
 * isto, o cliente via "não encontramos postos nesta área" e ficava a pensar que
 * a aplicação estava avariada,ou pior, que não há gás na zona dele. Aqui
 * diz-se o que se passa, mostra-se o motivo que o administrador escreveu, e
 * dá-se o caminho para resolver: falar connosco.
 */

const WHATSAPP = '244922761180'

export interface BlockedZone {
  id?: number
  name: string
  reason?: string | null
}

interface Props {
  zones: BlockedZone[]
  /** true quando o servidor pediu sessão: sem saber quem é, não há excepção a aplicar. */
  needsLogin?: boolean
  /** true quando foi a conta da pessoa que foi bloqueada, não o município. */
  accountBlocked?: boolean
  /** Mensagem vinda do servidor; ganha ao motivo escrito na zona. */
  message?: string | null
}

export default function SubscriptionNotice({ zones, needsLogin = false, accountBlocked = false, message = null }: Props) {
  const nomes = zones.map((z) => z.name).filter(Boolean)
  const uma = nomes.length === 1 ? nomes[0] : null
  const motivo = message ?? zones.find((z) => z.reason)?.reason ?? null

  const titulo = accountBlocked
    ? 'A sua conta está sem acesso à consulta, pague a subscrição para consultar os postos.'
    : uma
      ? `${uma}: pague a subscrição, para ver os dados`
      : 'Pague a subscrição, para ver os dados'

  const texto = encodeURIComponent(
    uma
      ? `Olá! Quero subscrever para consultar os postos do ${uma}.`
      : 'Olá! Quero subscrever para consultar os postos na minha zona.',
  )

  return (
    <section
      aria-labelledby="aviso-subscricao"
      className="fx-slide-next rounded-2xl border border-primary/30 bg-primary/5 p-6 sm:p-8"
    >
      <div className="flex gap-4">
        <span
          className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-primary/15 text-primary"
          aria-hidden="true"
        >
          <Lock size={20} />
        </span>

        <div className="min-w-0 flex-1">
          <h2 id="aviso-subscricao" className="text-lg font-bold text-dark">{titulo}</h2>

          <p className="mt-2 text-sm leading-relaxed text-gray">
            {motivo ?? (accountBlocked
              ? 'Fale connosco para reactivar o acesso à sua conta.'
              : 'O período de consulta gratuita terminou nesta zona. Para voltar a ver que postos têm gás, precisa de uma subscrição activa.')}
          </p>

          {!accountBlocked && nomes.length > 1 && (
            <p className="mt-2 text-sm text-gray">
              Zonas fechadas: <strong className="text-dark">{nomes.join(', ')}</strong>.
            </p>
          )}

          {needsLogin && (
            <p className="mt-2 text-sm text-gray">
              Se já tem subscrição, entre na sua conta para voltar a ver os postos.
            </p>
          )}

          <div className="mt-5 flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
            {needsLogin && (
              <Link
                to="/login"
                className="inline-flex items-center justify-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90"
              >
                <LogIn size={16} aria-hidden="true" />
                Entrar na minha conta
              </Link>
            )}

            <a
              href={`https://wa.me/${WHATSAPP}?text=${texto}`}
              target="_blank"
              rel="noreferrer noopener"
              className={`inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold ${
                needsLogin
                  ? 'border border-primary/40 text-primary hover:bg-primary/10'
                  : 'bg-primary text-white hover:opacity-90'
              }`}
            >
              <MessageCircle size={16} aria-hidden="true" />
              Pedir subscrição pelo WhatsApp
            </a>
          </div>

          <p className="mt-4 text-xs text-gray">
            Ou ligue para{' '}
            {CENTRAL_CONTACT_NUMBERS.map((c, i) => (
              <span key={c.number}>
                {i > 0 && ' · '}
                <a href={`tel:${c.number}`} className="inline-flex items-center gap-1 font-semibold text-dark hover:text-primary">
                  <Phone size={12} aria-hidden="true" />
                  {c.display}
                </a>
              </span>
            ))}
          </p>
        </div>
      </div>
    </section>
  )
}
