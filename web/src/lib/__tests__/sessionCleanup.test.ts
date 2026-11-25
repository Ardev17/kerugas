import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { SESSION_CLEARED_EVENT } from '../sessionCleanup'
import { confirmLogoutWithPendingPickups, enqueuePickup, readQueue } from '../pickupQueue'
import type { AuthUser } from '@/types'

const user = (id: number) => ({ id, name: `U${id}`, phone: '', email: null, role: 'DRIVER', status: 'ACTIVE', province_id: 1, municipality_id: 1 }) as AuthUser
const pickup = (uuid: string) => ({ client_uuid: uuid, station_id: 1, quantity: 2, amount: 0, registered_at: '2026-10-05T10:00:00Z', station_name: 'Posto', queued_at: '2026-10-05T10:00:00Z' })

describe('terminar sessão', () => {
  beforeEach(() => {
    localStorage.clear()
    useAuthStore.setState({ user: null, token: null, status: 'guest' })
  })

  it('apaga os dados locais da conta e avisa a app para esvaziar a cache', () => {
    localStorage.setItem('kerugas.activeOrder', '{"reference":"KG-1","phone":"923"}')
    localStorage.setItem('kerugas.recentStations.v1', '[1]')
    localStorage.setItem('kerugas_theme', 'dark')
    // Mapa guardado no aparelho: o que mostra depende da conta (consulta paga).
    localStorage.setItem('kerugas_query_cache_v2', '[{"key":["map-stations"]}]')
    const listener = vi.fn()
    window.addEventListener(SESSION_CLEARED_EVENT, listener)

    useAuthStore.getState().setSession(user(1), 't')
    useAuthStore.getState().clearSession()

    expect(localStorage.getItem('kerugas.activeOrder')).toBeNull()
    expect(localStorage.getItem('kerugas.recentStations.v1')).toBeNull()
    expect(localStorage.getItem('kerugas_query_cache_v2')).toBeNull()
    expect(localStorage.getItem('kerugas_theme')).toBe('dark') // preferências do aparelho ficam
    expect(listener).toHaveBeenCalled()
    window.removeEventListener(SESSION_CLEARED_EVENT, listener)
  })

  it('entrar outra conta sem sair também esvazia a cache', () => {
    const listener = vi.fn()
    window.addEventListener(SESSION_CLEARED_EVENT, listener)
    useAuthStore.getState().setSession(user(1), 't1')
    useAuthStore.getState().setSession(user(1), 't1')
    expect(listener).not.toHaveBeenCalled()
    useAuthStore.getState().setSession(user(2), 't2')
    expect(listener).toHaveBeenCalledTimes(1)
    window.removeEventListener(SESSION_CLEARED_EVENT, listener)
  })

  it('a fila offline de um condutor nunca é vista (nem enviada) por outro', () => {
    useAuthStore.getState().setSession(user(1), 't1')
    enqueuePickup(pickup('a'))
    expect(readQueue()).toHaveLength(1)

    useAuthStore.getState().clearSession()
    useAuthStore.getState().setSession(user(2), 't2')
    expect(readQueue()).toHaveLength(0)

    useAuthStore.getState().setSession(user(1), 't1')
    expect(readQueue()).toHaveLength(1) // continua guardada para o dono
  })

  it('pede confirmação para sair com carregamentos por enviar', () => {
    useAuthStore.getState().setSession(user(1), 't1')
    expect(confirmLogoutWithPendingPickups()).toBe(true)
    enqueuePickup(pickup('b'))
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    expect(confirmLogoutWithPendingPickups()).toBe(false)
    expect(confirm.mock.calls[0][0]).toContain('1 carregamento')
    confirm.mockRestore()
  })
})
