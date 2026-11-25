/** Contacto do programador: abre uma conversa no WhatsApp (+244 936 268 639). */
export const DEVELOPER_WHATSAPP_URL = 'https://wa.me/244936268639'

export function PoweredBy({ className = '' }: { className?: string }) {
  return (
    <>
      Powered by{' '}
      <a
        href={DEVELOPER_WHATSAPP_URL}
        target="_blank"
        rel="noopener noreferrer"
        aria-label="Falar com Eng. Aristides Chiovo no WhatsApp"
        className={`font-medium underline-offset-2 hover:text-primary hover:underline ${className}`}
      >
        Eng. Aristides Chiovo
      </a>
    </>
  )
}
