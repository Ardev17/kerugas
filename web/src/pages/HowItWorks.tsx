import { Link } from 'react-router-dom'
import { MapPin, Flame, Navigation, PackagePlus } from 'lucide-react'
import { FaqSection } from '@/components/FaqSection'

export default function HowItWorks() {
  const steps = [
    { icon: MapPin, title: '1. Partilhe a sua localização', text: 'Ou selecione manualmente a sua província e município,  a escolha é sempre sua.' },
    { icon: Flame, title: '2. Veja a disponibilidade', text: 'Cada posto mostra se tem gás, o nível da fila, os tipos de garrafa disponíveis e há quanto tempo foi atualizado.' },
    { icon: Navigation, title: '3. Trace a rota', text: 'Escolha o posto mais conveniente e abra a navegação diretamente no Google Maps.' },
    { icon: PackagePlus, title: '4. Ou peça para receber', text: 'Sem gás perto de si, ou prefere não sair de casa? Peça a entrega,a nossa equipa trata do resto.' },
  ]

  return (
    <div className="mx-auto max-w-3xl px-4 py-16">
      <h1 className="text-3xl font-bold text-dark">Como funciona</h1>
      <p className="mt-2 text-gray">
        O KeruGás combina atualizações dos próprios postos ( operador do posto)
        com confirmações da comunidade, para responder rapidamente a uma pergunta simples:
        onde há gás agora?
      </p>

      <div className="mt-10 space-y-8">
        {steps.map(({ icon: Icon, title, text }) => (
          <div key={title} className="flex gap-4">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
              <Icon size={18} />
            </div>
            <div>
              <h2 className="font-semibold text-dark">{title}</h2>
              <p className="mt-1 text-sm text-gray">{text}</p>
            </div>
          </div>
        ))}
      </div>

      <div className="mt-12 rounded-xl border border-gray/15 bg-light p-6">
        <h2 className="font-semibold text-dark">De onde vem a informação?</h2>
        <p className="mt-2 text-sm text-gray">
          Os postos parceiros atualizam o seu estado no painel do operador, e a comunidade confirma o que vê
          no local. Cada atualização perde confiança com o tempo,por isso mostramos sempre
          há quanto tempo foi a última atualização, e nunca declaramos uma informação como
          "100% garantida".
        </p>
      </div>

      <FaqSection className="mt-12" />

      <div className="mt-8 flex gap-3">
        <Link to="/encontrar-gas" className="rounded-lg bg-primary px-5 py-2.5 text-sm font-semibold text-white">
          Encontrar gás agora
        </Link>
        <Link to="/sobre" className="rounded-lg border border-gray/20 px-5 py-2.5 text-sm font-medium text-dark">
          Sobre o KeruGás
        </Link>
      </div>
    </div>
  )
}
