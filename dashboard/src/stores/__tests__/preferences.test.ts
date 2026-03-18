/**
 * @vitest-environment happy-dom
 */
import { describe, it, expect, beforeEach, vi } from 'vitest'

// Mock zustand persist to use a simple in-memory storage
vi.mock('zustand/middleware', async () => {
  const actual =
    await vi.importActual<typeof import('zustand/middleware')>('zustand/middleware')
  return {
    ...actual,
    persist: (config: unknown) => config,
  }
})

import { usePreferencesStore } from '../preferences'

describe('usePreferencesStore', () => {
  beforeEach(() => {
    usePreferencesStore.setState({
      density: 'comfortable',
      timezone: 'Europe/Moscow',
      sidebarCollapsed: false,
    })
  })

  it('has correct default state', () => {
    const state = usePreferencesStore.getState()
    expect(state.density).toBe('comfortable')
    expect(state.timezone).toBe('Europe/Moscow')
    expect(state.sidebarCollapsed).toBe(false)
  })

  it('setDensity changes to compact', () => {
    usePreferencesStore.getState().setDensity('compact')
    expect(usePreferencesStore.getState().density).toBe('compact')
  })

  it('setDensity changes to spacious', () => {
    usePreferencesStore.getState().setDensity('spacious')
    expect(usePreferencesStore.getState().density).toBe('spacious')
  })

  it('setTimezone updates timezone', () => {
    usePreferencesStore.getState().setTimezone('America/New_York')
    expect(usePreferencesStore.getState().timezone).toBe('America/New_York')
  })

  it('setTimezone to UTC', () => {
    usePreferencesStore.getState().setTimezone('UTC')
    expect(usePreferencesStore.getState().timezone).toBe('UTC')
  })

  it('toggleSidebar flips sidebarCollapsed from false to true', () => {
    expect(usePreferencesStore.getState().sidebarCollapsed).toBe(false)
    usePreferencesStore.getState().toggleSidebar()
    expect(usePreferencesStore.getState().sidebarCollapsed).toBe(true)
  })

  it('toggleSidebar flips sidebarCollapsed from true to false', () => {
    usePreferencesStore.getState().toggleSidebar()
    expect(usePreferencesStore.getState().sidebarCollapsed).toBe(true)
    usePreferencesStore.getState().toggleSidebar()
    expect(usePreferencesStore.getState().sidebarCollapsed).toBe(false)
  })

  it('multiple toggles work correctly', () => {
    const { toggleSidebar } = usePreferencesStore.getState()
    toggleSidebar()
    toggleSidebar()
    toggleSidebar()
    expect(usePreferencesStore.getState().sidebarCollapsed).toBe(true)
  })

  it('setters do not affect other state fields', () => {
    usePreferencesStore.getState().setDensity('compact')
    const state = usePreferencesStore.getState()
    expect(state.timezone).toBe('Europe/Moscow')
    expect(state.sidebarCollapsed).toBe(false)
  })
})
