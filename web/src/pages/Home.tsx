import { useEffect } from 'react'
import { FaqSection } from '@/components/FaqSection'
import { Link } from 'react-router-dom'
import { MapPin, Search, PackagePlus, ArrowRight, Gauge, Truck, Flame, Bell, Users, Navigation, Radio } from 'lucide-react'
import { analyticsService } from '@/services/analyticsService'
import { CentralContactLinks } from '@/components/CentralContactLinks'
import { EmberField } from '@/components/fx/EmberField'
import { GasFlame } from '@/components/fx/GasFlame'
import { Magnetic } from '@/components/fx/Magnetic'
import { Reveal } from '@/components/fx/Reveal'
import { ScrollProgress } from '@/components/fx/ScrollProgress'
import { Spotlight } from '@/components/fx/Spotlight'
import { TiltCard } from '@/components/fx/TiltCard'

/** Palavras que entram uma a uma, com atraso crescente. */
function Words({ text, start = 0, gradient = false }: { text: string; start?: number; gradient?: boolean }) {
  return (
    <span className="fx-words">
      {text.split(' ').map((w, i) => (
        <span key={i} style={{ animationDelay: `${start + i * 90}ms` }}>
          {/* O gradiente vai num span interior: num inline-block animado o
              "background-clip: text" do pai não chega às palavras. */}
          {gradient ? <span className="fx-gradient-text">{w}</span> : w}
          {'\u00A0'}
        </span>
      ))}
    </span>
  )
}

const TICKER = [
  { icon: Radio, text: 'Disponibilidade em tempo real' },
  { icon: Gauge, text: 'Filas em direto' },
  { icon: Truck, text: 'Entrega onde estiver' },
  { icon: Bell, text: 'Alertas no seu município' },
  { icon: Users, text: 'Vizinhos KeruGás' },
  { icon: Navigation, text: 'Rota até ao posto' },
]

const STEPS = [
  { icon: MapPin, title: 'Localização', text: 'Partilhe a sua localização ou selecione a sua província e município.' },
  { icon: Gauge, title: 'Disponibilidade', text: 'Veja quais postos têm gás, o nível da fila e a confiança da informação.' },
  { icon: Truck, title: 'Rota ou entrega', text: 'Trace a rota até ao posto,ou peça para receber o gás onde está.' },
]

/**
 * Homepage (secção 11 e 234). Dois CTAs principais com hierarquia clara:
 * "Encontrar gás" (discovery) e "Pedir gás" (delivery).
 *
 * Outubro 2026,"Fogo e energia": brasas a subir (toque para soltar
 * faíscas), título palavra a palavra, imagem em 3D com borda de luz,
 * etiquetas ao vivo a flutuar, faixa a correr, cartões com foco de luz e
 * revelação ao rolar. Tudo se adapta ao aparelho (lib/motion.ts).
 *
 * Imagens de marca (fornecidas pelo cliente):
 * - hero-realtime-desktop.webp: visual do hero.
 * - hero-mobile-promo.webp: banner vertical da secção de fecho.
 */
export default function Home() {
  useEffect(() => {
    analyticsService.track('page_view', { page: 'home' })
  }, [])

  return (
    <div className="overflow-x-clip">
      <ScrollProgress />

      {/* ---------------- HERO ---------------- */}
      <section className="relative isolate overflow-hidden">
        <div className="fx-aurora" aria-hidden="true"><i /></div>
        <div className="fx-grid" aria-hidden="true" />
        <EmberField />

        <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 pb-20 pt-12 md:min-h-[calc(100vh-4rem)] md:grid-cols-2 md:py-20">
          <div>
            <p className="fx-stagger">
              <span className="fx-chip inline-flex items-center gap-2 rounded-full border border-primary/25 bg-white/60 px-3 py-1.5 text-xs font-semibold text-dark">
                <span className="fx-live-dot" aria-hidden="true" />
                Ao vivo · postos atualizados em tempo real
              </span>
            </p>

            <h1 className="mt-5 text-4xl font-extrabold leading-[1.05] tracking-tight text-dark sm:text-5xl md:text-6xl">
              <Words text="Encontre gás" start={150} />
              <br />
              <Words text="antes de sair de casa." start={350} gradient />
            </h1>

            <div className="fx-stagger" style={{ ['--stagger-base' as string]: '0.6s' }}>
              <p className="mt-5 max-w-lg text-lg text-gray">
                Descubra onde existe gás butano, veja o nível da fila e encontre
                o posto mais conveniente perto de si. Ou peça para receber.
              </p>
            </div>

            <div className="fx-stagger mt-8 flex flex-col gap-3 sm:flex-row" style={{ ['--stagger-base' as string]: '0.75s' }}>
              <Magnetic className="w-full sm:w-auto">
                <Link
                  to="/encontrar-gas"
                  className="fx-btn-fire flex w-full items-center justify-center gap-2 rounded-xl px-7 py-3.5 font-semibold text-white sm:w-auto"
                >
                  <Search size={18} aria-hidden="true" />
                  Encontrar gás agora
                </Link>
              </Magnetic>
              <Magnetic className="w-full sm:w-auto">
                <Link
                  to="/pedir-gas"
                  className="fx-btn-ghost flex w-full items-center justify-center gap-2 rounded-xl border-2 border-primary px-7 py-3.5 font-semibold text-primary hover:bg-primary/5 sm:w-auto"
                >
                  <PackagePlus size={18} aria-hidden="true" />
                  Pedir gás
                </Link>
              </Magnetic>
            </div>

            <div className="fx-stagger" style={{ ['--stagger-base' as string]: '0.95s' }}>
              <Link to="/mapa" className="group mt-5 inline-flex items-center gap-1.5 text-sm font-medium text-gray hover:text-primary">
                <MapPin size={14} aria-hidden="true" className="transition-transform group-hover:-translate-y-0.5" />
                Ver mapa completo
                <ArrowRight size={14} aria-hidden="true" className="transition-transform group-hover:translate-x-1" />
              </Link>
            </div>
          </div>

          <Reveal effect="zoom" delay={250} className="relative">
            <TiltCard className="rounded-3xl">
              <div className="fx-ring-border rounded-3xl">
                <div className="aspect-video overflow-hidden rounded-3xl bg-dark md:aspect-[4/3]">
                  <img
                    src="/branding/hero-realtime-desktop.webp"
                    alt="Painel de monitorização em tempo real do KeruGás, mostrando estoque disponível e fila de espera"
                    className="h-full w-full object-cover"
                    width={1344}
                    height={768}
                    loading="eager"
                    // react-dom 18 ainda não conhece `fetchPriority`: atributo HTML em minúsculas.
                    {...{ fetchpriority: 'high' }}
                    decoding="async"
                  />
                </div>
              </div>
            </TiltCard>

            {/* Etiquetas ilustrativas que flutuam à volta da imagem. */}
            <div className="fx-float fx-chip absolute -left-3 top-6 flex items-center gap-2 rounded-2xl border border-white/20 bg-white/80 px-3 py-2 text-xs font-semibold text-dark shadow-xl sm:-left-8" aria-hidden="true">
              <span className="fx-live-dot" />
              Gás disponível
            </div>
            <div className="fx-float-slow fx-chip absolute -right-2 bottom-10 flex items-center gap-2 rounded-2xl border border-white/20 bg-white/80 px-3 py-2 text-xs font-semibold text-dark shadow-xl sm:-right-6" aria-hidden="true">
              <Truck size={15} className="text-primary" />
              Entrega a caminho
            </div>
            <div className="fx-float pointer-events-none absolute -bottom-10 left-6 hidden sm:block" aria-hidden="true">
              <GasFlame size={58} />
            </div>
          </Reveal>
        </div>
      </section>

      {/* ---------------- FAIXA A CORRER ---------------- */}
      <section className="border-y border-gray/10 bg-white py-4" aria-label="O que a KeruGás faz">
        <div className="fx-marquee">
          <ul className="fx-marquee-track">
            {[...TICKER, ...TICKER].map(({ icon: Icon, text }, i) => (
              <li key={i} className="flex items-center gap-2 px-6 text-sm font-semibold text-dark" aria-hidden={i >= TICKER.length}>
                <Icon size={16} className="text-primary" aria-hidden="true" />
                {text}
                <Flame size={14} className="ml-6 text-orange-500" aria-hidden="true" />
              </li>
            ))}
          </ul>
        </div>
      </section>

      {/* ---------------- COMO FUNCIONA ---------------- */}
      <section className="relative overflow-hidden bg-white py-20">
        <div className="mx-auto max-w-6xl px-4">
          <Reveal className="text-center">
            <p className="text-xs font-bold uppercase tracking-[0.25em] text-primary">Simples assim</p>
            <h2 className="mt-2 text-3xl font-extrabold text-dark md:text-4xl">
              Como <span className="fx-gradient-text">funciona</span>
            </h2>
          </Reveal>

          <Reveal className="relative mt-14">
            <div className="fx-draw-line absolute left-[16%] right-[16%] top-[52px] hidden h-0.5 rounded bg-gradient-to-r from-primary via-secondary to-orange-500 md:block" aria-hidden="true" />
            <div className="grid gap-6 md:grid-cols-3">
              {STEPS.map((item, i) => (
                <Reveal key={item.title} delay={i * 140} effect="up">
                  <Spotlight className="h-full rounded-3xl border border-gray/10 bg-white p-7 text-center">
                    <div
                      className="fx-step-icon mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-secondary text-white shadow-lg shadow-primary/30"
                      style={{ ['--pulse-delay' as string]: `${i * 0.6}s` }}
                    >
                      <item.icon size={28} aria-hidden="true" />
                    </div>
                    <p className="fx-gradient-text mt-5 text-sm font-black tracking-widest">PASSO {i + 1}</p>
                    <h3 className="mt-1 text-lg font-bold text-dark">{item.title}</h3>
                    <p className="mt-2 text-sm text-gray">{item.text}</p>
                  </Spotlight>
                </Reveal>
              ))}
            </div>
          </Reveal>
        </div>
      </section>

      {/* ---------------- FECHO ---------------- */}
      <section className="relative isolate overflow-hidden bg-dark py-20 text-light">
        <div className="fx-aurora opacity-70" aria-hidden="true"><i /></div>
        <EmberField density={0.5} />
        <div className="relative mx-auto grid max-w-6xl items-center gap-12 px-4 md:grid-cols-2">
          <Reveal effect="left" className="order-2 flex justify-center md:order-1">
            <div className="fx-float-slow">
              <div className="fx-ring-border rounded-[2rem]">
                <img
                  src="/branding/hero-mobile-promo.webp"
                  alt="Ilustração do KeruGás mostrando monitorização de estoque e filas de gás em tempo real, através de telemóvel"
                  className="w-full max-w-xs rounded-[2rem] sm:max-w-sm"
                  width={768}
                  height={1344}
                  loading="lazy"
                  decoding="async"
                />
              </div>
            </div>
          </Reveal>

          <Reveal effect="right" delay={120} className="order-1 text-center md:order-2 md:text-left">
            <GasFlame size={52} className="mb-4" />
            <h2 className="text-3xl font-extrabold leading-tight md:text-5xl">
              Nunca mais perca tempo <span className="fx-gradient-text">à procura de gás.</span>
            </h2>
            <p className="mt-5 text-light/70">
              Registe-se gratuitamente, ative alertas para o seu município e
              seja avisado assim que houver gás disponível perto de si,
              ou peça já a entrega, sem sair de casa.
            </p>

            <div className="mt-8 flex flex-col justify-center gap-3 sm:flex-row md:justify-start">
              <Magnetic className="w-full sm:w-auto">
                <Link
                  to="/register"
                  className="fx-btn-fire flex w-full items-center justify-center gap-2 rounded-xl px-7 py-3.5 font-semibold text-white sm:w-auto"
                >
                  Criar conta grátis
                  <ArrowRight size={16} aria-hidden="true" />
                </Link>
              </Magnetic>
              <Magnetic className="w-full sm:w-auto">
                <Link
                  to="/pedir-gas"
                  className="fx-btn-ghost flex w-full items-center justify-center gap-2 rounded-xl border-2 border-light/30 px-7 py-3.5 font-semibold text-light hover:bg-white/5 sm:w-auto"
                >
                  Pedir gás agora
                </Link>
              </Magnetic>
            </div>
          </Reveal>
        </div>
      </section>

      {/* ---------------- PERGUNTAS FREQUENTES (SEO/GEO, Outubro 2026) ---------------- */}
      <div className="mx-auto max-w-3xl px-4 py-16">
        <Reveal>
          <FaqSection />
        </Reveal>
      </div>

      {/* ---------------- CONTACTO ---------------- */}
      <section className="border-t border-gray/10 bg-light py-12">
        <Reveal className="mx-auto max-w-3xl px-4 text-center">
          <p className="font-semibold text-dark">Precisa de falar connosco agora?</p>
          <p className="mt-1 text-sm text-gray">Ligue diretamente para a central KeruGás,atendimento em qualquer operadora.</p>
          <div className="mt-4 flex justify-center">
            <CentralContactLinks />
          </div>
        </Reveal>
      </section>
    </div>
  )
}
