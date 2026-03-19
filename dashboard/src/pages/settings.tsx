import { useState, useMemo } from 'react'
import { useForm } from 'react-hook-form'
import { useTranslation } from 'react-i18next'
import { useTheme } from 'next-themes'
import { z } from 'zod'
import { zodResolver } from '@hookform/resolvers/zod'
import {
  Settings as SettingsIcon,
  User,
  Shield,
  Palette,
  Globe,
  Bell,
} from 'lucide-react'

import { useAuthStore } from '@/stores/auth'
import { usePreferencesStore, type Density } from '@/stores/preferences'
import { supportedLanguages } from '@/lib/i18n'
import { dashboardSettings } from '@/api/endpoints/dashboard-settings'

import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Separator } from '@/components/ui/separator'

// ─── Timezone list ───────────────────────────────────────────

const TIMEZONES = [
  'Europe/Moscow',
  'Europe/London',
  'Europe/Berlin',
  'Europe/Paris',
  'Europe/Istanbul',
  'Europe/Kiev',
  'Europe/Minsk',
  'Europe/Samara',
  'Asia/Yekaterinburg',
  'Asia/Novosibirsk',
  'Asia/Krasnoyarsk',
  'Asia/Irkutsk',
  'Asia/Yakutsk',
  'Asia/Vladivostok',
  'Asia/Kamchatka',
  'Asia/Tokyo',
  'Asia/Shanghai',
  'Asia/Singapore',
  'Asia/Dubai',
  'Asia/Kolkata',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'America/Sao_Paulo',
  'Pacific/Auckland',
  'Australia/Sydney',
  'UTC',
]

const DATE_FORMATS = [
  { value: 'DD.MM.YYYY', label: 'DD.MM.YYYY' },
  { value: 'MM/DD/YYYY', label: 'MM/DD/YYYY' },
  { value: 'YYYY-MM-DD', label: 'YYYY-MM-DD' },
]

// ─── Page Component ──────────────────────────────────────────

export function SettingsPage() {
  const { t } = useTranslation()

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <SettingsIcon className="text-muted-foreground h-7 w-7" />
        <h1 className="text-3xl font-bold">{t('settings.title')}</h1>
      </div>

      <Tabs defaultValue="profile" className="space-y-6">
        <TabsList>
          <TabsTrigger value="profile" className="gap-1.5">
            <User className="h-4 w-4" />
            {t('settings.tabProfile')}
          </TabsTrigger>
          <TabsTrigger value="security" className="gap-1.5">
            <Shield className="h-4 w-4" />
            {t('settings.tabSecurity')}
          </TabsTrigger>
          <TabsTrigger value="appearance" className="gap-1.5">
            <Palette className="h-4 w-4" />
            {t('settings.tabAppearance')}
          </TabsTrigger>
          <TabsTrigger value="regional" className="gap-1.5">
            <Globe className="h-4 w-4" />
            {t('settings.tabRegional')}
          </TabsTrigger>
          <TabsTrigger value="notifications" className="gap-1.5">
            <Bell className="h-4 w-4" />
            {t('settings.tabNotifications')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="profile">
          <ProfileTab />
        </TabsContent>

        <TabsContent value="security">
          <SecurityTab />
        </TabsContent>

        <TabsContent value="appearance">
          <AppearanceTab />
        </TabsContent>

        <TabsContent value="regional">
          <RegionalTab />
        </TabsContent>

        <TabsContent value="notifications">
          <NotificationsTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

// ─── Profile Tab ─────────────────────────────────────────────

function ProfileTab() {
  const { t } = useTranslation()
  const user = useAuthStore((s) => s.user)

  const profileSchema = useMemo(
    () =>
      z.object({
        name: z.string().min(1, t('settings.nameRequired')),
        email: z.string().email(t('settings.emailInvalid')),
      }),
    [t],
  )

  type ProfileFormValues = z.infer<typeof profileSchema>

  const form = useForm<ProfileFormValues>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      name: user?.name ?? '',
      email: user?.email ?? '',
    },
  })

  const onSubmit = async (data: ProfileFormValues) => {
    await dashboardSettings.update(data)
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.profileTitle')}</CardTitle>
        <CardDescription>{t('settings.profileDesc')}</CardDescription>
      </CardHeader>
      <CardContent>
        <Form {...form}>
          <form
            onSubmit={(e) => void form.handleSubmit(onSubmit)(e)}
            className="space-y-4"
          >
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('settings.nameLabel')}</FormLabel>
                  <FormControl>
                    <Input placeholder={t('settings.namePlaceholder')} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('settings.emailLabel')}</FormLabel>
                  <FormControl>
                    <Input
                      type="email"
                      placeholder={t('settings.emailPlaceholder')}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <Button type="submit">{t('common.save')}</Button>
          </form>
        </Form>
      </CardContent>
    </Card>
  )
}

// ─── Security Tab ────────────────────────────────────────────

function SecurityTab() {
  const { t } = useTranslation()
  const user = useAuthStore((s) => s.user)
  const twoFactorFromServer = user?.two_factor_enabled ?? false
  const [twoFactorOverride, setTwoFactorOverride] = useState<boolean | null>(null)
  const twoFactorEnabled = twoFactorOverride ?? twoFactorFromServer
  const setTwoFactorEnabled = (val: boolean) => setTwoFactorOverride(val)

  const passwordSchema = useMemo(
    () =>
      z
        .object({
          current_password: z.string().min(1, t('settings.currentPasswordRequired')),
          new_password: z.string().min(8, t('settings.newPasswordMin')),
          new_password_confirmation: z
            .string()
            .min(1, t('settings.confirmPasswordRequired')),
        })
        .refine((data) => data.new_password === data.new_password_confirmation, {
          message: t('settings.passwordsMismatch'),
          path: ['new_password_confirmation'],
        }),
    [t],
  )

  type PasswordFormValues = z.infer<typeof passwordSchema>

  const form = useForm<PasswordFormValues>({
    resolver: zodResolver(passwordSchema),
    defaultValues: {
      current_password: '',
      new_password: '',
      new_password_confirmation: '',
    },
  })

  const onSubmit = async (data: PasswordFormValues) => {
    await dashboardSettings.changePassword(data)
    form.reset()
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle>{t('settings.passwordTitle')}</CardTitle>
          <CardDescription>{t('settings.passwordDesc')}</CardDescription>
        </CardHeader>
        <CardContent>
          <Form {...form}>
            <form
              onSubmit={(e) => void form.handleSubmit(onSubmit)(e)}
              className="space-y-4"
            >
              <FormField
                control={form.control}
                name="current_password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('settings.currentPasswordLabel')}</FormLabel>
                    <FormControl>
                      <Input type="password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="new_password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('settings.newPasswordLabel')}</FormLabel>
                    <FormControl>
                      <Input type="password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <FormField
                control={form.control}
                name="new_password_confirmation"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('settings.confirmPasswordLabel')}</FormLabel>
                    <FormControl>
                      <Input type="password" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
              <Button type="submit">{t('settings.changePasswordButton')}</Button>
            </form>
          </Form>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>{t('settings.twoFactorTitle')}</CardTitle>
          <CardDescription>{t('settings.twoFactorDesc')}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="flex items-center justify-between">
            <Label htmlFor="2fa-toggle">{t('settings.twoFactorToggle')}</Label>
            <Switch
              id="2fa-toggle"
              checked={twoFactorEnabled}
              onCheckedChange={setTwoFactorEnabled}
            />
          </div>

          {twoFactorEnabled && (
            <>
              <Separator />
              <div className="space-y-3">
                <p className="text-muted-foreground text-sm">
                  {t('settings.twoFactorScanDesc')}
                </p>
                <div className="bg-muted flex h-48 w-48 items-center justify-center rounded-md border">
                  <span className="text-muted-foreground text-xs">
                    {t('settings.qrCode')}
                  </span>
                </div>
              </div>

              <Separator />

              <div className="space-y-2">
                <Label>{t('settings.recoveryCodes')}</Label>
                <p className="text-muted-foreground text-sm">
                  {t('settings.recoveryCodesDesc')}
                </p>
                <div className="bg-muted grid grid-cols-2 gap-2 rounded-md border p-4 font-mono text-sm">
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                  <span>XXXX-XXXX-XXXX</span>
                </div>
              </div>
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

// ─── Appearance Tab ──────────────────────────────────────────

function AppearanceTab() {
  const { t } = useTranslation()
  const { theme, setTheme } = useTheme()
  const density = usePreferencesStore((s) => s.density)
  const setDensity = usePreferencesStore((s) => s.setDensity)

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.appearanceTitle')}</CardTitle>
        <CardDescription>{t('settings.appearanceDesc')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          <Label>{t('settings.themeLabel')}</Label>
          <Select value={theme} onValueChange={setTheme}>
            <SelectTrigger className="w-[200px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="light">{t('settings.themeLight')}</SelectItem>
              <SelectItem value="dark">{t('settings.themeDark')}</SelectItem>
              <SelectItem value="system">{t('settings.themeSystem')}</SelectItem>
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label>{t('settings.densityLabel')}</Label>
          <Select value={density} onValueChange={(v) => setDensity(v as Density)}>
            <SelectTrigger className="w-[200px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="compact">{t('settings.densityCompact')}</SelectItem>
              <SelectItem value="comfortable">
                {t('settings.densityComfortable')}
              </SelectItem>
              <SelectItem value="spacious">{t('settings.densitySpacious')}</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Regional Tab ────────────────────────────────────────────

function RegionalTab() {
  const { t, i18n } = useTranslation()
  const timezone = usePreferencesStore((s) => s.timezone)
  const setTimezone = usePreferencesStore((s) => s.setTimezone)
  const [dateFormat, setDateFormat] = useState('DD.MM.YYYY')

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.regionalTitle')}</CardTitle>
        <CardDescription>{t('settings.regionalDesc')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <div className="space-y-2">
          <Label>{t('settings.languageLabel')}</Label>
          <Select
            value={i18n.language}
            onValueChange={(v) => void i18n.changeLanguage(v)}
          >
            <SelectTrigger className="w-[200px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {supportedLanguages.map((lang) => (
                <SelectItem key={lang.code} value={lang.code}>
                  {lang.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label>{t('settings.timezoneLabel')}</Label>
          <Select value={timezone} onValueChange={setTimezone}>
            <SelectTrigger className="w-[280px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {TIMEZONES.map((tz) => (
                <SelectItem key={tz} value={tz}>
                  {tz}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div className="space-y-2">
          <Label>{t('settings.dateFormatLabel')}</Label>
          <Select value={dateFormat} onValueChange={setDateFormat}>
            <SelectTrigger className="w-[200px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {DATE_FORMATS.map((fmt) => (
                <SelectItem key={fmt.value} value={fmt.value}>
                  {fmt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </CardContent>
    </Card>
  )
}

// ─── Notifications Tab ───────────────────────────────────────

const NOTIFICATION_KEYS = [
  'failed_payments',
  'disputes',
  'webhook_failures',
  'connector_alerts',
  'api_key_expiry',
] as const

const notifLabelKey: Record<string, string> = {
  failed_payments: 'settings.notifFailedPayments',
  disputes: 'settings.notifDisputes',
  webhook_failures: 'settings.notifWebhookFailures',
  connector_alerts: 'settings.notifConnectorAlerts',
  api_key_expiry: 'settings.notifApiKeyExpiry',
}

const notifDescKey: Record<string, string> = {
  failed_payments: 'settings.notifFailedPaymentsDesc',
  disputes: 'settings.notifDisputesDesc',
  webhook_failures: 'settings.notifWebhookFailuresDesc',
  connector_alerts: 'settings.notifConnectorAlertsDesc',
  api_key_expiry: 'settings.notifApiKeyExpiryDesc',
}

function NotificationsTab() {
  const { t } = useTranslation()
  const [settings, setSettings] = useState<Record<string, boolean>>(() => {
    const initial: Record<string, boolean> = {}
    for (const key of NOTIFICATION_KEYS) {
      initial[key] = true
    }
    return initial
  })

  const toggleSetting = (key: string) => {
    setSettings((prev) => ({ ...prev, [key]: !prev[key] }))
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t('settings.notificationsTitle')}</CardTitle>
        <CardDescription>{t('settings.notificationsDesc')}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {NOTIFICATION_KEYS.map((key) => (
          <div
            key={key}
            className="flex items-center justify-between rounded-lg border p-4"
          >
            <div className="space-y-0.5">
              <Label>{t(notifLabelKey[key]!)}</Label>
              <p className="text-muted-foreground text-sm">{t(notifDescKey[key]!)}</p>
            </div>
            <Switch
              checked={settings[key] ?? false}
              onCheckedChange={() => toggleSetting(key)}
            />
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
