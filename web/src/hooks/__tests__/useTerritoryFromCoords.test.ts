import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor, act } from '@testing-library/react'
import { useTerritoryFromCoords } from '../useTerritoryFromCoords'
import { territoryService } from '@/services/territoryService'

vi.mock('@/services/territoryService', () => ({ territoryService: { locate: vi.fn() } }))

const locate = vi.mocked(territoryService.locate)
const coordsA = { lat: -14.9177, lng: 13.4925 }

describe('useTerritoryFromCoords', () => {
  beforeEach(() => {
    locate.mockReset()
  })

  it('entrega a província e o município deduzidos', async () => {
    locate.mockResolvedValue({ province_id: 3, municipality_id: 9, source: 'geocoder' })
    const onResult = vi.fn()

    const { result } = renderHook(() => useTerritoryFromCoords(coordsA, true, onResult))

    await waitFor(() => expect(result.current.status).toBe('done'))
    expect(onResult).toHaveBeenCalledWith({ province_id: 3, municipality_id: 9, source: 'geocoder' })
  })

  it('não faz nada sem coordenadas ou antes de as províncias carregarem', async () => {
    const onResult = vi.fn()
    renderHook(() => useTerritoryFromCoords(null, true, onResult))
    renderHook(() => useTerritoryFromCoords(coordsA, false, onResult))
    await new Promise((r) => setTimeout(r, 700))
    expect(locate).not.toHaveBeenCalled()
  })

  it('"unknown" quando o servidor não sabe (utilizador escolhe à mão)', async () => {
    locate.mockResolvedValue({ province_id: null, municipality_id: null, source: null })
    const onResult = vi.fn()

    const { result } = renderHook(() => useTerritoryFromCoords(coordsA, true, onResult))

    await waitFor(() => expect(result.current.status).toBe('unknown'))
    expect(onResult).not.toHaveBeenCalled()
  })

  it('"unknown" quando o pedido falha', async () => {
    locate.mockRejectedValue(new Error('rede'))
    const { result } = renderHook(() => useTerritoryFromCoords(coordsA, true, vi.fn()))
    await waitFor(() => expect(result.current.status).toBe('unknown'))
  })

  it('uma escolha manual nunca é sobrescrita ao arrastar o pino; reset devolve o controlo', async () => {
    locate.mockResolvedValue({ province_id: 3, municipality_id: 9, source: 'geocoder' })
    const onResult = vi.fn()

    const { result, rerender } = renderHook(({ c }) => useTerritoryFromCoords(c, true, onResult), { initialProps: { c: coordsA } })
    await waitFor(() => expect(onResult).toHaveBeenCalledTimes(1))

    act(() => result.current.markTouched())
    rerender({ c: { lat: -8.9, lng: 13.2 } })
    await new Promise((r) => setTimeout(r, 700))
    expect(onResult).toHaveBeenCalledTimes(1)

    act(() => result.current.reset())
    rerender({ c: { lat: -8.95, lng: 13.25 } })
    await waitFor(() => expect(onResult).toHaveBeenCalledTimes(2))
  })
})
