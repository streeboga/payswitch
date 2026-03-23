import type { LucideIcon } from 'lucide-react'
import type { TFunction } from 'i18next'
import {
  LayoutDashboard,
  CreditCard,
  RotateCcw,
  ShieldAlert,
  Users,
  Plug,
  GitBranch,
  Key,
  Webhook,
  Rocket,
  FlaskConical,
  ScrollText,
  Building2,
  Store,
  Briefcase,
  UserCog,
  FileSearch,
  Code,
} from 'lucide-react'

export interface NavItem {
  label: string
  path: string
  icon: LucideIcon
  badge?: number
  adminOnly?: boolean
  testOnly?: boolean
}

export interface NavGroup {
  label: string
  items: NavItem[]
  adminOnly?: boolean
}

export function getNavGroups(t: TFunction): NavGroup[] {
  return [
    {
      label: t('sidebar.operations'),
      items: [
        { label: t('sidebar.overview'), path: '/overview', icon: LayoutDashboard },
        { label: t('sidebar.payments'), path: '/payments', icon: CreditCard },
        { label: t('sidebar.refunds'), path: '/refunds', icon: RotateCcw },
        { label: t('sidebar.disputes'), path: '/disputes', icon: ShieldAlert },
      ],
    },
    {
      label: t('sidebar.configuration'),
      items: [
        { label: t('sidebar.customers'), path: '/customers', icon: Users },
        { label: t('sidebar.connectors'), path: '/connectors', icon: Plug },
        { label: t('sidebar.routing'), path: '/routing', icon: GitBranch },
        { label: t('sidebar.apiKeys'), path: '/api-keys', icon: Key },
        { label: t('sidebar.webhooks'), path: '/webhooks', icon: Webhook },
      ],
    },
    {
      label: t('sidebar.development'),
      items: [
        {
          label: t('sidebar.onboarding'),
          path: '/onboarding',
          icon: Rocket,
          testOnly: true,
        },
        {
          label: t('sidebar.testPayment'),
          path: '/test-payment',
          icon: FlaskConical,
          testOnly: true,
        },
        {
          label: t('sidebar.integration'),
          path: '/integration',
          icon: Code,
        },
        { label: t('sidebar.eventLogs'), path: '/event-logs', icon: ScrollText },
      ],
    },
    {
      label: t('sidebar.management'),
      adminOnly: true,
      items: [
        {
          label: t('sidebar.organizations'),
          path: '/organizations',
          icon: Building2,
          adminOnly: true,
        },
        {
          label: t('sidebar.merchants'),
          path: '/merchants',
          icon: Store,
          adminOnly: true,
        },
        {
          label: t('sidebar.profiles'),
          path: '/profiles',
          icon: Briefcase,
          adminOnly: true,
        },
        {
          label: t('sidebar.users'),
          path: '/users',
          icon: UserCog,
          adminOnly: true,
        },
        {
          label: t('sidebar.auditLog'),
          path: '/audit-log',
          icon: FileSearch,
          adminOnly: true,
        },
      ],
    },
    // Account items (Settings, Notifications) are rendered in the sidebar bottom bar
  ]
}
