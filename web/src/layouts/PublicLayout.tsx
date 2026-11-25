import { lazy, Suspense } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Header } from '@/components/layout/Header'
import { Footer } from '@/components/layout/Footer'
import { ErrorBoundary } from '@/components/ErrorBoundary'
import { isNativeApp } from '@/native/platform'
import { BottomTabBar } from '@/native/components/BottomTabBar'
import { TrackOrderButton } from '@/components/TrackOrderButton'

// Só existem no app Android,no site não descarregam (nem a framer-motion que usam).
const OfflineBanner = lazy(() => import('@/native/components/OfflineBanner').then((m) => ({ default: m.OfflineBanner })))
const PushToast = lazy(() => import('@/native/components/PushToast').then((m) => ({ default: m.PushToast })))

function PageFallback() {
  return <div className="mx-auto max-w-4xl px-4 py-16 text-center text-gray">A carregar...</div>
}

/**
 * No app Android (Setembro 2026) a moldura muda: sai o rodapé de site
 * (o conteúdo dele passou para o ecrã Menu) e entra a barra de
 * navegação inferior. No site web nada muda.
 */
export function PublicLayout() {
  const location = useLocation()

  return (
    <div className="flex min-h-screen flex-col">
      <Header />
      <TrackOrderButton />
      {isNativeApp && (
        <Suspense fallback={null}>
          <OfflineBanner />
        </Suspense>
      )}
      <main className={`flex-1 ${isNativeApp ? 'native-main' : ''}`}>
        <ErrorBoundary>
          {isNativeApp ? (
            // `key` pela rota: cada ecrã entra com uma transição curta, e um
            // erro num ecrã não fica "preso" ao navegar para outro.
            <div key={location.pathname} className="native-page">
              <Suspense fallback={<PageFallback />}>
                <Outlet />
              </Suspense>
            </div>
          ) : (
            // Site (Outubro 2026): cada página entra com um "fade" desfocado.
            <div key={location.pathname} className="fx-page">
              <Suspense fallback={<PageFallback />}>
                <Outlet />
              </Suspense>
            </div>
          )}
        </ErrorBoundary>
      </main>
      {isNativeApp ? (
        <>
          <BottomTabBar />
          <Suspense fallback={null}>
            <PushToast />
          </Suspense>
        </>
      ) : (
        <Footer />
      )}
    </div>
  )
}
