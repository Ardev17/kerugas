import { useEffect, useState } from 'react'
import { api } from '@/lib/api'

/**
 * Fotos servidas pela API (perfil e Vizinhos),Outubro 2026.
 *
 * No app Android a página vem de https://app.kerugas.ao e a API de outro
 * endereço. Duas coisas estragavam as fotos aí (no site não, porque é o
 * mesmo endereço):
 *  1. o URL vem do servidor e podia trazer o endereço/protocolo visto pelo
 *     Laravel (ex.: http:// por trás do túnel),o app bloqueia http://;
 *  2. pelo túnel ngrok grátis, uma <img> recebe a página de aviso do ngrok
 *     em vez da foto (só pedidos com o cabeçalho `ngrok-skip-browser-warning`
 *     passam, e uma <img> não envia cabeçalhos).
 * Aqui: (1) as fotos da API usam sempre o endereço configurado da API; (2)
 * quando a API é um ngrok noutro endereço, a foto é descarregada pelo
 * cliente da API (com o cabeçalho) e mostrada a partir da memória.
 */
function apiBase(): URL | null {
  try {
    return new URL(String(api.defaults.baseURL ?? ''), window.location.href)
  } catch {
    return null
  }
}

/** Mesmo caminho, mas no endereço da API (só para caminhos /api/...). */
export function apiImageUrl(url: string | null | undefined): string | null {
  if (!url) return null
  try {
    const parsed = new URL(url, window.location.href)
    if (parsed.protocol === 'blob:') return url // já em memória
    if (parsed.protocol !== 'https:' && parsed.protocol !== 'http:') return null
    const base = apiBase()
    if (base && parsed.pathname.startsWith('/api/')) {
      return `${base.origin}${parsed.pathname}${parsed.search}`
    }
    return parsed.href
  } catch {
    return null
  }
}

function needsFetch(url: string): boolean {
  try {
    const target = new URL(url)
    return target.origin !== window.location.origin && /\.ngrok(-free)?\.(app|dev|io)$/.test(target.hostname)
  } catch {
    return false
  }
}

const cache = new Map<string, Promise<string>>()

function loadBlob(url: string): Promise<string> {
  let pending = cache.get(url)
  if (!pending) {
    pending = api.get<Blob>(url, { responseType: 'blob', timeout: 60_000 }).then((r) => URL.createObjectURL(r.data))
    pending.catch(() => cache.delete(url)) // falhou: tenta de novo na próxima vez
    cache.set(url, pending)
  }
  return pending
}

/**
 * Endereço pronto a pôr numa <img>. `null` enquanto carrega (ou sem foto);
 * `failed` quando não foi possível obter a foto.
 */
export function useApiImage(url: string | null | undefined): { src: string | null; failed: boolean } {
  const target = apiImageUrl(url)
  const direct = target !== null && !needsFetch(target)
  const [state, setState] = useState<{ for: string | null; src: string | null; failed: boolean }>({ for: null, src: null, failed: false })

  useEffect(() => {
    if (!target || direct) return
    let alive = true
    loadBlob(target).then(
      (src) => alive && setState({ for: target, src, failed: false }),
      () => alive && setState({ for: target, src: null, failed: true }),
    )
    return () => {
      alive = false
    }
  }, [target, direct])

  if (!target) return { src: null, failed: false }
  if (direct) return { src: target, failed: false }
  return state.for === target ? { src: state.src, failed: state.failed } : { src: null, failed: false }
}
