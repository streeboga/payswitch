import { create } from 'zustand'
import { persist } from 'zustand/middleware'

export type Density = 'compact' | 'comfortable' | 'spacious'

interface PreferencesState {
  density: Density
  timezone: string
  sidebarCollapsed: boolean
  setDensity: (density: Density) => void
  setTimezone: (timezone: string) => void
  toggleSidebar: () => void
}

export const usePreferencesStore = create<PreferencesState>()(
  persist(
    (set) => ({
      density: 'comfortable',
      timezone: 'Europe/Moscow',
      sidebarCollapsed: false,
      setDensity: (density) => set({ density }),
      setTimezone: (timezone) => set({ timezone }),
      toggleSidebar: () => set((s) => ({ sidebarCollapsed: !s.sidebarCollapsed })),
    }),
    { name: 'payswitch-preferences', version: 1 },
  ),
)
