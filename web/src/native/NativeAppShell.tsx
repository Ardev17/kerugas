import { useEffect, useRef } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { isNativeApp } from '@/native/platform'
import { hideSplashWhenReady } from '@/native/bootstrap'
import { runTopBackHandler } from '@/native/backButton'
import { internalPathFromUrl, safeInternalPath } from '@/native/deepLinks'
import { initPushNotifications } from '@/native/push'
import { checkForAppUpdate } from '@/native/appVersion'
import { useAuth } from '@/hooks/useAuth'

/**
 * Comportamento nativo que precisa do router. Montado uma vez, dentro
 * do <BrowserRouter>. No site web não renderiza nada nem faz nada.
 */
export function NativeAppShell() {
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const { isAuthenticated } = useAuth()

  // O React Router marca a primeira entrada do histórico com a chave
  // 'default'. Qualquer outra significa que a pessoa já navegou dentro do
  // app (mudou de aba, abriu um posto...) e tem para onde voltar.
  const canGoBackInApp = useRef(false)
  canGoBackInApp.current = location.key !== 'default'

  // Splash: sai assim que o primeiro ecrã está pintado.
  useEffect(() => {
    if (isNativeApp) void hideSplashWhenReady()
  }, [])

  // Botão voltar, links externos e regresso ao primeiro plano.
  useEffect(() => {
    if (!isNativeApp) return
    let disposed = false
    const cleanups: Array<() => void> = []

    void (async () => {
      const { App } = await import('@capacitor/app')
      if (disposed) return

      // Voltar: fecha primeiro o que está por cima (menu, janela...); depois
      // regressa ao ecrã/aba anterior,também entre as abas da barra
      // inferior. Só quando já não há nada para trás (primeiro ecrã desta
      // sessão) é que o app é minimizado, como o WhatsApp ou o Gmail.
      const back = await App.addListener('backButton', () => {
        if (runTopBackHandler()) return
        if (!canGoBackInApp.current) {
          void App.minimizeApp()
          return
        }
        navigate(-1)
      })

      const openUrl = await App.addListener('appUrlOpen', ({ url }) => {
        const path = internalPathFromUrl(url)
        if (path) navigate(path)
      })

      // Os dados no ecrã (disponibilidade de gás, filas, pedidos) podem
      // ter mudado muito enquanto o app esteve em segundo plano.
      const resume = await App.addListener('resume', () => {
        void queryClient.refetchQueries({ type: 'active' })
        void checkForAppUpdate()
      })

      cleanups.push(() => void back.remove(), () => void openUrl.remove(), () => void resume.remove())

      // App aberto "a frio" por um link (ex: SMS de avaliação).
      const launch = await App.getLaunchUrl()
      const launchPath = launch?.url ? internalPathFromUrl(launch.url) : null
      if (launchPath && !disposed) navigate(launchPath, { replace: true })
    })().catch((error) => console.warn('[KERUGAS] Integração nativa do app indisponível', error))

    return () => {
      disposed = true
      cleanups.forEach((fn) => fn())
    }
  }, [navigate, queryClient])

  // Notificações push: regista o telemóvel depois do login; tocar numa
  // notificação abre o ecrã certo (pedido, entrega, posto...).
  useEffect(() => {
    if (!isNativeApp || !isAuthenticated) return
    return initPushNotifications((rawPath) => {
      const path = safeInternalPath(rawPath)
      if (path) navigate(path)
    })
  }, [isAuthenticated, navigate])

  // Cada ecrã novo começa no topo,na app e no site (Outubro 2026: no site,
  // ao sair de uma página comprida, o ecrã seguinte abria lá em baixo, no
  // rodapé; nem o WebView nem o navegador fazem isto sozinhos numa SPA).
  useEffect(() => {
    window.scrollTo(0, 0)
  }, [location.pathname])

  return null
}
