import { api } from '@/lib/api'
import { nativePlatform } from '@/native/platform'
import { getAppVersion } from '@/native/appVersion'

/**
 * Relatórios de erros (Outubro 2026): quando o site ou o app falha no
 * telemóvel de alguém, o erro chega ao Super Admin (Painel → Erros da app)
 * em vez de ninguém saber. Sem serviços externos.
 *
 * Cuidados: só erros do NOSSO código (extensões do navegador e scripts de
 * terceiros são ignorados), no máximo 10 relatórios por sessão, o mesmo erro
 * só uma vez, e nunca o conteúdo de formulários. O servidor ainda limpa
 * emails, telefones e tokens antes de guardar.
 */
const MAX_PER_SESSION = 10
const sent = new Set<string>()
let installed = false

const IGNORED = [
  /ResizeObserver loop/i,
  /^Script error\.?$/i, // erro de outro domínio, sem detalhes
  /Network Error/i, // sem rede,esperado, não é um bug
  /Failed to fetch dynamically imported module|Importing a module script failed/i, // nova versão publicada
  /AbortError|The user aborted a request|canceled/i,
]

function fromOurCode(stack: string | undefined, filename?: string): boolean {
  const origin = window.location.origin
  const text = `${filename ?? ''}\n${stack ?? ''}`
  if (/chrome-extension:|moz-extension:|safari-extension:/.test(text)) return false
  // Sem ficheiro nem pilha com o nosso endereço (ex.: "VM341", "<anonymous>"): não é nosso.
  return text.includes(origin) || text.includes('/src/') || text.includes('/assets/')
}

export function reportError(error: unknown, extra?: { filename?: string }): void {
  try {
    const err = error instanceof Error ? error : new Error(typeof error === 'string' ? error : 'Erro desconhecido')
    const message = (err.message || 'Erro sem mensagem').slice(0, 2000)
    if (IGNORED.some((re) => re.test(message))) return
    if (!fromOurCode(err.stack, extra?.filename)) return

    const key = `${err.name}|${message}`
    if (sent.has(key) || sent.size >= MAX_PER_SESSION) return
    sent.add(key)

    void api
      .post('/client-errors', {
        source: nativePlatform ?? 'web',
        name: err.name?.slice(0, 200) || null,
        message,
        stack: err.stack?.slice(0, 8000) ?? null,
        url: window.location.origin + window.location.pathname, // nunca a query string
        app_version: getAppVersion(),
      })
      .catch(() => {})
  } catch {
    // o relatório de erros nunca pode causar outro erro
  }
}

export function installErrorReporting(): void {
  if (installed || import.meta.env.MODE === 'test') return
  installed = true

  window.addEventListener('error', (event) => {
    reportError(event.error ?? event.message, { filename: event.filename })
  })
  window.addEventListener('unhandledrejection', (event) => {
    const reason: unknown = event.reason
    // Pedidos HTTP que falham (4xx/5xx) já são tratados nos ecrãs.
    if (reason && typeof reason === 'object' && 'isAxiosError' in reason) return
    reportError(reason)
  })
}

/** Só para testes. */
export function __resetErrorReporting(): void {
  sent.clear()
}
