import { useCallback, useEffect, useState } from 'react'
import { BrowserRouter } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { AppRouter } from '@/app/router'
import { useThemeStore } from '@/stores/themeStore'
import { NativeAppShell } from '@/native/NativeAppShell'
import { DriverLocationRunner } from '@/components/driver/DriverLocationSharing'
import { useAuth } from '@/hooks/useAuth'
import { resyncWebPush } from '@/lib/webPush'
import { NotificationPopupHost } from '@/components/NotificationPopup'
import { isNativeApp } from '@/native/platform'
import { AppUpdateGate } from '@/native/components/AppUpdateGate'
import { PullToRefresh } from '@/native/components/PullToRefresh'
import { SessionCacheReset } from '@/components/SessionCacheReset'
import { prefetchCommonPages } from '@/app/prefetch'
import { IntroSplash } from '@/components/fx/IntroSplash'
import { installTextAssist } from '@/lib/textAssist'
import { SeoManager } from '@/seo/SeoManager'

/**
 * BUG CORRIGIDO: o `.dark` estava aplicado a uma <div> DENTRO do
 * <body>, mas o <body> tem as suas próprias classes (`bg-light
 * text-dark`, definidas em index.html) que ficam FORA da árvore React
 *,um seletor CSS `.dark .bg-light` só afeta descendentes de `.dark`,
 * nunca o próprio <body>, que é ANCESTRAL da div. Resultado: o fundo
 * da página nunca escurecia por completo, e qualquer classe usada
 * antes de eu a cobrir explicitamente (ex: `bg-white/95` do Header,
 * uma classe Tailwind DIFERENTE de `bg-white`) ficava clara, com texto
 * já convertido para claro por cima,texto invisível.
 *
 * Corrigido aplicando `.dark` diretamente ao <html> (via DOM, não
 * JSX), o padrão correto do Tailwind,cobre body, root, tudo.
 */
export default function App() {
  const { theme, hydrate } = useThemeStore()

  useEffect(() => {
    hydrate()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Corretor e sugestões do teclado nos campos de texto livre (ver lib/textAssist.ts).
  useEffect(() => installTextAssist(), [])

  // Páginas mais usadas descarregam em segundo plano (ver app/prefetch.ts).
  useEffect(() => prefetchCommonPages(), [])

  useEffect(() => {
    document.documentElement.classList.toggle('dark', theme === 'dark')
  }, [theme])

  // Arrastar para baixo (só no app): refaz os dados e volta a montar o ecrã
  // atual, para que também as páginas com pedidos próprios recarreguem.
  const queryClient = useQueryClient()
  const [refreshKey, setRefreshKey] = useState(0)
  const refreshScreen = useCallback(async () => {
    await queryClient.invalidateQueries()
    setRefreshKey((k) => k + 1)
  }, [queryClient])

  return (
    <BrowserRouter>
      <SeoManager />
      <SessionCacheReset />
      <NativeAppShell />
      <WebPushSync />
      <DriverLocationRunner />
      {isNativeApp && <PullToRefresh onRefresh={refreshScreen} />}
      <AppRouter key={refreshKey} />
      <NotificationPopupHost />
      {isNativeApp && <AppUpdateGate />}
      <IntroSplash />
    </BrowserRouter>
  )
}

/**
 * Web Push (Setembro 2026): depois de cada login, volta a ligar este
 * navegador à sessão,só se a pessoa já tinha autorizado notificações
 * antes; nunca pergunta nada sozinho.
 */
function WebPushSync() {
  const { isAuthenticated } = useAuth()

  useEffect(() => {
    if (isAuthenticated) void resyncWebPush().catch(() => {})
  }, [isAuthenticated])

  return null
}
