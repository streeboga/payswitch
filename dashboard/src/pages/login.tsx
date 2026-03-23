import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from '@tanstack/react-router'
import { useTheme } from 'next-themes'
import { AlertCircle, Moon, Sun, Monitor } from 'lucide-react'
import { auth } from '@/api/endpoints/auth'
import { useAuthStore } from '@/stores/auth'
import { useContextStore } from '@/stores/context'
import { supportedLanguages } from '@/lib/i18n'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Alert, AlertDescription } from '@/components/ui/alert'

type LoginFormData = { email: string; password: string }

export function LoginPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const setUser = useAuthStore((s) => s.setUser)
  const setRequiresTwoFactor = useAuthStore((s) => s.setRequiresTwoFactor)
  const [serverError, setServerError] = useState<string | null>(null)

  const loginSchema = z.object({
    email: z.string().email(t('auth.emailError')),
    password: z.string().min(1, t('auth.passwordError')),
  })

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<LoginFormData>({
    resolver: zodResolver(loginSchema),
  })

  const onSubmit = async (data: LoginFormData) => {
    setServerError(null)
    try {
      const response = await auth.login(data)

      if (response.two_factor) {
        setRequiresTwoFactor(true)
        await navigate({ to: '/two-factor-challenge' })
        return
      }

      // Reset stale org/merchant/profile context from previous session
      // so the context switcher picks from fresh data.
      const ctx = useContextStore.getState()
      ctx.setOrg(null)

      const user = await auth.user()
      setUser(user)
      await navigate({ to: '/overview' })
    } catch (error: unknown) {
      if (error && typeof error === 'object' && 'response' in error) {
        const resp = (error as { response: Response }).response
        if (resp.status === 422) {
          const body = await resp.json()
          const message =
            body?.errors?.email?.[0] ?? body?.message ?? t('auth.loginError')
          setServerError(message)
          return
        }
      }
      setServerError(t('errors.serverError'))
    }
  }

  const { i18n } = useTranslation()
  const { theme, setTheme } = useTheme()

  const themeIcon =
    theme === 'dark' ? (
      <Moon className="h-3.5 w-3.5" />
    ) : theme === 'light' ? (
      <Sun className="h-3.5 w-3.5" />
    ) : (
      <Monitor className="h-3.5 w-3.5" />
    )

  const THEME_CYCLE = ['light', 'dark', 'system'] as const
  const cycleTheme = () => {
    const current = (theme ?? 'system') as (typeof THEME_CYCLE)[number]
    const idx = THEME_CYCLE.indexOf(current)
    setTheme(THEME_CYCLE[(idx + 1) % THEME_CYCLE.length]!)
  }

  const cycleLanguage = () => {
    const codes = supportedLanguages.map((l) => l.code) as string[]
    const idx = codes.indexOf(i18n.language)
    void i18n.changeLanguage(codes[(idx + 1) % codes.length]!)
  }

  return (
    <div className="flex min-h-screen items-center justify-center">
      <div className="w-full max-w-sm space-y-3">
        <Card>
          <CardHeader className="text-center">
            <CardTitle className="text-2xl">{t('auth.pageTitle')}</CardTitle>
            <CardDescription>{t('auth.pageSubtitle')}</CardDescription>
          </CardHeader>
          <CardContent>
            <form onSubmit={handleSubmit(onSubmit)} className="space-y-4">
              {serverError && (
                <Alert variant="destructive">
                  <AlertCircle className="h-4 w-4" />
                  <AlertDescription>{serverError}</AlertDescription>
                </Alert>
              )}

              <div className="space-y-2">
                <Label htmlFor="email">{t('auth.emailLabel')}</Label>
                <Input
                  id="email"
                  type="email"
                  autoComplete="email"
                  {...register('email')}
                />
                {errors.email && (
                  <p className="text-destructive text-sm">{errors.email.message}</p>
                )}
              </div>

              <div className="space-y-2">
                <Label htmlFor="password">{t('auth.passwordLabel')}</Label>
                <Input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  {...register('password')}
                />
                {errors.password && (
                  <p className="text-destructive text-sm">{errors.password.message}</p>
                )}
              </div>

              <Button type="submit" disabled={isSubmitting} className="w-full">
                {isSubmitting ? t('auth.loggingIn') : t('auth.loginButton')}
              </Button>
            </form>
          </CardContent>
        </Card>

        <div className="flex items-center justify-center gap-1">
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground h-7 gap-1.5 px-2 text-xs"
            onClick={cycleLanguage}
          >
            {i18n.language.toUpperCase()}
          </Button>
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground h-7 gap-1.5 px-2 text-xs"
            onClick={cycleTheme}
          >
            {themeIcon}
          </Button>
        </div>
      </div>
    </div>
  )
}
