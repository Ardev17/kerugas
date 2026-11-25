import { create } from 'zustand'

/**
 * Estado de atualização do app Android (Outubro 2026).
 * - `required`: versão abaixo da mínima,ecrã bloqueante "Atualize".
 * - `available`: há versão nova, mas esta ainda serve,aviso que se fecha.
 */
interface AppUpdateState {
  required: boolean
  available: boolean
  dismissed: boolean
  storeUrl: string | null
  setRequired: (storeUrl: string | null) => void
  setAvailable: (available: boolean, storeUrl: string | null) => void
  dismiss: () => void
}

export const DEFAULT_STORE_URL = 'https://play.google.com/store/apps/details?id=ao.kerugas.app'

/** Só links https da Play Store ou da App Store (nunca um URL arbitrário vindo da rede). */
export function safeStoreUrl(url: unknown): string {
  if (typeof url !== 'string') return DEFAULT_STORE_URL
  const play = /^https:\/\/play\.google\.com\/store\/apps\/details\?id=[\w.]+$/.test(url)
  const apple = /^https:\/\/apps\.apple\.com\/[\w\-./%]+$/.test(url)
  return play || apple ? url : DEFAULT_STORE_URL
}

export const useAppUpdateStore = create<AppUpdateState>((set) => ({
  required: false,
  available: false,
  dismissed: false,
  storeUrl: null,
  setRequired: (storeUrl) => set({ required: true, storeUrl: safeStoreUrl(storeUrl) }),
  setAvailable: (available, storeUrl) => set((s) => ({ available, storeUrl: s.required ? s.storeUrl : safeStoreUrl(storeUrl) })),
  dismiss: () => set({ dismissed: true }),
}))
