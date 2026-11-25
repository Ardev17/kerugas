import { PoweredBy } from '@/components/PoweredBy'
import { SocialLinks } from '@/components/SocialLinks'

export default function About() {
  return (
    <div className="mx-auto max-w-2xl px-4 py-16">
      <h1 className="text-3xl font-bold text-dark">Sobre o KeruGás</h1>
      <p className="mt-4 text-gray">
        O KeruGás nasceu de um problema muito real e muito angolano: deslocarmo-nos a vários
        pontos de venda à procura de gás butano, sem saber se há produto disponível ou se a
        fila vale a pena. A nossa missão é simples,dar a qualquer pessoa a informação certa,
        no momento certo, para decidir com confiança onde ir ou se prefere pedir para receber.
      </p>

      <div className="mt-8 grid gap-6 sm:grid-cols-2">
        <div className="rounded-xl border border-gray/15 bg-white p-5">
          <h2 className="font-semibold text-dark">O que fazemos</h2>
          <p className="mt-2 text-sm text-gray">
            Reunimos atualizações de postos autorizados (painel próprio) com
            confirmações da comunidade, para mostrar disponibilidade, fila e confiança da
            informação em tempo real.
          </p>
        </div>
        <div className="rounded-xl border border-gray/15 bg-white p-5">
          <h2 className="font-semibold text-dark">Para quem</h2>
          <p className="mt-2 text-sm text-gray">
            Para qualquer pessoa em Angola que precisa de gás,e para os próprios postos,
            que ganham visibilidade e uma forma simples de comunicar o seu estado.
          </p>
        </div>
      </div>

      <div className="mt-8">
        <p className="mb-3 text-sm font-semibold text-dark">Siga-nos</p>
        <SocialLinks variant="buttons" />
      </div>

      <p className="mt-8 text-sm text-gray">
        <PoweredBy />.
      </p>
    </div>
  )
}
