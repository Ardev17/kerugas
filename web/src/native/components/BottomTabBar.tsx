import { NavLink } from 'react-router-dom'
import { Home, Search, Flame, Map, Menu, Truck, Store, BarChart3, ShieldCheck, TrendingUp, type LucideIcon } from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { tapFeedback } from '@/native/haptics'

interface Tab {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
  /** Ação principal do papel,botão central em destaque. */
  primary?: boolean
}

/**
 * Cada papel vê a navegação que corresponde ao seu trabalho. O cliente
 * tem "Pedir" em destaque; o condutor tem as entregas; o operador o seu
 * posto; a equipa de administração o painel. Tudo o resto vive no Menu.
 */
function tabsFor(role: string | undefined): Tab[] {
  switch (role) {
    case 'DRIVER':
    case 'STATION_DRIVER':
      return [
        { to: '/', label: 'Início', icon: Home, end: true },
        { to: '/mapa', label: 'Mapa', icon: Map },
        { to: '/driver', label: 'Entregas', icon: Truck, primary: true },
        { to: '/encontrar-gas', label: 'Postos', icon: Search },
        { to: '/menu', label: 'Menu', icon: Menu },
      ]
    case 'INVESTOR':
      return [
        { to: '/', label: 'Início', icon: Home, end: true },
        { to: '/mapa', label: 'Mapa', icon: Map },
        { to: '/investidor', label: 'Faturação', icon: TrendingUp, primary: true },
        { to: '/encontrar-gas', label: 'Postos', icon: Search },
        { to: '/menu', label: 'Menu', icon: Menu },
      ]
    case 'STATION_MANAGER':
      return [
        { to: '/', label: 'Início', icon: Home, end: true },
        { to: '/operator', label: 'Gás', icon: Flame, end: true },
        { to: '/posto', label: 'Meu posto', icon: Store, primary: true },
        { to: '/encontrar-gas', label: 'Postos', icon: Search },
        { to: '/menu', label: 'Menu', icon: Menu },
      ]
    case 'STATION_OPERATOR':
      return [
        { to: '/', label: 'Início', icon: Home, end: true },
        { to: '/mapa', label: 'Mapa', icon: Map },
        { to: '/operator', label: 'Meu posto', icon: Store, primary: true, end: true },
        { to: '/operator/analytics', label: 'Análise', icon: BarChart3 },
        { to: '/menu', label: 'Menu', icon: Menu },
      ]
    case 'ADMIN':
    case 'SUPER_ADMIN':
    case 'PROVINCE_SUPER_ADMIN':
    case 'MODERATOR':
      return [
        { to: '/', label: 'Início', icon: Home, end: true },
        { to: '/mapa', label: 'Mapa', icon: Map },
        { to: '/admin', label: 'Painel', icon: ShieldCheck, primary: true },
        { to: '/encontrar-gas', label: 'Postos', icon: Search },
        { to: '/menu', label: 'Menu', icon: Menu },
      ]
    default:
      return [
        { to: '/', label: 'Início', icon: Home, end: true },
        { to: '/encontrar-gas', label: 'Encontrar', icon: Search },
        { to: '/pedir-gas', label: 'Pedir gás', icon: Flame, primary: true },
        { to: '/mapa', label: 'Mapa', icon: Map },
        { to: '/menu', label: 'Menu', icon: Menu },
      ]
  }
}

/**
 * Barra de navegação inferior do app Android (Setembro 2026). Fica por
 * cima da barra de gestos do sistema (margem segura em globals.css) e
 * esconde-se enquanto o teclado está aberto, para nunca tapar o campo
 * onde a pessoa está a escrever.
 */
export function BottomTabBar() {
  const { user } = useAuth()
  const tabs = tabsFor(user?.role)

  return (
    <nav className="native-tabbar" aria-label="Navegação principal">
      <ul className="mx-auto flex max-w-lg items-end justify-around px-2">
        {tabs.map(({ to, label, icon: Icon, end, primary }) => (
          <li key={to} className="flex-1">
            <NavLink
              to={to}
              end={end}
              onClick={() => void tapFeedback()}
              className={({ isActive }) =>
                `group flex flex-col items-center gap-0.5 pb-1.5 pt-2 text-[11px] font-medium transition-colors ${
                  isActive ? 'text-primary' : 'text-gray'
                }`
              }
            >
              {({ isActive }) =>
                primary ? (
                  <>
                    <span
                      className={`-mt-5 flex h-12 w-12 items-center justify-center rounded-full bg-gradient-to-br from-primary to-secondary text-white shadow-lg shadow-primary/30 ring-4 ring-[var(--tabbar-bg)] transition-transform group-active:scale-95 ${
                        isActive ? 'fx-tab-primary fx-tab-pop scale-105' : ''
                      }`}
                    >
                      <Icon size={22} strokeWidth={2.2} />
                    </span>
                    <span className={isActive ? 'font-semibold' : ''}>{label}</span>
                  </>
                ) : (
                  <>
                    <span
                      className={`flex h-7 w-12 items-center justify-center rounded-full transition-colors ${
                        isActive ? 'fx-tab-pop bg-primary/10' : ''
                      }`}
                    >
                      <Icon size={21} strokeWidth={isActive ? 2.3 : 1.9} />
                    </span>
                    <span className={isActive ? 'font-semibold' : ''}>{label}</span>
                  </>
                )
              }
            </NavLink>
          </li>
        ))}
      </ul>
    </nav>
  )
}
