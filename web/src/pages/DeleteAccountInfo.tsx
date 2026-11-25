import { Link, useSearchParams } from 'react-router-dom'
import { CheckCircle2 } from 'lucide-react'

/**
 * Página pública "Eliminar conta" (Outubro 2026). A Google Play exige um
 * link na web que explique como eliminar a conta e os dados,é este
 * (https://kerugas.ao/eliminar-conta). Também é para onde a app manda a
 * pessoa depois de pedir a eliminação (`?agendada=<data>`).
 */
export default function DeleteAccountInfo() {
  const [params] = useSearchParams()
  const scheduled = params.get('agendada')
  const date = scheduled && !Number.isNaN(Date.parse(scheduled))
    ? new Date(scheduled).toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' })
    : null

  return (
    <div className="mx-auto max-w-2xl px-4 py-16">
      {date && (
        <div role="status" className="mb-8 flex gap-3 rounded-xl border border-success/30 bg-success/5 p-4 text-sm text-dark">
          <CheckCircle2 size={20} className="mt-0.5 shrink-0 text-success" />
          <div>
            <p className="font-semibold">Pedido de eliminação registado.</p>
            <p className="mt-1 text-gray">
              A sua conta será eliminada a <strong className="text-dark">{date}</strong>. Todas as sessões foram terminadas.
              Mudou de ideias? Basta <Link to="/login" className="font-medium text-primary">entrar de novo</Link> antes dessa data.
            </p>
          </div>
        </div>
      )}

      <h1 className="text-3xl font-bold text-dark">Eliminar a sua conta KeruGás</h1>
      <p className="mt-2 text-sm text-gray">KeruGás,plataforma de disponibilidade e entrega de gás em Angola.</p>

      <div className="mt-8 space-y-6 text-sm text-gray">
        <section>
          <h2 className="font-semibold text-dark">Como eliminar</h2>
          <ol className="mt-2 list-decimal space-y-1 pl-5">
            <li><Link to="/login" className="font-medium text-primary">Entre na sua conta</Link> na app KeruGás ou em kerugas.ao.</li>
            <li>Abra <strong className="text-dark">Meu perfil</strong> (no menu ou no ícone da sua foto).</li>
            <li>No fim da página, em <strong className="text-dark">Eliminar conta</strong>, confirme com a sua palavra-passe e escreva ELIMINAR.</li>
          </ol>
          <p className="mt-2">
            Não consegue entrar? Peça a eliminação através da página de <Link to="/contacto" className="font-medium text-primary">Contacto</Link>,
            a partir do número de telefone da conta. Confirmamos a identidade antes de eliminar.
          </p>
        </section>

        <section>
          <h2 className="font-semibold text-dark">Prazo para mudar de ideias</h2>
          <p className="mt-2">
            A conta é eliminada <strong className="text-dark">7 dias</strong> depois do pedido. Se entrar de novo nesse prazo, o pedido é cancelado.
          </p>
        </section>

        <section>
          <h2 className="font-semibold text-dark">O que é apagado</h2>
          <p className="mt-2">
            Nome, número de telefone, email, palavra-passe, foto de perfil, localização, favoritos, alertas, notificações,
            comentários, mensagens do chat, mensagens de contacto e SMS, o texto das suas avaliações e denúncias, os
            dispositivos registados para notificações e os endereços de rede guardados nos registos de segurança.
          </p>
        </section>

        <section>
          <h2 className="font-semibold text-dark">O que fica (sem dados pessoais)</h2>
          <p className="mt-2">
            Os pedidos de gás e os pagamentos associados ficam guardados para efeitos contabilísticos e legais, mas sem o seu nome,
            telefone, morada ou localização. As avaliações de postos ficam como "Conta eliminada". O registo de auditoria guarda que
            a conta foi eliminada e quando.
          </p>
        </section>

        <section>
          <h2 className="font-semibold text-dark">Quando não é possível eliminar logo</h2>
          <p className="mt-2">
            Enquanto houver algo por acertar,um pedido de gás em curso, garrafas ou dinheiro de entregas (condutores), um posto
            atribuído (operadores),a app explica o que falta resolver primeiro.
          </p>
        </section>

        <p>Mais detalhes na <Link to="/privacidade" className="font-medium text-primary">Política de Privacidade</Link>.</p>
      </div>
    </div>
  )
}
