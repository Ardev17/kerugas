import type { QueryClient, QueryKey } from '@tanstack/react-query'

/**
 * Velocidade (Outubro 2026): dados PÚBLICOS que mudam pouco (províncias,
 * municípios, bairros, postos do mapa, ficha de um posto) ficam guardados
 * neste aparelho. Na visita seguinte o ecrã aparece logo com a última
 * versão e o React Query vai buscar a atual em segundo plano,o
 * `updatedAt` original é reposto, por isso estes dados contam como
 * "antigos" e são sempre atualizados ao abrir.
 *
 * Nada da conta da pessoa (pedidos, notificações, perfil, admin) entra
 * aqui: só as chaves da lista abaixo.
 */
export const QUERY_CACHE_KEY = 'kerugas_query_cache_v2'

/**
 * Versões antigas da cache. A v1 guardava 'map-stations' como lista simples;
 * desde a consulta paga (Novembro 2026) é um objecto com a lista e as zonas
 * fechadas. Repor a v1 dava ecrãs a rebentar,muda-se de chave e a antiga
 * é apagada na primeira abertura.
 */
const OLD_CACHE_KEYS = ['kerugas_query_cache_v1']

const HOUR = 60 * 60 * 1000
const RULES: Record<string, number> = {
  provinces: 7 * 24 * HOUR,
  municipalities: 7 * 24 * HOUR,
  'territory-provinces': 7 * 24 * HOUR,
  'territory-municipalities': 7 * 24 * HOUR,
  neighborhoods: 7 * 24 * HOUR,
  'map-stations': 24 * HOUR,
  station: 24 * HOUR,
}
const MAX_ENTRIES = 60
const MAX_BYTES = 1_500_000

interface Entry {
  key: QueryKey
  data: unknown
  updatedAt: number
}

function maxAge(key: QueryKey): number | null {
  const head = Array.isArray(key) ? key[0] : null
  return typeof head === 'string' && head in RULES ? RULES[head] : null
}

function read(): Entry[] {
  try {
    const raw = localStorage.getItem(QUERY_CACHE_KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? (parsed as Entry[]) : []
  } catch {
    return []
  }
}

/** Repõe na cache o que ficou guardado da última visita (só o que ainda é válido). */
export function restorePersistedQueries(client: QueryClient, now = Date.now()): void {
  for (const old of OLD_CACHE_KEYS) {
    try {
      localStorage.removeItem(old)
    } catch {
      /* armazenamento bloqueado */
    }
  }
  for (const entry of read()) {
    if (!entry || !Array.isArray(entry.key) || typeof entry.updatedAt !== 'number') continue
    const age = maxAge(entry.key)
    if (age === null || now - entry.updatedAt > age || entry.data === undefined) continue
    client.setQueryData(entry.key, entry.data, { updatedAt: entry.updatedAt })
  }
}

/** Guarda (com atraso, para não escrever a cada pedido) as consultas da lista. */
export function persistQueries(client: QueryClient): () => void {
  let timer: number | undefined

  const save = () => {
    timer = undefined
    const entries: Entry[] = client
      .getQueryCache()
      .getAll()
      .filter((q) => q.state.status === 'success' && q.state.data !== undefined && maxAge(q.queryKey) !== null)
      .sort((a, b) => b.state.dataUpdatedAt - a.state.dataUpdatedAt)
      .slice(0, MAX_ENTRIES)
      .map((q) => ({ key: q.queryKey, data: q.state.data, updatedAt: q.state.dataUpdatedAt }))

    try {
      const json = JSON.stringify(entries)
      if (json.length > MAX_BYTES) return
      localStorage.setItem(QUERY_CACHE_KEY, json)
    } catch {
      /* armazenamento cheio ou bloqueado,sem cache local, tudo continua a funcionar */
    }
  }

  const unsubscribe = client.getQueryCache().subscribe((event) => {
    if (event.type !== 'updated' || event.action.type !== 'success') return
    if (maxAge(event.query.queryKey) === null) return
    if (timer === undefined) timer = window.setTimeout(save, 1000)
  })

  return () => {
    unsubscribe()
    if (timer !== undefined) window.clearTimeout(timer)
  }
}
