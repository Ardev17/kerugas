/*
 * Service worker do KeruGás,só para notificações Web Push (Setembro 2026).
 *
 * De propósito NÃO guarda páginas em cache: uma cache de service worker
 * mal gerida serve versões antigas do site depois de um deploy, e o
 * KeruGás mostra informação que muda a cada minuto (gás, filas).
 */
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' }
  }

  event.waitUntil(
    self.registration.showNotification(data.title || 'KeruGás', {
      body: data.body || '',
      icon: '/branding/icon-192.png',
      badge: '/branding/favicon-32.png',
      // Mesma categoria substitui a anterior em vez de empilhar avisos.
      tag: data.tag || 'kerugas',
      data: { path: data.path || '/' },
    })
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  // Só caminhos internos do próprio site,nunca outro domínio.
  const raw = event.notification.data && event.notification.data.path
  const path = typeof raw === 'string' && /^\/(?!\/)[^\\\s\x00-\x1F\x7F]*$/.test(raw) ? raw : '/'
  const url = new URL(path, self.location.origin).href
  // A URL final tem de continuar no nosso domínio (defesa extra).
  const safeUrl = new URL(url).origin === self.location.origin ? url : self.location.origin + '/'

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      for (const client of windows) {
        if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
          // navigate() falha em janelas que o service worker não controla
          // (ex: separador aberto antes da instalação),aí abre-se uma nova.
          return client
            .navigate(safeUrl)
            .then((c) => (c || client).focus())
            .catch(() => self.clients.openWindow(safeUrl))
        }
      }
      return self.clients.openWindow(safeUrl)
    })
  )
})
