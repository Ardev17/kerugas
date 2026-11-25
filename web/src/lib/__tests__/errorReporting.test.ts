import { beforeEach, describe, expect, it, vi } from 'vitest'

const post = vi.hoisted(() => vi.fn())
vi.mock('@/lib/api', () => ({ api: { post } }))
vi.mock('@/native/appVersion', () => ({ getAppVersion: () => null }))

import { __resetErrorReporting, reportError } from '../errorReporting'

function ourError(message: string) {
  const e = new Error(message)
  e.stack = `Error: ${message}\n    at Profile (${window.location.origin}/assets/Profile-abc.js:1:200)`
  return e
}

describe('relatório de erros', () => {
  beforeEach(() => {
    __resetErrorReporting()
    post.mockReset().mockResolvedValue({})
  })

  it('envia erros do nosso código sem a query string', () => {
    window.history.pushState({}, '', '/pedido/KG-1?telefone=923111000')
    reportError(ourError('boom'))
    expect(post).toHaveBeenCalledWith('/client-errors', expect.objectContaining({ source: 'web', message: 'boom', url: `${window.location.origin}/pedido/KG-1` }))
  })

  it('o mesmo erro só uma vez por sessão, e no máximo 10', () => {
    reportError(ourError('repetido'))
    reportError(ourError('repetido'))
    expect(post).toHaveBeenCalledTimes(1)
    for (let i = 0; i < 20; i++) reportError(ourError(`erro ${i}`))
    expect(post).toHaveBeenCalledTimes(10)
  })

  it('ignora extensões do navegador, scripts anónimos e falhas de rede', () => {
    const ext = new Error("Cannot read properties of undefined (reading 'startTime')")
    ext.stack = 'TypeError: x\n    at et.reportAllChanges (<anonymous>:2:19429)'
    reportError(ext, { filename: 'VM341' })
    const chromeExt = new Error('x')
    chromeExt.stack = 'Error: x\n at chrome-extension://abc/content.js:1:1'
    reportError(chromeExt)
    reportError(ourError('Network Error'))
    expect(post).not.toHaveBeenCalled()
  })
})
