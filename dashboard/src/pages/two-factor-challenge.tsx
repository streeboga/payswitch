import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { useNavigate } from '@tanstack/react-router'
import { AlertCircle } from 'lucide-react'
import { auth } from '@/api/endpoints/auth'
import { useAuthStore } from '@/stores/auth'
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

type OtpFormData = { code: string }
type RecoveryFormData = { recovery_code: string }

export function TwoFactorChallengePage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const setUser = useAuthStore((s) => s.setUser)
  const setRequiresTwoFactor = useAuthStore((s) => s.setRequiresTwoFactor)
  const [useRecovery, setUseRecovery] = useState(false)
  const [serverError, setServerError] = useState<string | null>(null)

  const otpSchema = z.object({
    code: z.string().length(6, t('auth.codeError')),
  })

  const recoverySchema = z.object({
    recovery_code: z.string().min(1, t('auth.recoveryCodeError')),
  })

  const otpForm = useForm<OtpFormData>({
    resolver: zodResolver(otpSchema),
  })

  const recoveryForm = useForm<RecoveryFormData>({
    resolver: zodResolver(recoverySchema),
  })

  const handleChallenge = async (data: { code?: string; recovery_code?: string }) => {
    setServerError(null)
    try {
      const user = await auth.twoFactorChallenge(data)
      setRequiresTwoFactor(false)
      setUser(user)
      await navigate({ to: '/overview' })
    } catch (error: unknown) {
      if (error && typeof error === 'object' && 'response' in error) {
        const resp = (error as { response: Response }).response
        if (resp.status === 422) {
          const body = await resp.json()
          const message =
            body?.errors?.code?.[0] ??
            body?.errors?.recovery_code?.[0] ??
            body?.message ??
            t('auth.invalidCode')
          setServerError(message)
          return
        }
      }
      setServerError(t('errors.serverError'))
    }
  }

  const onOtpSubmit = (data: OtpFormData) => handleChallenge({ code: data.code })
  const onRecoverySubmit = (data: RecoveryFormData) =>
    handleChallenge({ recovery_code: data.recovery_code })

  const isSubmitting =
    otpForm.formState.isSubmitting || recoveryForm.formState.isSubmitting

  return (
    <div className="flex min-h-screen items-center justify-center">
      <Card className="w-full max-w-sm">
        <CardHeader className="text-center">
          <CardTitle className="text-2xl">{t('auth.twoFactorTitle')}</CardTitle>
          <CardDescription>
            {useRecovery
              ? t('auth.twoFactorRecoveryDesc')
              : t('auth.twoFactorOtpDesc')}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          {serverError && (
            <Alert variant="destructive">
              <AlertCircle className="h-4 w-4" />
              <AlertDescription>{serverError}</AlertDescription>
            </Alert>
          )}

          {!useRecovery ? (
            <form onSubmit={otpForm.handleSubmit(onOtpSubmit)} className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="code">{t('auth.codeLabel')}</Label>
                <Input
                  id="code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={6}
                  className="text-center text-lg tracking-widest"
                  {...otpForm.register('code')}
                />
                {otpForm.formState.errors.code && (
                  <p className="text-destructive text-sm">
                    {otpForm.formState.errors.code.message}
                  </p>
                )}
              </div>

              <Button type="submit" disabled={isSubmitting} className="w-full">
                {isSubmitting ? t('auth.verifying') : t('auth.verifyButton')}
              </Button>
            </form>
          ) : (
            <form
              onSubmit={recoveryForm.handleSubmit(onRecoverySubmit)}
              className="space-y-4"
            >
              <div className="space-y-2">
                <Label htmlFor="recovery_code">{t('auth.recoveryCodeLabel')}</Label>
                <Input
                  id="recovery_code"
                  type="text"
                  {...recoveryForm.register('recovery_code')}
                />
                {recoveryForm.formState.errors.recovery_code && (
                  <p className="text-destructive text-sm">
                    {recoveryForm.formState.errors.recovery_code.message}
                  </p>
                )}
              </div>

              <Button type="submit" disabled={isSubmitting} className="w-full">
                {isSubmitting ? t('auth.verifying') : t('auth.verifyButton')}
              </Button>
            </form>
          )}

          <Button
            type="button"
            variant="ghost"
            onClick={() => {
              setUseRecovery(!useRecovery)
              setServerError(null)
            }}
            className="w-full"
          >
            {useRecovery ? t('auth.useAuthApp') : t('auth.useRecoveryCode')}
          </Button>
        </CardContent>
      </Card>
    </div>
  )
}
