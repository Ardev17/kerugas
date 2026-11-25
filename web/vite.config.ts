import { defineConfig, type Plugin } from 'vitest/config'
import react from '@vitejs/plugin-react'
import path from 'node:path'
import { seoPrerender } from './plugins/seoPrerender'

/**
 * App Android e iPhone (Setembro/Outubro 2026): nos builds `--mode android` e
 * `--mode ios`, o index.html do app ganha `viewport-fit=cover`,é isso que diz ao Capacitor 8
 * (SystemBars) para desenhar a app por baixo da barra de estado e
 * injetar as margens seguras (ver globals.css, secção APP ANDROID). O
 * index.html do site web fica exatamente igual, por isso nada muda para
 * quem visita pelo browser.
 */
function nativeViewport(mode: string): Plugin {
  return {
    name: 'kerugas-native-viewport',
    transformIndexHtml(html) {
      if (mode !== 'android' && mode !== 'ios') return html
      return html
        .replace(
          '<meta name="viewport" content="width=device-width, initial-scale=1.0" />',
          '<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />'
        )
        // O manifest PWA não faz sentido dentro do app nativo.
        .replace('<link rel="manifest" href="/manifest.json" />', '')
    },
  }
}

/**
 * Partilhar o site pelo túnel (Outubro 2026),`npm run share`. O site e a
 * API saem pelo MESMO endereço ngrok: o Vite serve o site e reencaminha
 * `/api` para o Laravel no PC. Só se aplica ao `vite dev`/`vite preview`
 * no PC,o site em produção é servido pelo nginx e nada disto existe lá.
 */
const TUNNEL_HOSTS = ['.ngrok-free.app', '.ngrok-free.dev', '.ngrok.app', '.ngrok.dev', '.trycloudflare.com']
const apiProxy = {
  '/api': {
    target: process.env.KERUGAS_API_PROXY ?? 'http://127.0.0.1:8000',
    // Host original (o do ngrok) e cabeçalhos X-Forwarded-* do túnel seguem
    // intactos: o Laravel gera os URLs (fotos) com o endereço público https.
    changeOrigin: false,
  },
  // Sitemap dos postos (Outubro 2026): em produção o nginx manda-o para a
  // API; aqui o túnel/preview faz o mesmo.
  '/sitemap-postos.xml': {
    target: process.env.KERUGAS_API_PROXY ?? 'http://127.0.0.1:8000',
    changeOrigin: false,
    rewrite: () => '/api/v1/seo/sitemap-postos.xml',
  },
}

export default defineConfig(({ mode }) => ({
  plugins: [react(), nativeViewport(mode), seoPrerender(mode)],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  build: {
    rollupOptions: {
      output: {
        // Velocidade (Outubro 2026): as bibliotecas base ficam em ficheiros
        // próprios. Descarregam em paralelo e, como quase nunca mudam, o
        // browser guarda-as entre atualizações do site,depois de um deploy
        // só desce o código que mudou, não o React inteiro outra vez.
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined
          if (/node_modules\/(react|react-dom|scheduler|react-router|react-router-dom|@remix-run)\//.test(id)) return 'vendor-react'
          if (/node_modules\/(@tanstack|axios|zustand)\//.test(id)) return 'vendor-data'
          if (/node_modules\/framer-motion\//.test(id)) return 'vendor-motion'
          return undefined
        },
      },
    },
  },
  server: {
    host: true,
    // CORS (Outubro 2026): o Vite NÃO responde a CORS,deixa passar tudo
    // para o Laravel (proxy `/api`), que é quem sabe que origens aceita
    // (config/cors.php: inclui https://app.kerugas.ao, a origem do app
    // Android). Antes, o próprio Vite respondia ao pedido de verificação
    // (OPTIONS) do app com "204" mas sem autorizar a origem do app,e o
    // app nunca chegava a pedir os dados ("Sem ligação ao servidor").
    cors: false,
    port: 5173,
    allowedHosts: TUNNEL_HOSTS,
    proxy: apiProxy,
  },
  preview: {
    port: 4173,
    cors: false, // ver comentário em `server.cors`
    allowedHosts: TUNNEL_HOSTS,
    proxy: apiProxy,
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    globals: true,
    css: false,
  },
}))
