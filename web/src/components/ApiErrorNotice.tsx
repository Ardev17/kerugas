import axios from 'axios'
import { RefreshCw, WifiOff } from 'lucide-react'

/**
 * Quando a API não responde (Outubro 2026): antes, as listas ficavam
 * simplesmente vazias (ex.: "Selecione a província" sem províncias) e
 * parecia que não havia dados. Agora a pessoa vê que é um problema de
 * ligação, com um botão para tentar outra vez,e o tipo de falha ajuda a
 * perceber a causa no app (servidor desligado, endereço errado, túnel
 * ngrok fechado…).
 */
function apiErrorText(error: unknown): string {
  if (axios.isAxiosError(error)) {
    if (error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT') return 'O servidor KeruGás demorou demasiado a responder. Tente outra vez.'
    if (!error.response) return 'Sem ligação ao servidor KeruGás. Verifique a internet e tente outra vez.'
    if (error.response.status >= 500) return `O servidor KeruGás está com um problema (erro ${error.response.status}). Tente daqui a pouco.`
    return `Não foi possível carregar os dados (erro ${error.response.status}).`
  }
  return 'Não foi possível carregar os dados. Tente outra vez.'
}

export function ApiErrorNotice({ error, onRetry, className = '' }: { error: unknown; onRetry: () => void; className?: string }) {
  return (
    <div role="alert" className={`flex flex-col items-start gap-2 rounded-lg bg-danger/10 px-3 py-3 text-sm text-danger sm:flex-row sm:items-center sm:justify-between ${className}`}>
      <span className="flex items-start gap-2">
        <WifiOff size={16} className="mt-0.5 shrink-0" aria-hidden="true" />
        <span>
          {apiErrorText(error)}
        </span>
      </span>
      <button type="button" onClick={onRetry} className="flex shrink-0 items-center gap-1.5 rounded-full border border-danger/40 px-3 py-1 text-xs font-semibold">
        <RefreshCw size={13} aria-hidden="true" /> Tentar outra vez
      </button>
    </div>
  )
}
