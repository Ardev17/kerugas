import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation } from '@tanstack/react-query'
import {
  ChevronRight, LayoutDashboard, Heart, BellRing, Package, Moon, Sun, Info, HelpCircle, Mail,
  FileText, Shield, Cookie, LogOut, Truck, Store, ShieldCheck, BarChart3, UserCircle2, Bell, Star, Users, TrendingUp, Headset,
  type LucideIcon,
} from 'lucide-react'
import { useAuth } from '@/hooks/useAuth'
import { useCentralChat } from '@/hooks/useCentralChat'
import { useAuthStore } from '@/stores/authStore'
import { useThemeStore } from '@/stores/themeStore'
import { authService } from '@/services/authService'
import { confirmLogoutWithPendingPickups } from '@/lib/pickupQueue'
import { isNativeApp } from '@/native/platform'
import { tapFeedback } from '@/native/haptics'
import { SocialLinks } from '@/components/SocialLinks'

const ROLE_LABEL: Record<string, string> = {
  SUPER_ADMIN: 'Super Admin',
  PROVINCE_SUPER_ADMIN: 'Super Admin Provincial',
  ADMIN: 'Administrador',
  MODERATOR: 'Moderador',
  STATION_OPERATOR: 'Operador de posto',
  STATION_MANAGER: 'Admin de posto',
  DRIVER: 'Condutor',
  STATION_DRIVER: 'Condutor de posto',
  USER: 'Cliente',
}

interface MenuItem {
  to: string
  label: string
  icon: LucideIcon
}

/**
 * Ecrã "Menu" do app Android (Setembro 2026),substitui o menu
 * hambúrguer e o rodapé do site, que num app não fazem sentido. Tudo o
 * que o rodapé tinha (páginas institucionais, legais, redes sociais)
 * continua acessível daqui. Também funciona no site, em /menu.
 */
export default function AppMenu() {
  const { user, isAuthenticated } = useAuth()
  const clearSession = useAuthStore((s) => s.clearSession)
  const { theme, toggle } = useThemeStore()
  const navigate = useNavigate()
  const [version, setVersion] = useState<string | null>(null)

  useEffect(() => {
    if (!isNativeApp) return
    void import('@capacitor/app')
      .then(({ App }) => App.getInfo())
      .then((info) => setVersion(`${info.version} (${info.build})`))
      .catch(() => {})
  }, [])

  const logoutMutation = useMutation({
    mutationFn: authService.logout,
    onSettled: () => {
      clearSession()
      navigate('/', { replace: true })
    },
  })

  const central = useCentralChat()
  const role = user?.role
  const accountItems: MenuItem[] = []
  if (isAuthenticated) {
    if (role === 'ADMIN' || role === 'SUPER_ADMIN' || role === 'PROVINCE_SUPER_ADMIN' || role === 'MODERATOR') {
      accountItems.push({ to: '/admin', label: 'Painel administrativo', icon: ShieldCheck })
    }
    if (role === 'INVESTOR') {
      accountItems.push({ to: '/investidor', label: 'Painel do investidor', icon: TrendingUp })
    }
    if (role === 'STATION_MANAGER') {
      accountItems.push({ to: '/posto', label: 'Gestão do posto', icon: Store })
      accountItems.push({ to: '/operator', label: 'Estado do gás', icon: Store })
      accountItems.push({ to: '/operator/analytics', label: 'Análise do posto', icon: BarChart3 })
    }
    if (role === 'STATION_OPERATOR') {
      accountItems.push({ to: '/operator', label: 'O meu posto', icon: Store })
      accountItems.push({ to: '/operator/analytics', label: 'Análise do posto', icon: BarChart3 })
    }
    if (role === 'DRIVER' || role === 'STATION_DRIVER') {
      accountItems.push({ to: '/driver', label: 'Painel do condutor', icon: Truck })
      accountItems.push({ to: '/driver/avaliacoes', label: 'As minhas avaliações', icon: Star })
    } else {
      accountItems.push({ to: '/dashboard', label: 'O meu painel', icon: LayoutDashboard })
      accountItems.push({ to: '/pedidos', label: 'Os meus pedidos', icon: Package })
    }
    accountItems.push({ to: '/comunidade', label: 'Vizinhos KeruGás', icon: Users })
    accountItems.push({ to: '/favorites', label: 'Postos favoritos', icon: Heart })
    accountItems.push({ to: '/alerts', label: 'Alertas de gás', icon: BellRing })
    accountItems.push({ to: '/perfil', label: 'Meu perfil', icon: UserCircle2 })
    accountItems.push({ to: '/notificacoes', label: 'Notificações', icon: Bell })
    // Conversa com a Central (Outubro 2026): só depois de a direção escrever.
    if (central.available) accountItems.push({ to: '/conversa-central', label: central.unread > 0 ? `Conversa com a Central (${central.unread})` : 'Conversa com a Central', icon: Headset })
  }

  const infoItems: MenuItem[] = [
    { to: '/como-funciona', label: 'Como funciona', icon: HelpCircle },
    { to: '/sobre', label: 'Sobre o KeruGás', icon: Info },
    { to: '/contacto', label: 'Contacto', icon: Mail },
  ]

  const legalItems: MenuItem[] = [
    { to: '/termos', label: 'Termos de utilização', icon: FileText },
    { to: '/privacidade', label: 'Privacidade', icon: Shield },
    { to: '/cookies', label: 'Cookies', icon: Cookie },
  ]

  return (
    <div className="mx-auto max-w-lg px-4 py-6">
      {isAuthenticated && user ? (
        <div className="flex items-center gap-3 rounded-2xl bg-gradient-to-br from-primary to-secondary p-5 text-white">
          <UserCircle2 size={44} strokeWidth={1.5} />
          <div className="min-w-0">
            <p className="truncate text-lg font-bold">{user.name}</p>
            <p className="text-sm text-white/80">
              {ROLE_LABEL[user.role] ?? user.role} · {user.phone}
            </p>
          </div>
        </div>
      ) : (
        <div className="rounded-2xl bg-gradient-to-br from-primary to-secondary p-5 text-white">
          <p className="text-lg font-bold">Bem-vindo ao KeruGás</p>
          <p className="mt-1 text-sm text-white/85">Entre para pedir gás, seguir postos e receber alertas.</p>
          <div className="mt-4 flex gap-2">
            <Link to="/login" className="flex-1 rounded-lg bg-white px-4 py-2.5 text-center text-sm font-semibold text-primary">
              Entrar
            </Link>
            <Link to="/register" className="flex-1 rounded-lg border border-white/60 px-4 py-2.5 text-center text-sm font-semibold text-white">
              Criar conta
            </Link>
          </div>
        </div>
      )}

      {accountItems.length > 0 && <MenuSection title="A minha conta" items={accountItems} />}

      <section className="mt-6">
        <h2 className="px-1 text-xs font-semibold uppercase tracking-wide text-gray">Aparência</h2>
        <button
          role="switch"
          aria-checked={theme === 'dark'}
          onClick={() => { void tapFeedback(); toggle() }}
          className="mt-2 flex w-full items-center gap-3 rounded-xl border border-gray/15 bg-white px-4 py-3.5 text-left active:bg-light"
        >
          {theme === 'dark' ? <Sun size={20} className="text-gray" /> : <Moon size={20} className="text-gray" />}
          <span className="flex-1 text-sm font-medium text-dark">Modo escuro</span>
          <span
            aria-hidden="true"
            className={`relative h-6 w-11 rounded-full transition-colors ${theme === 'dark' ? 'bg-primary' : 'bg-gray/30'}`}
          >
            <span
              className={`absolute left-0 top-0.5 h-5 w-5 rounded-full bg-[#ffffff] shadow transition-transform ${theme === 'dark' ? 'translate-x-5' : 'translate-x-0.5'}`}
            />
          </span>
        </button>
      </section>

      <MenuSection title="KeruGás" items={infoItems} />
      <MenuSection title="Legal" items={legalItems} />

      {isAuthenticated && (
        <button
          onClick={() => { if (confirmLogoutWithPendingPickups()) logoutMutation.mutate() }}
          disabled={logoutMutation.isPending}
          className="mt-6 flex w-full items-center justify-center gap-2 rounded-xl border border-danger/30 bg-white px-4 py-3.5 text-sm font-semibold text-danger active:bg-danger/5 disabled:opacity-60"
        >
          <LogOut size={18} /> {logoutMutation.isPending ? 'A terminar sessão...' : 'Terminar sessão'}
        </button>
      )}

      <div className="mt-8 flex flex-col items-center gap-3 pb-4 text-center">
        <SocialLinks />
        <p className="text-xs text-gray">
          KeruGás{version ? ` · versão ${version}` : ''}
        </p>
      </div>
    </div>
  )
}

function MenuSection({ title, items }: { title: string; items: MenuItem[] }) {
  return (
    <section className="mt-6">
      <h2 className="px-1 text-xs font-semibold uppercase tracking-wide text-gray">{title}</h2>
      <ul className="mt-2 divide-y divide-gray/10 overflow-hidden rounded-xl border border-gray/15 bg-white">
        {items.map(({ to, label, icon: Icon }) => (
          <li key={to}>
            <Link to={to} onClick={() => void tapFeedback()} className="flex items-center gap-3 px-4 py-3.5 active:bg-light">
              <Icon size={20} className="text-gray" />
              <span className="flex-1 text-sm font-medium text-dark">{label}</span>
              <ChevronRight size={18} className="text-gray" />
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}
