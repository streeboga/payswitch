import { describe, it, expect, vi, afterEach } from 'vitest'
import { render, screen, cleanup } from '@testing-library/react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

// Mock stores
const mockContextState = {
  currentOrgKey: 'org1',
  currentMerchantKey: 'mer1',
  currentProfileKey: null,
  setOrg: vi.fn(),
  setMerchant: vi.fn(),
  setProfile: vi.fn(),
}

const mockPrefsState = {
  sidebarCollapsed: false,
}

vi.mock('@/stores/context', () => ({
  useContextStore: (selector?: (state: typeof mockContextState) => unknown) =>
    selector ? selector(mockContextState) : mockContextState,
}))

vi.mock('@/stores/preferences', () => ({
  usePreferencesStore: (selector?: (state: typeof mockPrefsState) => unknown) =>
    selector ? selector(mockPrefsState) : mockPrefsState,
}))

// Mock hooks
vi.mock('@/hooks/use-context-data', () => ({
  useOrganizations: () => ({
    data: [{ key: 'org1', name: 'Org One' }],
    isLoading: false,
  }),
  useMerchants: () => ({
    data: [{ key: 'mer1', name: 'Merchant One' }],
    isLoading: false,
  }),
  useProfiles: () => ({
    data: [
      { key: 'prof1', name: 'Profile One' },
      { key: 'prof2', name: 'Profile Two' },
    ],
    isLoading: false,
  }),
}))

// Mock Tooltip to avoid Radix portal issues
vi.mock('@/components/ui/tooltip', () => ({
  Tooltip: ({ children }: { children: React.ReactNode }) => <>{children}</>,
  TooltipTrigger: ({
    children,
    asChild: _asChild,
    ...props
  }: {
    children: React.ReactNode
    asChild?: boolean
  }) => <div {...props}>{children}</div>,
  TooltipContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}))

// Mock Select to avoid Radix portal issues
vi.mock('@/components/ui/select', () => ({
  Select: ({
    children,
    ...props
  }: {
    children: React.ReactNode
    value?: string
    onValueChange?: (v: string) => void
    disabled?: boolean
  }) => (
    <div data-disabled={props.disabled ?? false} data-value={props.value}>
      {children}
    </div>
  ),
  SelectTrigger: ({
    children,
    ...props
  }: {
    children: React.ReactNode
    className?: string
    'data-testid'?: string
    'aria-label'?: string
  }) => (
    <button data-testid={props['data-testid']} aria-label={props['aria-label']}>
      {children}
    </button>
  ),
  SelectValue: ({ placeholder }: { placeholder?: string }) => <span>{placeholder}</span>,
  SelectContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  SelectItem: ({
    children,
    value,
  }: {
    children: React.ReactNode
    value: string
    className?: string
  }) => <div data-value={value}>{children}</div>,
}))

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  })
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }
}

describe('ContextSwitcher', () => {
  afterEach(() => {
    cleanup()
  })

  it('renders 3 selects for org, merchant, and profile', async () => {
    const { ContextSwitcher } = await import('../context-switcher')
    render(<ContextSwitcher />, { wrapper: createWrapper() })

    expect(screen.getByTestId('org-select')).toBeDefined()
    expect(screen.getByTestId('merchant-select')).toBeDefined()
    expect(screen.getByTestId('profile-select')).toBeDefined()
  })

  it('renders context-switcher test id', async () => {
    const { ContextSwitcher } = await import('../context-switcher')
    render(<ContextSwitcher />, { wrapper: createWrapper() })

    expect(screen.getByTestId('context-switcher')).toBeDefined()
  })

  it('renders labels for organization, merchant, and profile', async () => {
    const { ContextSwitcher } = await import('../context-switcher')
    render(<ContextSwitcher />, { wrapper: createWrapper() })

    expect(screen.getByText('contextSwitcher.orgLabel')).toBeDefined()
    expect(screen.getByText('contextSwitcher.merchantLabel')).toBeDefined()
    expect(screen.getByText('contextSwitcher.profileLabel')).toBeDefined()
  })

  it('renders "all profiles" option', async () => {
    const { ContextSwitcher } = await import('../context-switcher')
    render(<ContextSwitcher />, { wrapper: createWrapper() })

    expect(screen.getAllByText('contextSwitcher.allProfiles').length).toBeGreaterThanOrEqual(1)
  })
})
