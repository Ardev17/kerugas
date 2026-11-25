import { api } from '@/lib/api'
import type { ApiSuccess, Station } from '@/types'

interface NearbyParams {
  latitude?: number
  longitude?: number
  municipality_id?: number
  gas_available?: boolean
  queue_level?: string
  radius_km?: number
}

/**
 * Um município cuja consulta está fechada (Novembro 2026). Os postos dessa
 * zona não vêm na lista; vem isto no lugar, para podermos explicar porquê em
 * vez de mostrar um vazio que parece avaria.
 */
export interface BlockedMunicipality {
  id: number
  name: string
  reason: string
}

/**
 * Porque é que a lista veio curta por causa de QUEM pede, e não de onde.
 * Vem preenchido quando a própria conta está bloqueada, ou quando é preciso
 * iniciar sessão para sabermos a quem aplicar uma excepção.
 */
export interface AccessBlock {
  reason: 'user_blocked' | 'login_required'
  message: string
}

export interface StationList {
  stations: Station[]
  blocked: BlockedMunicipality[]
  accessBlock: AccessBlock | null
}

interface ListMeta {
  blocked_municipalities?: BlockedMunicipality[]
  access_block?: AccessBlock | null
}

export const stationService = {
  async nearby(params: NearbyParams): Promise<StationList> {
    const { data } = await api.get<ApiSuccess<Station[]> & { meta?: ListMeta }>('/stations/nearby', { params })
    return {
      stations: data.data,
      blocked: data.meta?.blocked_municipalities ?? [],
      accessBlock: data.meta?.access_block ?? null,
    }
  },

  async show(id: number): Promise<Station> {
    const { data } = await api.get<ApiSuccess<Station>>(`/stations/${id}`)
    return data.data
  },

  async forMap(): Promise<StationList> {
    const { data } = await api.get<ApiSuccess<Station[]> & { meta?: ListMeta }>('/map/stations')
    return {
      stations: data.data,
      blocked: data.meta?.blocked_municipalities ?? [],
      accessBlock: data.meta?.access_block ?? null,
    }
  },
}
