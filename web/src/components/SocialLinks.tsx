import { SOCIAL_LINKS } from '@/constants/socials'
import { FacebookGlyph, InstagramGlyph, TikTokGlyph, WhatsAppGlyph } from '@/components/icons/SocialIcons'

const ICON_BY_NETWORK = {
  Facebook: FacebookGlyph,
  Instagram: InstagramGlyph,
  TikTok: TikTokGlyph,
  WhatsApp: WhatsAppGlyph,
}

/**
 * Links de redes sociais do KERUGAS (Setembro 2026),pedido explícito
 * do cliente, presentes no rodapé e noutras partes relevantes do
 * site (Contacto, Sobre). Os URLs em si são fictícios por agora (ver
 * `constants/socials.ts`),um único sítio a editar quando o cliente
 * fornecer os reais.
 *
 * `variant="icons"` (rodapé/cabeçalho: só os ícones, compacto);
 * `variant="buttons"` (Contacto/Sobre: ícone + nome, mais visível).
 */
export function SocialLinks({ variant = 'icons', className }: { variant?: 'icons' | 'buttons'; className?: string }) {
  if (variant === 'buttons') {
    return (
      <div className={`grid gap-2 sm:grid-cols-2 ${className ?? ''}`}>
        {SOCIAL_LINKS.map((s) => {
          const Icon = ICON_BY_NETWORK[s.network]
          return (
            <a
              key={s.network}
              href={s.url}
              target="_blank"
              rel="noreferrer"
              className="flex items-center gap-2 rounded-lg border border-primary/30 bg-primary/5 px-3 py-2.5 text-sm font-medium text-primary hover:bg-primary/10"
            >
              <Icon size={16} />
              {s.label}
            </a>
          )
        })}
      </div>
    )
  }

  return (
    <div className={`flex items-center gap-3 ${className ?? ''}`}>
      {SOCIAL_LINKS.map((s) => {
        const Icon = ICON_BY_NETWORK[s.network]
        return (
          <a
            key={s.network}
            href={s.url}
            target="_blank"
            rel="noreferrer"
            aria-label={s.label}
            className="text-gray transition-colors hover:text-white"
          >
            <Icon size={18} />
          </a>
        )
      })}
    </div>
  )
}
