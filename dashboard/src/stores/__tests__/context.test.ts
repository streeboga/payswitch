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

import { useContextStore } from '../context'

describe('useContextStore', () => {
  beforeEach(() => {
    useContextStore.setState({
      currentOrgKey: null,
      currentMerchantKey: null,
      currentProfileKey: null,
      testMode: true,
    })
  })

  it('initial state has null keys and testMode true', () => {
    const state = useContextStore.getState()
    expect(state.currentOrgKey).toBeNull()
    expect(state.currentMerchantKey).toBeNull()
    expect(state.currentProfileKey).toBeNull()
    expect(state.testMode).toBe(true)
  })

  it('setOrg sets org and resets merchant and profile', () => {
    useContextStore.getState().setMerchant('m1')
    useContextStore.getState().setProfile('p1')
    useContextStore.getState().setOrg('org1')

    const state = useContextStore.getState()
    expect(state.currentOrgKey).toBe('org1')
    expect(state.currentMerchantKey).toBeNull()
    expect(state.currentProfileKey).toBeNull()
  })

  it('setMerchant sets merchant and resets profile', () => {
    useContextStore.getState().setOrg('org1')
    useContextStore.getState().setMerchant('m1')
    useContextStore.getState().setProfile('p1')

    // Now change merchant — profile should reset
    useContextStore.getState().setMerchant('m2')

    const state = useContextStore.getState()
    expect(state.currentOrgKey).toBe('org1')
    expect(state.currentMerchantKey).toBe('m2')
    expect(state.currentProfileKey).toBeNull()
  })

  it('setProfile sets only profile without affecting org/merchant', () => {
    useContextStore.getState().setOrg('org1')
    useContextStore.getState().setMerchant('m1')
    useContextStore.getState().setProfile('p1')

    const state = useContextStore.getState()
    expect(state.currentOrgKey).toBe('org1')
    expect(state.currentMerchantKey).toBe('m1')
    expect(state.currentProfileKey).toBe('p1')
  })

  it('setOrg(null) clears all keys', () => {
    useContextStore.getState().setOrg('org1')
    useContextStore.getState().setMerchant('m1')
    useContextStore.getState().setProfile('p1')

    useContextStore.getState().setOrg(null)

    const state = useContextStore.getState()
    expect(state.currentOrgKey).toBeNull()
    expect(state.currentMerchantKey).toBeNull()
    expect(state.currentProfileKey).toBeNull()
  })

  it('setTestMode toggles testMode', () => {
    useContextStore.getState().setTestMode(false)
    expect(useContextStore.getState().testMode).toBe(false)

    useContextStore.getState().setTestMode(true)
    expect(useContextStore.getState().testMode).toBe(true)
  })

  it('cascading reset: changing org after full selection clears merchant and profile', () => {
    useContextStore.getState().setOrg('org1')
    useContextStore.getState().setMerchant('m1')
    useContextStore.getState().setProfile('p1')

    // Switch org
    useContextStore.getState().setOrg('org2')

    const state = useContextStore.getState()
    expect(state.currentOrgKey).toBe('org2')
    expect(state.currentMerchantKey).toBeNull()
    expect(state.currentProfileKey).toBeNull()
  })
})
