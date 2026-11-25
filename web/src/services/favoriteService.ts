import { api } from '@/lib/api'
import type { ApiSuccess, Favorite } from '@/types'
import type { AccessBlock, BlockedMunicipality } from '@/services/stationService'

interface FavoriteListMeta {
  hidden_count?: number
  blocked_municipalities?: BlockedMunicipality[]
  access_block?: AccessBlock | null
}

export interface FavoriteList {
  favorites: Favorite[]
  hiddenCount: number
  blocked: BlockedMunicipality[]
  accessBlock: AccessBlock | null
}

export const favoriteService = {
  /**
   * Novembro 2026: a lista pode vir curta de propósito,um favorito num
   * município fechado, ou num posto sem presença paga, deixa de aparecer. O
   * `meta` diz quantos ficaram de fora e porquê, para o ecrã explicar.
   */
  async list(): Promise<FavoriteList> {
    const { data } = await api.get<ApiSuccess<Favorite[]> & { meta?: FavoriteListMeta }>('/favorites')

    return {
      favorites: data.data,
      hiddenCount: data.meta?.hidden_count ?? 0,
      blocked: data.meta?.blocked_municipalities ?? [],
      accessBlock: data.meta?.access_block ?? null,
    }
  },

  async add(stationId: number): Promise<void> {
    await api.post(`/favorites/${stationId}`)
  },

  async remove(stationId: number): Promise<void> {
    await api.delete(`/favorites/${stationId}`)
  },
}
