/**
 * Redes sociais do KERUGAS,pedido explícito do cliente, visível no
 * rodapé e noutras partes relevantes do site (Contacto, Sobre).
 *
 * IMPORTANTE: os links de Facebook/Instagram/TikTok abaixo ainda são
 * FICTÍCIOS (pedido explícito do cliente, "para depois eu alterar")
 *,substitua pelos links/handles reais antes de pôr em produção. Um
 * único sítio a editar; todo o site lê daqui, mesmo padrão já usado
 * em `constants/contact.ts` para os números da Central.
 *
 * O WhatsApp já usa o número oficial (Nov. 2026): +244 922 761 180.
 * Formato `https://wa.me/<número sem "+" nem espaços>`,manter esse
 * formato para o link abrir o chat diretamente (não `tel:`, que
 * abriria o discador normal).
 */
export interface SocialLink {
  network: 'Facebook' | 'Instagram' | 'TikTok' | 'WhatsApp'
  url: string
  label: string
}

export const SOCIAL_LINKS: SocialLink[] = [
  { network: 'Facebook', url: 'https://www.facebook.com/profile.php?id=61596145942489', label: 'Facebook' },
  { network: 'Instagram', url: 'https://www.instagram.com/kerugasangola/', label: 'Instagram' },
  { network: 'TikTok', url: 'https://www.facebook.com/profile.php?id=61596145942489', label: 'TikTok' },
  { network: 'WhatsApp', url: 'https://wa.me/244922761180', label: 'WhatsApp' },
]
