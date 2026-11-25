import { useEffect, useState } from 'react'
import { NavLink, Outlet, Link, useLocation } from 'react-router-dom'
import { motion } from 'framer-motion'
import { CommunityButton } from '@/components/CommunityButton'
import { LayoutDashboard, Fuel, Users, MessageSquare, Flag, Package, BarChart3, Download, Menu, X, Home, Sun, Moon, Send, Receipt, Truck, MessagesSquare, Mail, BellRing, Gauge, CreditCard, Boxes, Flame, UserCircle2, Bug, HeartHandshake, DatabaseBackup, UsersRound, Headset, TrendingUp, MapPinned, Lock } from 'lucide-react'
import { useAdminRealtime } from '@/hooks/useAdminRealtime'
import { useThemeStore } from '@/stores/themeStore'
import { useAuth } from '@/hooks/useAuth'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { isNativeApp } from '@/native/platform'
import { useBackHandler } from '@/native/backButton'
import { OfflineBanner } from '@/native/components/OfflineBanner'
import { PushToast } from '@/native/components/PushToast'
import { GasFlame } from '@/components/fx/GasFlame'

/**
 * `roles` define quem vê cada secção. O Moderador só vê Comentários e
 * Denúncias,é literalmente a definição do seu papel no sistema
 * (moderar conteúdo da comunidade), sem acesso a gestão operacional
 * (postos, utilizadores, pedidos) nem a dados agregados (analytics).
 */
const links = [
  { to: '/admin', label: 'Dashboard', icon: LayoutDashboard, end: true, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Painel de métricas (Outubro 2026),só Super Admin.
  { to: '/admin/metrics', label: 'Métricas', icon: Gauge, roles: ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/metricas-municipios', label: 'Métricas por município', icon: MapPinned, roles: ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Torneira do período gratuito (Novembro 2026),fechar a consulta dos
  // postos por município, com excepções por pessoa. Só Super Admin.
  { to: '/admin/acesso-consulta', label: 'Acesso à consulta', icon: Lock, roles: ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/analytics', label: 'Analytics', icon: BarChart3, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Mapa de calor da procura (Outubro 2026).
  { to: '/admin/demand', label: 'Procura', icon: Flame, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/stations', label: 'Postos', icon: Fuel, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Central de Operações (SMS manual): estritamente Admin/Super Admin —
  // nem o Moderador nem o Operador de posto têm acesso (secção 40 da
  // especificação da Central de Operações).
  { to: '/admin/operations', label: 'Central de Operações', icon: Send, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/users', label: 'Utilizadores', icon: Users, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/comments', label: 'Comentários', icon: MessageSquare, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'MODERATOR'] },
  // Moderação dos Vizinhos KeruGás (Outubro 2026): o Moderador vê o país todo, o Admin a sua província.
  { to: '/admin/vizinhos', label: 'Vizinhos', icon: HeartHandshake, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'MODERATOR'] },
  { to: '/admin/reports', label: 'Denúncias', icon: Flag, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'MODERATOR'] },
  { to: '/admin/orders', label: 'Pedidos', icon: Package, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Faturação por entrega (Setembro 2026): mesma restrição de papel que
  // Pedidos (só quem confirma pedidos gera faturação),a permissão
  // `billing.view` no backend já não é dada a MODERATOR/STATION_OPERATOR.
  { to: '/admin/billing', label: 'Faturação', icon: Receipt, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Carregamentos nos postos + contadores (Outubro 2026).
  { to: '/admin/pickups', label: 'Carregamentos', icon: Boxes, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Condutores + chat por município (Setembro 2026),visível a
  // ADMIN/SUPER_ADMIN; a criação de contas de Condutor em si continua
  // restrita a SUPER_ADMIN dentro da própria página (ver AdminDrivers).
  { to: '/admin/drivers', label: 'Condutores', icon: Truck, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/chat', label: 'Chat', icon: MessagesSquare, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Chat da Equipa (Outubro 2026): sala única dos admins de todo o país.
  { to: '/admin/chat-equipa', label: 'Chat da Equipa', icon: UsersRound, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Investidores (Outubro 2026): associar a municípios, percentagem e conversa privada,só o Super Admin geral.
  { to: '/admin/investidores', label: 'Investidores', icon: TrendingUp, roles: ['SUPER_ADMIN'] },
  // Conversa com a Central (Outubro 2026): mensagem direta a um utilizador.
  { to: '/admin/conversas-central', label: 'Conversa com a Central', icon: Headset, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Mensagens de contacto do site (Setembro 2026),restrito a Super
  // Admin (decisão explícita do cliente): o Admin comum não vê esta
  // secção, ao contrário da maioria das outras áreas do painel.
  { to: '/admin/contact-messages', label: 'Mensagens de contacto', icon: Mail, roles: ['SUPER_ADMIN'] },
  // Campanhas e envios de notificações (Setembro 2026),só Super Admin.
  { to: '/admin/notifications', label: 'Notificações', icon: BellRing, roles: ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Subscrições mensais (Outubro 2026),ativação manual, só Super Admin.
  { to: '/admin/subscriptions', label: 'Subscrições', icon: CreditCard, roles: ['SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  { to: '/admin/export', label: 'Exportar', icon: Download, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN'] },
  // Meu Perfil (Outubro 2026),todos os que entram no painel.
  { to: '/admin/errors', label: 'Erros da app', icon: Bug, roles: ['SUPER_ADMIN'] },
  // Cópias de segurança (Outubro 2026),só o Super Admin geral.
  { to: '/admin/backups', label: 'Cópias de segurança', icon: DatabaseBackup, roles: ['SUPER_ADMIN'] },
  { to: '/perfil', label: 'Meu perfil', icon: UserCircle2, roles: ['ADMIN', 'SUPER_ADMIN', 'PROVINCE_SUPER_ADMIN', 'MODERATOR'] },
]

/**
 * Layout admin (secção 80): sidebar fixa no desktop, drawer no mobile.
 * Visual de SaaS enterprise,sem gradientes ou glassmorphism excessivo.
 * Suporta dark mode (secção 80/89), escopado apenas a este layout —
 * ver comentário em src/styles/globals.css sobre a decisão de overrides
 * globais em vez de `dark:` repetido em cada página.
 */
export function AdminLayout() {
  const [drawerOpen, setDrawerOpen] = useState(false)
  const { theme, toggle } = useThemeStore()
  const { user } = useAuth()
  const location = useLocation()
  useAdminRealtime()
  // Botão voltar do Android fecha o menu lateral antes de navegar.
  useBackHandler(drawerOpen, () => setDrawerOpen(false))

  // Com o menu aberto, a página por trás não rola (o dedo rola só o menu).
  useEffect(() => {
    if (!drawerOpen) return
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previous
    }
  }, [drawerOpen])

  return (
    <div className="flex min-h-screen bg-light">
      {/* Sidebar desktop */}
      {/* sticky + altura do ecrã: com muitos itens, a lista rola por dentro. */}
      <aside className="fx-sidebar sticky top-0 hidden h-screen w-60 shrink-0 border-r border-gray/10 bg-dark text-light md:block">
        <SidebarContent onNavigate={() => {}} role={user?.role} pillId="nav-pill-desktop" />
      </aside>

      {/* Drawer mobile */}
      {drawerOpen && (
        // Outubro 2026: altura = ecrã visível (dvh, sem contar a barra do
        // navegador do telemóvel) e a lista rola por dentro,antes os últimos
        // itens ficavam fora do ecrã sem forma de lá chegar.
        <div className="fixed inset-0 z-50 flex md:hidden" style={{ height: '100dvh' }} role="dialog" aria-modal="true" aria-label="Menu">
          <div className={`fx-sidebar fx-drawer-in flex h-full w-64 max-w-[85vw] flex-col overflow-hidden bg-dark text-light ${isNativeApp ? 'native-drawer' : ''}`}>
            <SidebarContent onNavigate={() => setDrawerOpen(false)} role={user?.role} pillId="nav-pill-drawer" />
          </div>
          <div className="fx-backdrop-in flex-1 bg-black/40 backdrop-blur-[2px]" onClick={() => setDrawerOpen(false)} />
        </div>
      )}

      <div className="min-w-0 flex-1">
        <header className={`${isNativeApp ? 'native-header sticky top-0 z-40' : ''} border-b border-gray/10 bg-white`}>
          <div className="flex h-14 items-center justify-between px-4 md:px-6">
            <button className="shrink-0 md:hidden" onClick={() => setDrawerOpen(true)} aria-label="Abrir menu">
              <Menu size={22} />
            </button>
            {/* Em ecrãs estreitos (telemóvel, app) o título longo partia-se
                em duas linhas e empurrava os botões,versão curta aí. */}
            <p className="truncate px-2 text-sm font-semibold text-dark">
              <span className="sm:hidden">Painel KeruGás</span>
              <span className="hidden sm:inline">Painel Administrativo KeruGás</span>
            </p>
            <div className="flex shrink-0 items-center gap-3">
              {/* Vizinhos KeruGás: entrar na conversa da comunidade (a moderação fica no menu lateral). */}
              <CommunityButton variant="compact" className="sm:hidden" />
              <CommunityButton className="hidden sm:inline-flex" />
              <button
                onClick={toggle}
                aria-label={theme === 'dark' ? 'Ativar modo claro' : 'Ativar modo escuro'}
                className="flex h-8 w-8 items-center justify-center rounded-lg text-dark hover:bg-light"
              >
                {theme === 'dark' ? <Sun size={16} /> : <Moon size={16} />}
              </button>
              <Link to="/" className="whitespace-nowrap text-xs font-medium text-gray hover:text-primary">{isNativeApp ? 'Sair' : 'Ver site'}</Link>
            </div>
          </div>
          <div className="fx-header-line" aria-hidden="true" />
        </header>
        {isNativeApp && <OfflineBanner />}

        <main className={`p-4 md:p-6 ${isNativeApp ? 'native-admin-main' : ''}`}>
          <ErrorBoundary>
            {/* Cada secção entra com uma transição curta (Outubro 2026). */}
            <div key={location.pathname} className="fx-page">
              <Outlet />
            </div>
          </ErrorBoundary>
        </main>
        {isNativeApp && <PushToast />}
      </div>
    </div>
  )
}

function SidebarContent({ onNavigate, role, pillId }: { onNavigate: () => void; role?: string; pillId: string }) {
  const visibleLinks = links.filter((link) => !role || link.roles.includes(role))

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex h-14 shrink-0 items-center justify-between px-4">
        <span className="flex items-center gap-2 font-bold tracking-tight">
          <img src="/branding/logo-128.webp" alt="" className="h-7 w-7 object-contain" width={28} height={28} />
          KeruGás
          <GasFlame size={14} />
        </span>
        <button className="md:hidden" onClick={onNavigate} aria-label="Fechar menu"><X size={20} /></button>
      </div>

      <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto overscroll-contain px-3 py-2">
        {visibleLinks.map(({ to, label, icon: Icon, end }) => (
          <NavLink
            key={to}
            to={to}
            end={end}
            onClick={onNavigate}
            className={({ isActive }) =>
              `group relative isolate flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors ${
                isActive ? 'text-white' : 'text-light/70 hover:bg-white/5 hover:text-light'
              }`
            }
          >
            {({ isActive }) => (
              <>
                {/* A "pílula" desliza de um item para o outro ao mudar de secção. */}
                {isActive && <motion.span layoutId={pillId} className="fx-nav-pill" transition={{ type: 'spring', stiffness: 420, damping: 34 }} />}
                <Icon size={18} className="transition-transform duration-300 group-hover:scale-110" /> {label}
              </>
            )}
          </NavLink>
        ))}
      </nav>

      <div className="shrink-0 border-t border-white/10 p-4">
        <Link to="/" className="flex items-center gap-2 text-xs text-light/60 hover:text-light">
          <Home size={14} /> {isNativeApp ? 'Voltar à app' : 'Voltar ao site'}
        </Link>
      </div>
    </div>
  )
}
