import { create } from 'zustand'
import { persist } from 'zustand/middleware'

interface ContextState {
  currentOrgKey: string | null
  currentMerchantKey: string | null
  currentProfileKey: string | null
  testMode: boolean
  setOrg: (key: string | null) => void
  setMerchant: (key: string | null) => void
  setProfile: (key: string | null) => void
  setTestMode: (testMode: boolean) => void
  resetContext: () => void
}

export const useContextStore = create<ContextState>()(
  persist(
    (set) => ({
      currentOrgKey: null,
      currentMerchantKey: null,
      currentProfileKey: null,
      testMode: true,
      setOrg: (currentOrgKey) =>
        set({ currentOrgKey, currentMerchantKey: null, currentProfileKey: null }),
      setMerchant: (currentMerchantKey) =>
        set({ currentMerchantKey, currentProfileKey: null }),
      setProfile: (currentProfileKey) => set({ currentProfileKey }),
      setTestMode: (testMode) => set({ testMode }),
      resetContext: () =>
        set({ currentOrgKey: null, currentMerchantKey: null, currentProfileKey: null }),
    }),
    { name: 'payswitch-context', version: 1 },
  ),
)
