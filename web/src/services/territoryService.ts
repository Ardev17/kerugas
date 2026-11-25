import { api } from '@/lib/api'
import type { ApiSuccess, Municipality, Province } from '@/types'

export const territoryService = {
  async listProvinces(): Promise<Province[]> {
    const { data } = await api.get<ApiSuccess<Province[]>>('/provinces')
    return data.data
  },

  async listMunicipalities(provinceId: number): Promise<Municipality[]> {
    const { data } = await api.get<ApiSuccess<Municipality[]>>(`/provinces/${provinceId}/municipalities`)
    return data.data
  },

  /** Província/município prováveis para umas coordenadas (nulls se não souber). */
  async locate(latitude: number, longitude: number): Promise<TerritoryGuess> {
    const { data } = await api.get<ApiSuccess<TerritoryGuess>>('/territory/locate', { params: { latitude, longitude } })
    return data.data
  },
}

export interface TerritoryGuess {
  province_id: number | null
  municipality_id: number | null
  source: 'geocoder' | 'station' | null
}
