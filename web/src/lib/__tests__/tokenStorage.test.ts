import { beforeEach, describe, expect, it, vi } from 'vitest'

const native = vi.hoisted(() => ({ on: false }))
const secure = vi.hoisted(() => ({ data: new Map<string, string>(), get: vi.fn(), set: vi.fn(), remove: vi.fn() }))

vi.mock('@/native/platform', () => ({
  get isNativeApp() { return native.on },
  hasNativePlugin: () => native.on,
}))
// Como o plugin real do Capacitor: um Proxy que responde a QUALQUER nome
// de método (incluindo `then`),e o Android rebenta se `then` for chamado.
vi.mock('@aparajita/capacitor-secure-storage', () => ({
  SecureStorage: new Proxy(secure, {
    get(target, prop) {
      if (prop === 'then') return () => { throw new Error('"SecureStorage.then()" is not implemented on android') }
      return (target as Record<string | symbol, unknown>)[prop]
    },
  }),
}))

describe('tokenStorage', () => {
  beforeEach(() => {
    vi.resetModules()
    localStorage.clear()
    secure.data.clear()
    secure.get.mockReset().mockImplementation(async (k: string) => secure.data.get(k) ?? null)
    secure.set.mockReset().mockImplementation(async (k: string, v: string) => { secure.data.set(k, v) })
    secure.remove.mockReset().mockImplementation(async (k: string) => secure.data.delete(k))
  })

  it('no site usa o localStorage', async () => {
    native.on = false
    const t = await import('../tokenStorage')
    t.setToken('abc')
    expect(localStorage.getItem('kerugas_token')).toBe('abc')
    expect(t.getToken()).toBe('abc')
    t.clearToken()
    expect(t.getToken()).toBeNull()
  })

  it('no app guarda no armazenamento seguro (sem nunca chamar .then no plugin) e migra o token antigo', async () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => {})
    native.on = true
    localStorage.setItem('kerugas_token', 'antigo')
    const t = await import('../tokenStorage')

    await t.initTokenStorage()
    expect(t.getToken()).toBe('antigo')
    expect(secure.data.get('kerugas_token')).toBe('antigo')
    expect(localStorage.getItem('kerugas_token')).toBeNull()

    t.setToken('novo')
    expect(t.getToken()).toBe('novo')
    await vi.waitFor(() => expect(secure.data.get('kerugas_token')).toBe('novo'))
    expect(localStorage.getItem('kerugas_token')).toBeNull()

    t.clearToken()
    expect(t.getToken()).toBeNull()
    await vi.waitFor(() => expect(secure.data.has('kerugas_token')).toBe(false))
    expect(warn).not.toHaveBeenCalled() // nunca caiu na alternativa do WebView
    warn.mockRestore()
  })

  it('se o armazenamento seguro falhar, continua a funcionar', async () => {
    native.on = true
    localStorage.setItem('kerugas_token', 'x')
    secure.get.mockRejectedValue(new Error('keystore'))
    const t = await import('../tokenStorage')
    await t.initTokenStorage()
    expect(t.getToken()).toBe('x')
  })
})
