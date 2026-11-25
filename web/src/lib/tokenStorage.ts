import { isNativeApp, hasNativePlugin } from '@/native/platform'

/**
 * Onde fica o token de sessão (Outubro 2026).
 *
 * - Site: `localStorage` (como antes).
 * - App Android: armazenamento seguro do sistema (Android Keystore, via
 *   @aparajita/capacitor-secure-storage),cifrado e fora do alcance de
 *   outro código que corra no WebView. O valor fica também em memória para
 *   o cliente HTTP o poder ler de forma síncrona.
 *
 * `initTokenStorage()` tem de correr ANTES de o React montar (ver main.tsx):
 * o store de sessão lê o token logo ao ser criado.
 */
export const TOKEN_KEY = 'kerugas_token'

let memoryToken: string | null = null
// A sessão mudou (login/logout) antes de a leitura inicial acabar? Então a
// leitura (mais antiga) nunca a pode sobrepor.
let changedSinceInit = false

function localGet(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null // armazenamento bloqueado: trata-se como visitante
  }
}

function localSet(value: string | null): void {
  try {
    if (value === null) localStorage.removeItem(TOKEN_KEY)
    else localStorage.setItem(TOKEN_KEY, value)
  } catch {
    // sem armazenamento: a sessão dura só enquanto a página estiver aberta
  }
}

const secureAvailable = () => isNativeApp && hasNativePlugin('SecureStorage')

/**
 * BUG CORRIGIDO (Outubro 2026): devolver o plugin DIRETAMENTE de uma função
 * async faz o JavaScript procurar `.then` nele (para ver se é uma promessa);
 * o plugin nativo do Capacitor responde a qualquer nome e o Android dava
 * "SecureStorage.then() is not implemented". Por isso vai embrulhado num
 * objeto simples,nunca devolver um plugin Capacitor de uma promessa.
 */
async function secure(): Promise<{ store: typeof import('@aparajita/capacitor-secure-storage').SecureStorage }> {
  const { SecureStorage } = await import('@aparajita/capacitor-secure-storage')
  return { store: SecureStorage }
}

/** Carrega o token guardado (e muda o de versões antigas do app para o armazenamento seguro). */
export async function initTokenStorage(): Promise<void> {
  if (!secureAvailable()) {
    memoryToken = localGet()
    return
  }

  try {
    const { store } = await secure()
    const stored = await store.get(TOKEN_KEY)
    // Um token no localStorage é de uma versão antiga do app OU de uma
    // falha do armazenamento seguro,em ambos os casos é o mais recente.
    const legacy = localGet()
    const token = legacy ?? (typeof stored === 'string' && stored !== '' ? stored : null)

    if (legacy) {
      await store.set(TOKEN_KEY, legacy)
      localSet(null)
    }
    if (!changedSinceInit) memoryToken = token
  } catch (error) {
    console.warn('[KERUGAS] Armazenamento seguro indisponível,a usar o do WebView.', error)
    if (!changedSinceInit) memoryToken = localGet()
  }
}

export function getToken(): string | null {
  return secureAvailable() ? memoryToken : localGet()
}

export function setToken(token: string): void {
  changedSinceInit = true
  memoryToken = token
  if (!secureAvailable()) {
    localSet(token)
    return
  }
  void secure()
    .then(({ store }) => store.set(TOKEN_KEY, token))
    .catch(() => localSet(token)) // nunca perder a sessão por falha do plugin
}

export function clearToken(): void {
  changedSinceInit = true
  memoryToken = null
  localSet(null)
  if (secureAvailable()) {
    // Se a remoção falhar, sobrescreve com vazio: a sessão nunca "volta"
    // no arranque seguinte.
    void secure()
      .then(({ store }) => store.remove(TOKEN_KEY).catch(() => store.set(TOKEN_KEY, '')))
      .catch(() => {})
  }
}
