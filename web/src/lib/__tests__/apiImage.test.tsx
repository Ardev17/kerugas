import { describe, expect, it, vi, beforeEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'

const get = vi.fn()
vi.mock('@/lib/api', () => ({
  api: { defaults: { baseURL: 'https://schoolboyish.ngrok-free.dev/api/v1' }, get: (...a: unknown[]) => get(...a) },
}))

import { apiImageUrl, useApiImage } from '../apiImage'

describe('apiImageUrl', () => {
  it('rejeita vazio e protocolos estranhos', () => {
    expect(apiImageUrl(null)).toBeNull()
    expect(apiImageUrl('javascript:alert(1)')).toBeNull()
  })

  it('passa blob: sem mexer', () => {
    expect(apiImageUrl('blob:https://x/abc')).toBe('blob:https://x/abc')
  })

  it('põe as fotos da API no endereço https configurado', () => {
    expect(apiImageUrl('https://kerugas.onrender.com/api/v1/community/images/a.jpg')).toBe(
      'https://schoolboyish.ngrok-free.dev/api/v1/community/images/a.jpg',
    )
  })

  it('deixa URLs externas como estão', () => {
    expect(apiImageUrl('https://cdn.exemplo.ao/foto.jpg')).toBe('https://cdn.exemplo.ao/foto.jpg')
  })
})

describe('useApiImage', () => {
  beforeEach(() => {
    get.mockReset()
    URL.createObjectURL = vi.fn(() => 'blob:https://app/foto')
  })

  it('descarrega pelo cliente da API quando a API é um ngrok noutra origem', async () => {
    get.mockResolvedValue({ data: new Blob(['x']) })
    const { result } = renderHook(() => useApiImage('http://x/api/v1/community/images/ok.jpg'))
    expect(result.current.src).toBeNull()
    await waitFor(() => expect(result.current.src).toBe('blob:https://app/foto'))
    expect(get).toHaveBeenCalledWith(
      'https://schoolboyish.ngrok-free.dev/api/v1/community/images/ok.jpg',
      expect.objectContaining({ responseType: 'blob' }),
    )
  })

  it('marca falha quando o descarregamento falha', async () => {
    get.mockRejectedValue(new Error('rede'))
    const { result } = renderHook(() => useApiImage('http://x/api/v1/community/images/falha.jpg'))
    await waitFor(() => expect(result.current.failed).toBe(true))
  })
})
