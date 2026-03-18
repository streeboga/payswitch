import { create } from 'zustand'

export interface UserRole {
  organization_id: string
  role: 'admin' | 'operator' | 'viewer'
}

export interface User {
  id: number
  name: string
  email: string
  email_verified_at: string | null
  two_factor_enabled?: boolean
  role?: 'admin' | 'operator' | 'viewer'
  roles?: UserRole[]
}

interface AuthState {
  user: User | null
  isAuthenticated: boolean
  isLoading: boolean
  requiresTwoFactor: boolean
  setUser: (user: User | null) => void
  setLoading: (loading: boolean) => void
  clearUser: () => void
  setRequiresTwoFactor: (requires: boolean) => void
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  isAuthenticated: false,
  isLoading: true,
  requiresTwoFactor: false,
  setUser: (user) => set({ user, isAuthenticated: !!user, isLoading: false }),
  setLoading: (isLoading) => set({ isLoading }),
  clearUser: () => set({ user: null, isAuthenticated: false, isLoading: false }),
  setRequiresTwoFactor: (requiresTwoFactor) => set({ requiresTwoFactor }),
}))
