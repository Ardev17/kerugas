import React from 'react'
import ReactDOM from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { bootstrapNativeApp } from './native/bootstrap'
import { initTokenStorage } from './lib/tokenStorage'
import { checkForAppUpdate, initAppVersion } from './native/appVersion'
import { installErrorReporting } from './lib/errorReporting'
import { installMotionLevel } from './lib/motion'
import { persistQueries, restorePersistedQueries } from './lib/queryPersistence'
import './styles/globals.css'

/**
 * Arranque (Outubro 2026): antes do primeiro render
 *  1. app Android: margens seguras, barra de estado, teclado e rede;
 *  2. o token de sessão é lido (no app vem do armazenamento seguro, que é
 *     assíncrono). O código do <App> descarrega em paralelo; como o store
 *     da sessão pode ter sido criado antes de o token chegar, é
 *     sincronizado (hydrate) antes do primeiro render;
 *  3. versão do app nos pedidos à API (atualização obrigatória);
 *  4. relatório de erros.
 * No site web os passos nativos não fazem nada.
 */
async function start(): Promise<void> {
  bootstrapNativeApp()
  installMotionLevel()
  installErrorReporting()

  // Velocidade (Outubro 2026): o código da app começa a descarregar JÁ, em
  // paralelo com a leitura do token,antes só começava depois, e o
  // telemóvel esperava as duas coisas uma atrás da outra.
  const appModule = import('./App')

  // Nunca deixar o app preso no splash por causa do armazenamento.
  const ready = Promise.all([initTokenStorage(), initAppVersion()])
  let late = false
  await Promise.race([ready, new Promise((resolve) => window.setTimeout(() => { late = true; resolve(null) }, 3000))])

  // Telemóvel lento: se o token chegou depois do primeiro ecrã, a sessão é
  // retomada logo que ele chega (em vez de aparecer como visitante).
  if (late) {
    void ready.then(async () => {
      const { useAuthStore } = await import('./stores/authStore')
      useAuthStore.getState().hydrate()
    })
  }

  const { default: App } = await appModule
  const { useAuthStore } = await import('./stores/authStore')
  useAuthStore.getState().hydrate()

  const queryClient = new QueryClient({
    defaultOptions: { queries: { staleTime: 30_000, retry: 1 } },
  })
  // Dados públicos que mudam pouco aparecem logo a partir da última visita
  // e são atualizados em segundo plano (ver lib/queryPersistence).
  restorePersistedQueries(queryClient)
  persistQueries(queryClient)

  ReactDOM.createRoot(document.getElementById('root')!).render(
    <React.StrictMode>
      <QueryClientProvider client={queryClient}>
        <App />
      </QueryClientProvider>
    </React.StrictMode>
  )

  void checkForAppUpdate()
}

void start()
