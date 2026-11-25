import { api } from '@/lib/api'
import type { PaginatedMeta } from '@/types'

export interface Paginated<T> {
  data: T[]
  meta?: PaginatedMeta
}

/**
 * Organização das listas do painel por categoria → província → município
 * (Outubro 2026). Cada lista do painel tem `/admin/<recurso>/groups` com as
 * contagens, e a lista normal aceita `province_id`/`municipality_id`
 * (o valor "none" = sem província/município).
 */
export type GroupResource = 'users' | 'stations' | 'orders' | 'comments' | 'reports' | 'drivers'

export interface MunicipalityGroup {
  municipality_id: number | null
  municipality: string
  count: number
}

export interface ProvinceGroup {
  province_id: number | null
  province: string
  count: number
  municipalities: MunicipalityGroup[]
}

export interface GroupTab {
  key: string
  count: number
}

export type GroupParams = Record<string, string | number | boolean | undefined>

export const NONE = 'none'
export const locationParam = (id: number | null) => (id === null ? NONE : id)

export const adminGroupsService = {
  async groups(resource: GroupResource, params: GroupParams = {}): Promise<{ tabs: GroupTab[]; groups: ProvinceGroup[] }> {
    const { data } = await api.get<{ data: { tabs: GroupTab[]; groups: ProvinceGroup[] } }>(`/admin/${resource}/groups`, { params })
    return data.data
  },
  async list<T>(resource: GroupResource, params: GroupParams = {}): Promise<Paginated<T>> {
    const { data } = await api.get<Paginated<T>>(`/admin/${resource}`, { params })
    return data
  },
}
