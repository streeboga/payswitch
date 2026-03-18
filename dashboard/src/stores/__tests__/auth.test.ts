import { describe, it, expect, beforeEach } from 'vitest'
import { useAuthStore } from '../auth'

describe('useAuthStore', () => {
  beforeEach(() => {
    // Сброс стора перед каждым тестом
    useAuthStore.setState({
      user: null,
      isAuthenticated: false,
      isLoading: true,
    })
  })

  it('начальное состояние — не авторизован, загрузка', () => {
    const state = useAuthStore.getState()
    expect(state.user).toBeNull()
    expect(state.isAuthenticated).toBe(false)
    expect(state.isLoading).toBe(true)
  })

  it('setUser устанавливает пользователя и isAuthenticated', () => {
    const user = {
      id: 1,
      name: 'Тест',
      email: 'test@example.com',
      email_verified_at: '2026-01-01',
      role: 'admin' as const,
    }

    useAuthStore.getState().setUser(user)
    const state = useAuthStore.getState()

    expect(state.user).toEqual(user)
    expect(state.isAuthenticated).toBe(true)
    expect(state.isLoading).toBe(false)
  })

  it('setUser(null) сбрасывает авторизацию', () => {
    useAuthStore.getState().setUser({
      id: 1,
      name: 'Тест',
      email: 'test@example.com',
      email_verified_at: null,
    })
    useAuthStore.getState().setUser(null)

    const state = useAuthStore.getState()
    expect(state.user).toBeNull()
    expect(state.isAuthenticated).toBe(false)
    expect(state.isLoading).toBe(false)
  })

  it('clearUser сбрасывает всё', () => {
    useAuthStore.getState().setUser({
      id: 1,
      name: 'Тест',
      email: 'test@example.com',
      email_verified_at: null,
    })
    useAuthStore.getState().clearUser()

    const state = useAuthStore.getState()
    expect(state.user).toBeNull()
    expect(state.isAuthenticated).toBe(false)
    expect(state.isLoading).toBe(false)
  })

  it('setLoading меняет состояние загрузки', () => {
    useAuthStore.getState().setLoading(false)
    expect(useAuthStore.getState().isLoading).toBe(false)

    useAuthStore.getState().setLoading(true)
    expect(useAuthStore.getState().isLoading).toBe(true)
  })

  it('requiresTwoFactor отражает состояние 2FA', () => {
    expect(useAuthStore.getState().requiresTwoFactor).toBe(false)

    useAuthStore.getState().setRequiresTwoFactor(true)
    expect(useAuthStore.getState().requiresTwoFactor).toBe(true)

    useAuthStore.getState().setRequiresTwoFactor(false)
    expect(useAuthStore.getState().requiresTwoFactor).toBe(false)
  })
})
