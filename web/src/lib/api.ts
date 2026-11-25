import axios from 'axios'
import { getToken } from '@/lib/tokenStorage'

/**
 * Cliente HTTP central. Toda a comunicação com o backend Laravel
 * passa por aqui,nunca fazer fetch/axios direto dentro de páginas.
 */
export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL ?? 'https://kerugas.onrender.com/api/v1',
  // Outubro 2026: sem limite, um servidor que não responde (túnel ngrok
  // fechado, PC desligado, endereço errado no .env.android) deixava o ecrã
  // a "carregar" para sempre, sem erro nenhum. Fotos têm limite próprio.
  timeout: 25_000,
  headers: {
    Accept: 'application/json',
    // Reforço do lado do cliente ao Cache-Control: no-store que o
    // backend já envia em toda a API (ver PreventApiCaching),ajuda
    // com proxies intermédios que respeitem diretivas do pedido.
    'Cache-Control': 'no-cache',
    // Testes do app por túnel ngrok (Outubro 2026): no plano grátis o ngrok
    // responde com uma página de aviso em HTML em vez da API, a não ser que
    // o pedido traga este cabeçalho. Só é enviado quando a API é um ngrok.
    ...(/\.ngrok(-free)?\.(app|dev|io)\b/.test(String(import.meta.env.VITE_API_URL ?? '')) ? { 'ngrok-skip-browser-warning': '1' } : {}),
  },
})

api.interceptors.request.use((config) => {
  const token = getToken()
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

/**
 * Extrai a mensagem de erro real de uma resposta 422/4xx do Laravel
 * (`{message, errors: {campo: [mensagem]}}`), em vez de mostrar sempre
 * um genérico "algo correu mal". Usado onde a mensagem específica do
 * backend traz informação que vale a pena mostrar (ex: "este
 * utilizador já é operador de outro posto").
 */
export function getApiErrorMessage(error: unknown, fallback: string): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    const firstFieldError = data?.errors ? Object.values(data.errors)[0]?.[0] : undefined
    return firstFieldError ?? data?.message ?? fallback
  }
  return fallback
}

/** Servidor para onde a app está a falar (só o nome),ajuda a perceber falhas de ligação no app. */
export function apiHost(): string {
  try {
    return new URL(String(api.defaults.baseURL ?? ''), window.location.href).host
  } catch {
    return String(api.defaults.baseURL ?? '')
  }
}
