import { ChevronDown } from 'lucide-react'
import { FAQ_GERAL, type SeoFaq } from '@/seo/pages'

/**
 * Perguntas frequentes (Outubro 2026, SEO/GEO). O texto vem de
 * src/seo/pages.ts,o MESMO que vai para os dados estruturados (FAQPage) e
 * para o HTML pré-gerado. O Google exige que as perguntas do FAQPage estejam
 * visíveis na página; e as IAs citam respostas curtas e diretas como estas.
 */
export function FaqSection({ items = FAQ_GERAL, title = 'Perguntas frequentes', className = '' }: { items?: SeoFaq[]; title?: string; className?: string }) {
  return (
    <section aria-labelledby="faq-title" className={className}>
      <h2 id="faq-title" className="text-xl font-bold text-dark">{title}</h2>
      <div className="mt-4 divide-y divide-gray/10 rounded-2xl border border-gray/15">
        {items.map((f) => (
          <details key={f.q} className="group px-4 py-3">
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-semibold text-dark">
              <h3 className="text-base">{f.q}</h3>
              <ChevronDown size={18} aria-hidden="true" className="shrink-0 text-gray transition-transform group-open:rotate-180" />
            </summary>
            <p className="mt-2 text-sm text-gray">{f.a}</p>
          </details>
        ))}
      </div>
    </section>
  )
}
