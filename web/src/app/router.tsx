import { lazy, Suspense } from 'react'
import { Route, Routes } from 'react-router-dom'
import { PublicLayout } from '@/layouts/PublicLayout'
import { LegacyRedirect, LegacyStationRedirect } from '@/app/LegacyRedirect'
import { LEGACY_REDIRECTS } from '@/app/paths'
import { AuthGuard } from '@/components/AuthGuard'
import { AdminGuard } from '@/components/AdminGuard'
import { OperatorGuard } from '@/components/OperatorGuard'
import { StationManagerGuard } from '@/components/StationManagerGuard'
import { DriverGuard } from '@/components/DriverGuard'
import { InvestorGuard } from '@/components/InvestorGuard'
import Home from '@/pages/Home'
import FindGas from '@/pages/FindGas'
import NotFound from '@/pages/NotFound'
const StationDetails = lazy(() => import('@/pages/StationDetails'))
const OrderSuccess = lazy(() => import('@/pages/OrderSuccess'))
const OrderTracking = lazy(() => import('@/pages/OrderTracking'))
const MyOrders = lazy(() => import('@/pages/MyOrders'))
const AppMenu = lazy(() => import('@/pages/AppMenu'))
const NotificationSettings = lazy(() => import('@/pages/NotificationSettings'))
const Profile = lazy(() => import('@/pages/Profile'))
const RateDelivery = lazy(() => import('@/pages/RateDelivery'))
const Login = lazy(() => import('@/pages/Login'))
const ForgotPassword = lazy(() => import('@/pages/ForgotPassword'))
const ResetPassword = lazy(() => import('@/pages/ResetPassword'))
const Dashboard = lazy(() => import('@/pages/Dashboard'))
const Favorites = lazy(() => import('@/pages/Favorites'))
const Alerts = lazy(() => import('@/pages/Alerts'))
const HowItWorks = lazy(() => import('@/pages/HowItWorks'))
const About = lazy(() => import('@/pages/About'))
const Contact = lazy(() => import('@/pages/Contact'))
const Terms = lazy(() => import('@/pages/Terms'))
const Privacy = lazy(() => import('@/pages/Privacy'))
const DeleteAccountInfo = lazy(() => import('@/pages/DeleteAccountInfo'))
const Cookies = lazy(() => import('@/pages/Cookies'))
const OperatorDashboard = lazy(() => import('@/pages/operator/OperatorDashboard'))
const StationManagerDashboard = lazy(() => import('@/pages/station/StationManagerDashboard'))
const DriverDashboard = lazy(() => import('@/pages/driver/DriverDashboard'))
const DriverRatings = lazy(() => import('@/pages/driver/DriverRatings'))
const Vizinhos = lazy(() => import('@/pages/Vizinhos'))
const AdminVizinhos = lazy(() => import('@/pages/admin/AdminVizinhos'))
const AdminChatEquipa = lazy(() => import('@/pages/admin/AdminChatEquipa'))

/**
 * Code-splitting (secção 78/Etapa 20): Leaflet (mapa) e Recharts (admin)
 * são as duas bibliotecas mais pesadas do bundle,carregadas sob
 * demanda em vez de no bundle inicial, para que um visitante que nunca
 * abre o mapa ou o painel admin não pague esse custo de download.
 */
const MapPage = lazy(() => import('@/pages/MapPage'))
// OrderGas e Register agora usam <LocationPicker> (mapa Leaflet para
// ajustar a localização detetada),passam a lazy pelo mesmo motivo
// do MapPage: não trazer essa dependência pesada para quem nunca a usa.
const OrderGas = lazy(() => import('@/pages/OrderGas'))
const Register = lazy(() => import('@/pages/Register'))
const OperatorAnalytics = lazy(() => import('@/pages/operator/OperatorAnalytics'))
const AdminLayout = lazy(() => import('@/layouts/AdminLayout').then((m) => ({ default: m.AdminLayout })))
const AdminDashboard = lazy(() => import('@/pages/admin/AdminDashboard'))
const AdminAnalytics = lazy(() => import('@/pages/admin/AdminAnalytics'))
const AdminDemand = lazy(() => import('@/pages/admin/AdminDemand'))
const AdminMetrics = lazy(() => import('@/pages/admin/AdminMetrics'))
const AdminMunicipalityMetrics = lazy(() => import('@/pages/admin/AdminMunicipalityMetrics'))
const AdminStationDataAccess = lazy(() => import('@/pages/admin/AdminStationDataAccess'))
const AdminSubscriptions = lazy(() => import('@/pages/admin/AdminSubscriptions'))
const AdminStations = lazy(() => import('@/pages/admin/AdminStations'))
const AdminUsers = lazy(() => import('@/pages/admin/AdminUsers'))
const AdminComments = lazy(() => import('@/pages/admin/AdminComments'))
const AdminReports = lazy(() => import('@/pages/admin/AdminReports'))
const AdminContactMessages = lazy(() => import('@/pages/admin/AdminContactMessages'))
const AdminNotifications = lazy(() => import('@/pages/admin/AdminNotifications'))
const AdminOrders = lazy(() => import('@/pages/admin/AdminOrders'))
const AdminBilling = lazy(() => import('@/pages/admin/AdminBilling'))
const AdminPickups = lazy(() => import('@/pages/admin/AdminPickups'))
const AdminDrivers = lazy(() => import('@/pages/admin/AdminDrivers'))
const AdminChat = lazy(() => import('@/pages/admin/AdminChat'))
const AdminCentralChats = lazy(() => import('@/pages/admin/AdminCentralChats'))
const CentralChat = lazy(() => import('@/pages/CentralChat'))
const InvestorDashboard = lazy(() => import('@/pages/InvestorDashboard'))
const AdminInvestors = lazy(() => import('@/pages/admin/AdminInvestors'))
const AdminExport = lazy(() => import('@/pages/admin/AdminExport'))
const AdminClientErrors = lazy(() => import('@/pages/admin/AdminClientErrors'))
const AdminBackups = lazy(() => import('@/pages/admin/AdminBackups'))
const AdminOperationsCenter = lazy(() => import('@/pages/admin/AdminOperationsCenter'))

/**
 * Velocidade (Outubro 2026): só a página inicial, "Encontrar gás" e o 404
 * vêm no arranque,o resto descarrega quando é aberto (o <Suspense> do
 * PublicLayout mostra "A carregar..." nesse instante). As mais usadas
 * descarregam pouco depois em segundo plano (ver app/prefetch.ts).
 */
function PageFallback() {
  return <div className="mx-auto max-w-4xl px-4 py-16 text-center text-gray">A carregar...</div>
}

export function AppRouter() {
  return (
    <Routes>
      <Route element={<PublicLayout />}>
        <Route path="/" element={<Home />} />
        <Route path="/encontrar-gas" element={<FindGas />} />
        <Route
          path="/mapa"
          element={
            <Suspense fallback={<PageFallback />}>
              <MapPage />
            </Suspense>
          }
        />
        <Route path="/postos/:id" element={<StationDetails />} />
        {/* Endereços antigos (Outubro 2026),continuam a funcionar. */}
        <Route path="/station/:id" element={<LegacyStationRedirect />} />
        {LEGACY_REDIRECTS.map(([from, to]) => (
          <Route key={from} path={from} element={<LegacyRedirect to={to} />} />
        ))}
        <Route
          path="/pedir-gas"
          element={
            <Suspense fallback={<PageFallback />}>
              <OrderGas />
            </Suspense>
          }
        />
        <Route path="/order/success/:reference" element={<OrderSuccess />} />
        {/* Acompanhamento de pedidos (Setembro 2026),destino das
            notificações de pedido do cliente no app. Só o dono vê. */}
        <Route
          path="/pedidos"
          element={
            <AuthGuard>
              <MyOrders />
            </AuthGuard>
          }
        />
        {/* Sem AuthGuard: quem pediu sem conta acompanha com o telefone guardado. */}
        <Route path="/pedido/:reference" element={<OrderTracking />} />
        {/* Ecrã "Menu" do app Android,também acessível no site. */}
        <Route path="/menu" element={<AppMenu />} />
        {/* Meu Perfil (Outubro 2026),todos os papéis. */}
        <Route
          path="/perfil"
          element={
            <AuthGuard>
              <Suspense fallback={<PageFallback />}>
                <Profile />
              </Suspense>
            </AuthGuard>
          }
        />
        {/* Investidor (Outubro 2026): faturação dos seus municípios + conversa com o Super Admin. */}
        <Route
          path="/investidor"
          element={
            <InvestorGuard>
              <Suspense fallback={<PageFallback />}>
                <InvestorDashboard />
              </Suspense>
            </InvestorGuard>
          }
        />
        {/* Conversa com a Central (Outubro 2026),só aparece depois de a direção escrever. */}
        <Route
          path="/conversa-central"
          element={
            <AuthGuard>
              <Suspense fallback={<PageFallback />}>
                <CentralChat />
              </Suspense>
            </AuthGuard>
          }
        />
        {/* Vizinhos KeruGás (Outubro 2026),a comunidade do município; qualquer conta com sessão. */}
        <Route
          path="/comunidade"
          element={
            <AuthGuard>
              <Suspense fallback={<PageFallback />}>
                <Vizinhos />
              </Suspense>
            </AuthGuard>
          }
        />
        {/* Preferências de notificações (Setembro 2026). */}
        <Route
          path="/notificacoes"
          element={
            <AuthGuard>
              <NotificationSettings />
            </AuthGuard>
          }
        />
        {/* Avaliação do condutor (Setembro 2026),pública, sem AuthGuard
            (ver comentário em RateDelivery.tsx/DriverRatingController). */}
        <Route path="/avaliar/:reference" element={<RateDelivery />} />
        <Route path="/login" element={<Login />} />
        <Route
          path="/register"
          element={
            <Suspense fallback={<PageFallback />}>
              <Register />
            </Suspense>
          }
        />
        <Route path="/forgot-password" element={<ForgotPassword />} />
        <Route path="/reset-password" element={<ResetPassword />} />
        <Route
          path="/dashboard"
          element={
            <AuthGuard>
              <Dashboard />
            </AuthGuard>
          }
        />
        <Route
          path="/favorites"
          element={
            <AuthGuard>
              <Favorites />
            </AuthGuard>
          }
        />
        <Route
          path="/alerts"
          element={
            <AuthGuard>
              <Alerts />
            </AuthGuard>
          }
        />
        <Route path="/como-funciona" element={<HowItWorks />} />
        <Route path="/sobre" element={<About />} />
        <Route path="/contacto" element={<Contact />} />
        <Route path="/termos" element={<Terms />} />
        <Route path="/privacidade" element={<Privacy />} />
        <Route path="/eliminar-conta" element={<DeleteAccountInfo />} />
        <Route path="/cookies" element={<Cookies />} />
        <Route
          path="/operator"
          element={
            <OperatorGuard>
              <OperatorDashboard />
            </OperatorGuard>
          }
        />
        <Route
          path="/posto"
          element={
            <StationManagerGuard>
              <StationManagerDashboard />
            </StationManagerGuard>
          }
        />
        <Route
          path="/operator/analytics"
          element={
            <OperatorGuard>
              <Suspense fallback={<PageFallback />}>
                <OperatorAnalytics />
              </Suspense>
            </OperatorGuard>
          }
        />
        <Route
          path="/driver"
          element={
            <DriverGuard>
              <Suspense fallback={<PageFallback />}>
                <DriverDashboard />
              </Suspense>
            </DriverGuard>
          }
        />
        <Route
          path="/driver/avaliacoes"
          element={
            <DriverGuard>
              <Suspense fallback={<PageFallback />}>
                <DriverRatings />
              </Suspense>
            </DriverGuard>
          }
        />
        <Route path="*" element={<NotFound />} />
      </Route>

      <Route
        path="/admin"
        element={
          <AdminGuard>
            <Suspense fallback={<PageFallback />}>
              <AdminLayout />
            </Suspense>
          </AdminGuard>
        }
      >
        <Route
          index
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminDashboard />
            </Suspense>
          }
        />
        <Route
          path="metrics"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminMetrics />
            </Suspense>
          }
        />
        <Route
          path="metricas-municipios"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminMunicipalityMetrics />
            </Suspense>
          }
        />
        <Route
          path="acesso-consulta"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminStationDataAccess />
            </Suspense>
          }
        />
        <Route
          path="subscriptions"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminSubscriptions />
            </Suspense>
          }
        />
        <Route
          path="analytics"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminAnalytics />
            </Suspense>
          }
        />
        <Route
          path="demand"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminDemand />
            </Suspense>
          }
        />
        <Route
          path="stations"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminStations />
            </Suspense>
          }
        />
        <Route
          path="users"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminUsers />
            </Suspense>
          }
        />
        {/* Chat da Equipa (Outubro 2026): só Super Admin, Provincial e Admin (o servidor também recusa os outros). */}
        <Route
          path="chat-equipa"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminChatEquipa />
            </Suspense>
          }
        />
        <Route
          path="vizinhos"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminVizinhos />
            </Suspense>
          }
        />
        <Route
          path="comments"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminComments />
            </Suspense>
          }
        />
        <Route
          path="reports"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminReports />
            </Suspense>
          }
        />
        <Route
          path="orders"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminOrders />
            </Suspense>
          }
        />
        <Route
          path="notifications"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminNotifications />
            </Suspense>
          }
        />
        <Route
          path="contact-messages"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminContactMessages />
            </Suspense>
          }
        />
        <Route
          path="billing"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminBilling />
            </Suspense>
          }
        />
        <Route
          path="pickups"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminPickups />
            </Suspense>
          }
        />
        <Route
          path="drivers"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminDrivers />
            </Suspense>
          }
        />
        <Route
          path="chat"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminChat />
            </Suspense>
          }
        />
        {/* Investidores (Outubro 2026): só o Super Admin geral (o servidor também recusa os outros). */}
        <Route
          path="investidores"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminInvestors />
            </Suspense>
          }
        />
        {/* Conversa com a Central (Outubro 2026): canal direto direção ↔ utilizador. */}
        <Route
          path="conversas-central"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminCentralChats />
            </Suspense>
          }
        />
        <Route
          path="export"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminExport />
            </Suspense>
          }
        />
        <Route
          path="errors"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminClientErrors />
            </Suspense>
          }
        />
        <Route
          path="backups"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminBackups />
            </Suspense>
          }
        />
        <Route
          path="operations"
          element={
            <Suspense fallback={<PageFallback />}>
              <AdminOperationsCenter />
            </Suspense>
          }
        />
      </Route>
    </Routes>
  )
}
