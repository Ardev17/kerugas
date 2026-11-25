import { describe, expect, it } from 'vitest'
import { act, renderHook } from '@testing-library/react'
import { useLiveRefetchInterval, useRealtimeStore } from '../realtimeStore'

describe('atualização automática sem tempo real', () => {
  it('atualiza mais vezes quando o tempo real não está ligado', () => {
    useRealtimeStore.setState({ connected: false })
    const { result } = renderHook(() => useLiveRefetchInterval(20_000))
    expect(result.current).toBe(20_000)
    act(() => useRealtimeStore.setState({ connected: true }))
    expect(result.current).toBe(100_000)
  })
})
