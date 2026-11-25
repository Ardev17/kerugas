import { NavLink } from 'react-router-dom'
import { HeartHandshake } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'

interface Props {
  /**
   * `pill`,ícone + "Vizinhos" (cabeçalho do site e do app).
   * `compact`,só o ícone em círculo (ao lado do botão de menu no telemóvel).
   */
  variant?: 'pill' | 'compact'
  className?: string
  onNavigate?: () => void
}

/**
 * Acesso direto ao Vizinhos KeruGás (Outubro 2026),o chat da comunidade
 * do município, para qualquer conta com sessão. Botão em destaque (cores
 * da marca) no cabeçalho, ao lado dos botões que já existiam,nenhum foi
 * retirado ou mudado de sítio. Visitantes sem sessão não o veem (a
 * comunidade exige conta).
 */
export function CommunityButton({ variant = 'pill', className = '', onNavigate }: Props) {
  const { isAuthenticated } = useAuth()
  if (!isAuthenticated) return null

  if (variant === 'compact') {
    return (
      <NavLink
        to="/comunidade"
        onClick={onNavigate}
        aria-label="Vizinhos KeruGás,chat da comunidade"
        title="Vizinhos KeruGás"
        className={({ isActive }) =>
          `flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary text-white shadow-sm shadow-primary/30 transition-transform active:scale-95 ${
            isActive ? 'ring-2 ring-primary/40 ring-offset-1' : ''
          } ${className}`
        }
      >
        <HeartHandshake size={18} strokeWidth={2.2} />
      </NavLink>
    )
  }

  return (
    <NavLink
      to="/comunidade"
      onClick={onNavigate}
      aria-label="Vizinhos KeruGás,chat da comunidade"
      title="Vizinhos KeruGás,fale com a sua comunidade"
      className={({ isActive }) =>
        `inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full bg-gradient-to-r from-primary to-secondary px-3 py-1.5 text-sm font-semibold text-white shadow-sm shadow-primary/30 transition hover:opacity-90 active:scale-95 ${
          isActive ? 'ring-2 ring-primary/40 ring-offset-1' : ''
        } ${className}`
      }
    >
      <HeartHandshake size={16} strokeWidth={2.2} />
      <span>Vizinhos</span>
    </NavLink>
  )
}
