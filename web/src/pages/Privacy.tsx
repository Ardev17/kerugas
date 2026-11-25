import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'

/**
 * Política de Privacidade (atualizada em Outubro de 2026: foto de perfil
 * pública, eliminação de conta, relatórios de erros, app Android). Tem de
 * bater certo com o formulário "Segurança dos dados" da Google Play,ver
 * docs/play-store-data-safety.md.
 */
export default function Privacy() {
  return (
    <div className="mx-auto max-w-2xl px-4 py-16">
      <h1 className="text-3xl font-bold text-dark">Política de Privacidade</h1>
      

      <div className="mt-8 space-y-6 text-sm text-gray">
        <Section title="1. Quem somos">
          A KeruGás (kerugas.ao e app KeruGás) mostra a disponibilidade de gás nos postos e organiza pedidos e
          entregas de gás em Angola. Para questões de privacidade use a página de <Link to="/contacto" className="font-medium text-primary">Contacto</Link>.
        </Section>

        <Section title="2. Dados que recolhemos">
          <ul className="list-disc space-y-1 pl-5">
            <li><strong className="text-dark">Conta:</strong> nome, número de telefone, email (opcional), palavra-passe (guardada cifrada), província e município.</li>
            <li><strong className="text-dark">Foto de perfil (opcional):</strong> a imagem que escolher, recortada e reduzida.</li>
            <li><strong className="text-dark">Localização (opcional):</strong> só quando autoriza no telemóvel ou no navegador,para mostrar postos perto de si e para a entrega.</li>
            <li><strong className="text-dark">Pedidos de gás:</strong> morada e ponto de referência da entrega, quantidade, estado e valor.</li>
            <li><strong className="text-dark">Conteúdos que publica:</strong> comentários e avaliações de postos, confirmações de disponibilidade e mensagens do chat.</li>
            <li><strong className="text-dark">Dispositivo:</strong> identificador para notificações push e a versão da app.</li>
            
          </ul>
        </Section>

        <Section title="3. Como usamos os dados">
          Para criar e proteger a sua conta, mostrar postos relevantes, processar e entregar pedidos, enviar os avisos e
          notificações que ativar (pode desligá-los em Preferências de notificações), moderar conteúdos, prevenir fraude e
          corrigir erros da app. Não vendemos dados pessoais nem os usamos para publicidade de terceiros.
        </Section>

        <Section title="4. O que fica visível para outras pessoas">
          A <strong className="text-dark">foto de perfil e o nome</strong> aparecem junto dos seus comentários, avaliações e mensagens, e,no caso
          dos condutores,no acompanhamento do pedido do cliente. A foto é pública: pode ser vista por qualquer pessoa,
          mesmo sem conta. Pode removê-la a qualquer momento em Meu perfil. A moderação pode remover fotos impróprias.
          O seu telefone, email e localização nunca são mostrados a outros utilizadores (exceto o necessário para a entrega,
          à equipa da Kerugas e ao condutor).
          {' '}A única exceção é quando <strong className="text-dark">você</strong> escolhe "Partilhar a minha localização" nos
          Vizinhos: os vizinhos do seu município veem o ponto (ou o posto) que partilhou, só entre as 08h e as 18h, durante o
          tempo que escolher (no máximo 2 horas). Pode parar quando quiser e, quando a partilha acaba, a localização é apagada
          dos nossos servidores.
        </Section>

        <Section title="5. Com quem partilhamos">
          <ul className="list-disc space-y-1 pl-5">
            <li>Fornecedor de SMS, para enviar avisos por mensagem (por exemplo, sobre o seu pedido).</li>
            <li>Google (Firebase Cloud Messaging) e os serviços de notificações do navegador, para entregar notificações push.</li>
            <li>OpenStreetMap, para os mapas (recebe o pedido dos mapas, não os seus dados de conta).</li>
            <li>Autoridades, quando a lei o exigir.</li>
          </ul>
        </Section>

        <Section title="6. Segurança">
          Ligações cifradas (HTTPS), palavras-passe cifradas, sessões que pode terminar em Meu perfil, a sessão da app
          guardada na área segura do telemóvel e fotos analisadas e re-processadas pelo servidor antes de serem guardadas.
        </Section>

        <Section title="7. Durante quanto tempo guardamos">
          Enquanto a conta existir. Quando a elimina, os dados pessoais são apagados ao fim de 7 dias. Os pedidos e pagamentos
          ficam guardados sem os seus dados pessoais, pelo tempo exigido pela lei contabilística. Os relatórios de erros são
          apagados ao fim de 90 dias.
        </Section>

        <Section title="8. Os seus direitos e eliminação da conta">
          Pode ver e corrigir os seus dados em Meu perfil e <Link to="/eliminar-conta" className="font-medium text-primary">eliminar a conta</Link> a qualquer momento,
          na app ou no site. Para outros pedidos (por exemplo, uma cópia dos seus dados) use a página de Contacto.
        </Section>

        <Section title="9. Alterações">
          Se esta política mudar de forma importante, avisamos na app antes de a alteração entrar em vigor.
        </Section>
      </div>
    </div>
  )
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section>
      <h2 className="font-semibold text-dark">{title}</h2>
      <div className="mt-2">{children}</div>
    </section>
  )
}
