import { lazy } from 'react'
import {
  createRouter,
  createRootRoute,
  createRoute,
  redirect,
} from '@tanstack/react-router'
import { RootLayout } from './root-layout'
import { useAuthStore } from '@/stores/auth'

// Eager: login & 2FA (needed before auth), overview (most common landing)
import { LoginPage } from '@/pages/login'
import { TwoFactorChallengePage } from '@/pages/two-factor-challenge'
import { OverviewPage } from '@/pages/overview'

// Lazy: everything else
const PaymentsPage = lazy(() =>
  import('@/pages/payments').then((m) => ({ default: m.PaymentsPage })),
)
const PaymentDetailPage = lazy(() =>
  import('@/pages/payment-detail').then((m) => ({ default: m.PaymentDetailPage })),
)
const RefundsPage = lazy(() =>
  import('@/pages/refunds').then((m) => ({ default: m.RefundsPage })),
)
const CustomersPage = lazy(() =>
  import('@/pages/customers').then((m) => ({ default: m.CustomersPage })),
)
const CustomerDetailPage = lazy(() =>
  import('@/pages/customer-detail').then((m) => ({ default: m.CustomerDetailPage })),
)
const ApiKeysPage = lazy(() =>
  import('@/pages/api-keys').then((m) => ({ default: m.ApiKeysPage })),
)
const ConnectorsPage = lazy(() =>
  import('@/pages/connectors').then((m) => ({ default: m.ConnectorsPage })),
)
const ConnectorDetailPage = lazy(() =>
  import('@/pages/connector-detail').then((m) => ({ default: m.ConnectorDetailPage })),
)
const WebhooksPage = lazy(() =>
  import('@/pages/webhooks').then((m) => ({ default: m.WebhooksPage })),
)
const RoutingRulesPage = lazy(() =>
  import('@/pages/routing-rules').then((m) => ({ default: m.RoutingRulesPage })),
)
const OnboardingPage = lazy(() =>
  import('@/pages/onboarding').then((m) => ({ default: m.OnboardingPage })),
)
const TestPaymentPage = lazy(() =>
  import('@/pages/test-payment').then((m) => ({ default: m.TestPaymentPage })),
)
const EventLogsPage = lazy(() =>
  import('@/pages/event-logs').then((m) => ({ default: m.EventLogsPage })),
)
const OrganizationsPage = lazy(() =>
  import('@/pages/organizations').then((m) => ({ default: m.OrganizationsPage })),
)
const OrganizationDetailPage = lazy(() =>
  import('@/pages/organization-detail').then((m) => ({
    default: m.OrganizationDetailPage,
  })),
)
const MerchantsPage = lazy(() =>
  import('@/pages/merchants').then((m) => ({ default: m.MerchantsPage })),
)
const MerchantDetailPage = lazy(() =>
  import('@/pages/merchant-detail').then((m) => ({ default: m.MerchantDetailPage })),
)
const ProfilesPage = lazy(() =>
  import('@/pages/profiles').then((m) => ({ default: m.ProfilesPage })),
)
const ProfileDetailPage = lazy(() =>
  import('@/pages/profile-detail').then((m) => ({ default: m.ProfileDetailPage })),
)
const AuditLogPage = lazy(() =>
  import('@/pages/audit-log').then((m) => ({ default: m.AuditLogPage })),
)
const NotificationsPage = lazy(() =>
  import('@/pages/notifications').then((m) => ({ default: m.NotificationsPage })),
)
const DisputesPage = lazy(() =>
  import('@/pages/disputes').then((m) => ({ default: m.DisputesPage })),
)
const DisputeDetailPage = lazy(() =>
  import('@/pages/dispute-detail').then((m) => ({ default: m.DisputeDetailPage })),
)
const SettingsPage = lazy(() =>
  import('@/pages/settings').then((m) => ({ default: m.SettingsPage })),
)
const ConnectorHealthPage = lazy(() =>
  import('@/pages/connector-health').then((m) => ({ default: m.ConnectorHealthPage })),
)
const UsersPage = lazy(() =>
  import('@/pages/users').then((m) => ({ default: m.UsersPage })),
)

const rootRoute = createRootRoute({
  component: RootLayout,
  // Non-blocking: auth fetch is initiated in main.tsx before the router renders.
  // beforeLoad only reads existing synchronous store state — no awaiting.
  beforeLoad: () => {},
})

// Auth guard — redirects to /login only when auth resolution is complete.
// While isLoading is true, the fetch is still in-flight (initiated in main.tsx);
// we allow the route to render — RootLayout shows nothing sensitive until
// isAuthenticated is true, and the router re-evaluates once the store settles.
function requireAuth() {
  const { isAuthenticated, isLoading } = useAuthStore.getState()
  if (!isLoading && !isAuthenticated) {
    throw redirect({ to: '/login' })
  }
}

// Admin guard — redirects to /overview if not admin
function requireAdmin() {
  requireAuth()
  const { user, isLoading } = useAuthStore.getState()
  if (!isLoading) {
    const isAdmin = user?.roles?.some((r) => r.role === 'admin') ?? false
    if (!isAdmin) {
      throw redirect({ to: '/overview' })
    }
  }
}

const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  beforeLoad: () => {
    throw redirect({ to: '/overview' })
  },
})

const loginRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/login',
  component: LoginPage,
  beforeLoad: () => {
    const { isAuthenticated, isLoading } = useAuthStore.getState()
    // Only redirect away from login once we know for sure the user is authenticated.
    if (!isLoading && isAuthenticated) {
      throw redirect({ to: '/overview' })
    }
  },
})

const twoFactorRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/two-factor-challenge',
  component: TwoFactorChallengePage,
  beforeLoad: () => {
    const { requiresTwoFactor, isAuthenticated } = useAuthStore.getState()
    if (isAuthenticated) {
      throw redirect({ to: '/overview' })
    }
    if (!requiresTwoFactor) {
      throw redirect({ to: '/login' })
    }
  },
})

const overviewRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/overview',
  component: OverviewPage,
  beforeLoad: requireAuth,
})

const paymentsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/payments',
  component: PaymentsPage,
  beforeLoad: requireAuth,
})

const paymentDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/payments/$paymentKey',
  component: PaymentDetailPage,
  beforeLoad: requireAuth,
})

const refundsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/refunds',
  component: RefundsPage,
  beforeLoad: requireAuth,
})

const customersRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/customers',
  component: CustomersPage,
  beforeLoad: requireAuth,
})

const customerDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/customers/$customerKey',
  component: CustomerDetailPage,
  beforeLoad: requireAuth,
})

const apiKeysRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/api-keys',
  component: ApiKeysPage,
  beforeLoad: requireAuth,
})

const connectorsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/connectors',
  component: ConnectorsPage,
  beforeLoad: requireAuth,
})

const connectorDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/connectors/$connectorKey',
  component: ConnectorDetailPage,
  beforeLoad: requireAuth,
})

const webhooksRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/webhooks',
  component: WebhooksPage,
  beforeLoad: requireAuth,
})

const routingRulesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/routing',
  component: RoutingRulesPage,
  beforeLoad: requireAuth,
})

const onboardingRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/onboarding',
  component: OnboardingPage,
  beforeLoad: requireAuth,
})

const testPaymentRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/test-payment',
  component: TestPaymentPage,
  beforeLoad: requireAuth,
})

const eventLogsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/event-logs',
  component: EventLogsPage,
  beforeLoad: requireAuth,
})

const organizationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/organizations',
  component: OrganizationsPage,
  beforeLoad: requireAuth,
})

const organizationDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/organizations/$orgKey',
  component: OrganizationDetailPage,
  beforeLoad: requireAuth,
})

const merchantsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/merchants',
  component: MerchantsPage,
  beforeLoad: requireAuth,
})

const merchantDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/merchants/$merchantKey',
  component: MerchantDetailPage,
  beforeLoad: requireAuth,
})

const profilesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/profiles',
  component: ProfilesPage,
  beforeLoad: requireAuth,
})

const profileDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/profiles/$profileKey',
  component: ProfileDetailPage,
  beforeLoad: requireAuth,
})

const auditLogRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/audit-log',
  component: AuditLogPage,
  beforeLoad: requireAuth,
})

const notificationsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/notifications',
  component: NotificationsPage,
  beforeLoad: requireAuth,
})

const disputesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/disputes',
  component: DisputesPage,
  beforeLoad: requireAuth,
})

const disputeDetailRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/disputes/$disputeKey',
  component: DisputeDetailPage,
  beforeLoad: requireAuth,
})

const connectorHealthRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/connectors/$connectorKey/health',
  component: ConnectorHealthPage,
  beforeLoad: requireAuth,
})

const usersRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/users',
  component: UsersPage,
  beforeLoad: requireAdmin,
})

const settingsRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/settings',
  component: SettingsPage,
  beforeLoad: requireAuth,
})

const routeTree = rootRoute.addChildren([
  indexRoute,
  loginRoute,
  twoFactorRoute,
  overviewRoute,
  paymentsRoute,
  paymentDetailRoute,
  refundsRoute,
  customersRoute,
  customerDetailRoute,
  connectorsRoute,
  connectorDetailRoute,
  apiKeysRoute,
  webhooksRoute,
  routingRulesRoute,
  onboardingRoute,
  testPaymentRoute,
  eventLogsRoute,
  organizationsRoute,
  organizationDetailRoute,
  merchantsRoute,
  merchantDetailRoute,
  profilesRoute,
  profileDetailRoute,
  auditLogRoute,
  notificationsRoute,
  disputesRoute,
  disputeDetailRoute,
  connectorHealthRoute,
  usersRoute,
  settingsRoute,
])

export const router = createRouter({ routeTree })

// Re-run route guards once the async auth fetch resolves.
// When isLoading transitions to false the guards re-evaluate and redirect
// unauthenticated users to /login (or let authenticated users through).
let prevLoading = useAuthStore.getState().isLoading
useAuthStore.subscribe((state) => {
  if (prevLoading && !state.isLoading) {
    void router.invalidate()
  }
  prevLoading = state.isLoading
})

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router
  }
}
