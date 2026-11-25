import { Component, type ErrorInfo, type ReactNode } from 'react'
import { ErrorArt } from '@/components/illustrations/Admin'
import { RefreshCw } from 'lucide-react'
import { reportError } from '@/lib/errorReporting'
import { useAuthStore } from '@/stores/authStore'
import { canEnterAdminPanel } from '@/lib/roles'

interface Props {
  children: ReactNode
}

interface State {
  hasError: boolean
  message: string | null
}

const CHUNK_ERROR = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|Loading chunk .* failed/i
const RELOAD_KEY = 'kerugas:chunk-reload'

/**
 * Error Boundary global (secção 104: "Frontend: ErrorBoundary").
 *
 * BUG CORRIGIDO: sem isto, qualquer erro não apanhado durante o render
 * de uma página (ex: aceder a `.map()` num valor que afinal não era
 * um array) desmontava a aplicação INTEIRA,página em branco, sem
 * cabeçalho, sem nada, como aconteceu em /admin/reports. Agora um erro
 * numa página mostra uma mensagem amigável nessa página, sem derrubar
 * o resto do site.
 *
 * Outubro 2026: depois de publicar uma versão nova, um separador aberto
 * pede ficheiros da versão antiga que já não existem,recarrega sozinho
 * UMA vez (sem ciclo). E a mensagem técnica fica em "Detalhes", para o
 * suporte saber o que aconteceu sem abrir a consola,SÓ para a equipa
 * (quem entra no painel): o público nunca vê mensagens técnicas.
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false, message: null }

  static getDerivedStateFromError(error: unknown): State {
    return { hasError: true, message: error instanceof Error ? error.message : String(error) }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    if (CHUNK_ERROR.test(error?.message ?? '')) {
      try {
        if (!sessionStorage.getItem(RELOAD_KEY)) {
          sessionStorage.setItem(RELOAD_KEY, '1')
          window.location.reload()
          return
        }
      } catch {
        // sem sessionStorage: mostra a mensagem normal
      }
    }
    console.error('[KERUGAS] Erro não apanhado numa página:', error, info)
    reportError(error)
  }

  componentDidMount() {
    // Página carregou bem: permite um novo recarregamento automático no futuro.
    window.setTimeout(() => {
      try {
        sessionStorage.removeItem(RELOAD_KEY)
      } catch {
        // ignorar
      }
    }, 10_000)
  }

  render() {
    if (this.state.hasError) {
      return (
        <div className="mx-auto flex min-h-[60vh] max-w-md flex-col items-center justify-center px-4 text-center">
          <ErrorArt className="mb-3 h-28 w-auto" />
          <p className="font-semibold text-dark">Algo correu mal nesta página.</p>
          <p className="mt-1 text-sm text-gray">Tente recarregar,se o problema persistir, contacte o suporte.</p>
          <button
            onClick={() => window.location.reload()}
            className="mt-4 flex items-center gap-2 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white"
          >
            <RefreshCw size={14} /> Recarregar
          </button>
          {this.state.message && canEnterAdminPanel(useAuthStore.getState().user) && (
            <details className="mt-4 w-full text-left text-xs text-gray">
              <summary className="cursor-pointer text-center">Detalhes</summary>
              <p className="mt-2 break-words rounded-lg bg-black/5 p-2 font-mono dark:bg-white/5">{this.state.message}</p>
            </details>
          )}
        </div>
      )
    }

    return this.props.children
  }
}
