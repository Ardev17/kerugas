import { Link, NavLink, useNavigate } from 'react-router-dom'
import { canEnterAdminPanel } from '@/lib/roles'
import { Avatar } from '@/components/Avatar'
import { Menu, X, LogOut, LayoutDashboard, ShieldCheck, Sun, Moon, Truck, Package, TrendingUp } from 'lucide-react'
import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useAuth } from '@/hooks/useAuth'
import { authService } from '@/services/authService'
import { confirmLogoutWithPendingPickups } from '@/lib/pickupQueue'
import { useAuthStore } from '@/stores/authStore'
import { useThemeStore } from '@/stores/themeStore'
import { NotificationsBell } from '@/components/NotificationsBell'
import { CommunityButton } from '@/components/CommunityButton'
import { DownloadAppButton } from '@/components/DownloadAppButton'
import { isNativeApp } from '@/native/platform'
import { useCentralChat } from '@/hooks/useCentralChat'

const navLinks = [
  { to: '/encontrar-gas', label: 'Encontrar gás' },
  { to: '/mapa', label: 'Mapa' },
  { to: '/pedir-gas', label: 'Pedir gás' },
]

export function Header() {
  const [open, setOpen] = useState(false)
  const { user, isAuthenticated } = useAuth()
  const central = useCentralChat()
  const clearSession = useAuthStore((s) => s.clearSession)
  const { theme, toggle: toggleTheme } = useThemeStore()
  const navigate = useNavigate()

  const logoutMutation = useMutation({
    mutationFn: authService.logout,
    onSettled: () => {
      clearSession()
      navigate('/')
    },
  })

  // App Android (Setembro 2026): cabeçalho compacto,a navegação vive
  // na barra inferior e o resto no ecrã Menu. Estende-se por baixo da
  // barra de estado do sistema (margem segura em globals.css).
  if (isNativeApp) {
    return (
      <header className="native-header sticky top-0 z-40 border-b border-gray/10 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-14 max-w-6xl items-center justify-between px-4">
          <Link to="/" className="flex items-center gap-2">
            <img src="/branding/logo-128.webp" alt="" className="h-9 w-9 object-contain" width={36} height={36} />
            <span className="text-lg font-bold tracking-tight text-dark">KeruGás</span>
          </Link>
          {isAuthenticated ? (
            <div className="flex items-center gap-2">
              <CommunityButton />
              <NotificationsBell />
            </div>
          ) : (
            <Link to="/login" className="rounded-full bg-primary px-4 py-1.5 text-sm font-semibold text-white">
              Entrar
            </Link>
          )}
        </div>
      </header>
    )
  }

  return (
    <header className="sticky top-0 z-40 border-b border-gray/10 bg-white/95 backdrop-blur">
      <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4">
        <Link to="/" className="flex items-center gap-2">
          <img src="/branding/logo-128.webp" alt="KeruGás" className="h-11 w-11 object-contain" width={44} height={44} />
          <span className="text-lg font-bold tracking-tight text-dark">KeruGás</span>
        </Link>

        <nav className="hidden items-center gap-6 md:flex" aria-label="Navegação principal">
          {navLinks.map((link) => (
            <NavLink
              key={link.to}
              to={link.to}
              className={({ isActive }) =>
                `text-sm font-medium transition-colors ${isActive ? 'text-primary' : 'text-gray hover:text-dark'}`
              }
            >
              {link.label}
            </NavLink>
          ))}
        </nav>

        <div className="hidden items-center gap-3 md:flex">
          <button
            onClick={toggleTheme}
            aria-label={theme === 'dark' ? 'Ativar modo claro' : 'Ativar modo escuro'}
            className="flex h-9 w-9 items-center justify-center rounded-lg text-gray hover:bg-light hover:text-dark"
          >
            {theme === 'dark' ? <Sun size={17} /> : <Moon size={17} />}
          </button>
          <DownloadAppButton />
          {isAuthenticated ? (
            <>
              {/* Vizinhos em destaque: só o ícone em ecrãs médios, com o nome em ecrãs largos. */}
              <CommunityButton variant="compact" className="lg:hidden" />
              <CommunityButton className="hidden lg:inline-flex" />
              <NotificationsBell />
              {canEnterAdminPanel(user) && (
                <Link to="/admin" className="flex items-center gap-1.5 text-sm font-medium text-primary hover:opacity-80">
                  <ShieldCheck size={16} /> Painel Admin
                </Link>
              )}
              {user?.role === 'USER' && (
                <Link to="/pedidos" className="flex items-center gap-1.5 text-sm font-medium text-gray hover:text-dark">
                  <Package size={16} /> Meus pedidos
                </Link>
              )}
              {user?.role === 'STATION_MANAGER' && (
                <>
                  <Link to="/posto" className="text-sm font-medium text-gray hover:text-dark">
                    Gestão do posto
                  </Link>
                  <Link to="/operator" className="text-sm font-medium text-gray hover:text-dark">
                    Estado do gás
                  </Link>
                </>
              )}
              {user?.role === 'STATION_OPERATOR' && (
                <>
                  <Link to="/operator" className="text-sm font-medium text-gray hover:text-dark">
                    Meu posto
                  </Link>
                  <Link to="/operator/analytics" className="text-sm font-medium text-gray hover:text-dark">
                    Analytics
                  </Link>
                </>
              )}
              {/* Condutor (Setembro 2026): painel dedicado, substitui o
                  /dashboard genérico de cliente,não faz sentido para
                  esta conta. */}
              {/* Investidor (Outubro 2026): a faturação dos seus municípios. */}
              {user?.role === 'INVESTOR' && (
                <Link to="/investidor" className="flex items-center gap-1.5 text-sm font-medium text-primary hover:opacity-80">
                  <TrendingUp size={16} /> Investidor
                </Link>
              )}
              {user?.role === 'DRIVER' || user?.role === 'STATION_DRIVER' ? (
                <Link to="/driver" className="flex items-center gap-1.5 text-sm font-medium text-primary hover:opacity-80">
                  <Truck size={16} /> Painel do Condutor
                </Link>
              ) : (
                <Link to="/dashboard" className="flex items-center gap-1.5 text-sm font-medium text-gray hover:text-dark">
                  <LayoutDashboard size={16} /> {user?.name?.split(' ')[0]}
                </Link>
              )}
              <Link to="/perfil" aria-label="Meu perfil" title="Meu perfil" className="flex h-9 w-9 items-center justify-center rounded-full hover:ring-2 hover:ring-primary/30">
                <Avatar name={user?.name} url={user?.avatar_url} size="sm" zoomable={false} />
              </Link>
              <button
                onClick={() => { if (confirmLogoutWithPendingPickups()) logoutMutation.mutate() }}
                className="flex items-center gap-1.5 rounded-lg border border-gray/20 px-3 py-2 text-sm font-medium text-dark hover:bg-light"
              >
                <LogOut size={14} /> Sair
              </button>
            </>
          ) : (
            <>
              <Link to="/login" className="text-sm font-medium text-gray hover:text-dark">
                Entrar
              </Link>
              <Link
                to="/register"
                className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition-opacity hover:opacity-90"
              >
                Criar conta
              </Link>
            </>
          )}
        </div>

        <div className="flex items-center gap-3 md:hidden">
          <CommunityButton onNavigate={() => setOpen(false)} />
          <button
            onClick={() => setOpen((v) => !v)}
            aria-label={open ? 'Fechar menu' : 'Abrir menu'}
            aria-expanded={open}
          >
            {open ? <X size={24} /> : <Menu size={24} />}
          </button>
        </div>
      </div>

      {open && (
        <nav className="max-h-[calc(100dvh-4rem)] overflow-y-auto overscroll-contain border-t border-gray/10 bg-white px-4 py-3 md:hidden" aria-label="Navegação móvel">
          <div className="flex flex-col gap-3">
            {navLinks.map((link) => (
              <NavLink key={link.to} to={link.to} className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                {link.label}
              </NavLink>
            ))}
            <DownloadAppButton variant="row" onNavigate={() => setOpen(false)} />
            <hr className="border-gray/10" />
            <button
              onClick={toggleTheme}
              className="flex items-center gap-2 py-2 text-sm font-medium text-dark"
            >
              {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
              {theme === 'dark' ? 'Modo claro' : 'Modo escuro'}
            </button>
            {isAuthenticated ? (
              <>
                {canEnterAdminPanel(user) && (
                  <Link to="/admin" className="py-2 text-sm font-semibold text-primary" onClick={() => setOpen(false)}>
                    Painel Admin
                  </Link>
                )}
                {user?.role === 'USER' && (
                  <Link to="/pedidos" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                    Meus pedidos
                  </Link>
                )}
                {user?.role === 'STATION_MANAGER' && (
                  <>
                    <Link to="/posto" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                      Gestão do posto
                    </Link>
                    <Link to="/operator" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                      Estado do gás
                    </Link>
                  </>
                )}
                {user?.role === 'STATION_OPERATOR' && (
                  <>
                    <Link to="/operator" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                      Meu posto
                    </Link>
                    <Link to="/operator/analytics" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                      Analytics
                    </Link>
                  </>
                )}
                {user?.role === 'INVESTOR' && (
                  <Link to="/investidor" className="py-2 text-sm font-semibold text-primary" onClick={() => setOpen(false)}>
                    Painel do investidor
                  </Link>
                )}
                {user?.role === 'DRIVER' || user?.role === 'STATION_DRIVER' ? (
                  <Link to="/driver" className="py-2 text-sm font-semibold text-primary" onClick={() => setOpen(false)}>
                    Painel do Condutor
                  </Link>
                ) : (
                  <Link to="/dashboard" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                    Painel ({user?.name?.split(' ')[0]})
                  </Link>
                )}
                <Link to="/perfil" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                  Meu perfil
                </Link>
                {/* Conversa com a Central (Outubro 2026): discreto, só depois de a direção escrever. */}
                {central.available && (
                  <Link to="/conversa-central" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                    Conversa com a Central{central.unread > 0 ? ` (${central.unread})` : ''}
                  </Link>
                )}
                <button
                  onClick={() => { if (confirmLogoutWithPendingPickups()) logoutMutation.mutate(); setOpen(false) }}
                  className="py-2 text-left text-sm font-medium text-danger"
                >
                  Sair
                </button>
              </>
            ) : (
              <>
                <Link to="/login" className="py-2 text-sm font-medium text-dark" onClick={() => setOpen(false)}>
                  Entrar
                </Link>
                <Link to="/register" className="py-2 text-sm font-semibold text-primary" onClick={() => setOpen(false)}>
                  Criar conta
                </Link>
              </>
            )}
          </div>
        </nav>
      )}
    </header>
  )
}
