import { create } from 'zustand'
import { hasDriverLocationConsent, setDriverLocationConsent, type DriverLocationState } from '@/native/driverLocation'

/** Estado da partilha de localização do condutor (Novembro 2026). */
interface DriverLocationStore {
  consent: boolean
  state: DriverLocationState
  setConsent: (v: boolean) => void
  setState: (s: DriverLocationState) => void
}

export const useDriverLocationStore = create<DriverLocationStore>((set) => ({
  consent: hasDriverLocationConsent(),
  state: 'off',
  setConsent: (v) => {
    setDriverLocationConsent(v)
    set({ consent: v })
  },
  setState: (state) => set({ state }),
}))
