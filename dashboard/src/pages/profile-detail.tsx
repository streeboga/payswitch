import { useTranslation } from 'react-i18next'
import { useParams, Link } from '@tanstack/react-router'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { ArrowLeft, FolderOpen, KeyRound } from 'lucide-react'

import { useProfileDetail, useUpdateProfile } from '@/hooks/use-profiles'
import { PaymentWidgetPreview } from '@/components/shared/payment-widget-preview'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { ErrorState } from '@/components/shared/error-state'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Button } from '@/components/ui/button'
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form'
import { Input } from '@/components/ui/input'

// ─── Loading Skeleton ───────────────────────────────────────

function ProfileDetailSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-8 w-8" />
        <Skeleton className="h-8 w-64" />
      </div>
      <Skeleton className="h-48" />
      <Skeleton className="h-32" />
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function ProfileDetailPage() {
  const { t } = useTranslation()
  const { profileKey } = useParams({ strict: false }) as { profileKey: string }
  const { data, isLoading, isError, error, refetch } = useProfileDetail(profileKey)
  const updateMutation = useUpdateProfile()

  const editProfileSchema = z.object({
    webhook_url: z
      .string()
      .url(t('profiles.webhookUrlInvalid'))
      .or(z.literal(''))
      .optional(),
  })

  type EditProfileForm = z.infer<typeof editProfileSchema>

  const form = useForm<EditProfileForm>({
    resolver: zodResolver(editProfileSchema),
    values: {
      webhook_url: data?.webhook_url ?? '',
    },
  })

  function onSubmit(values: EditProfileForm) {
    updateMutation.mutate({
      key: profileKey,
      data: { webhook_url: values.webhook_url || undefined },
    })
  }

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/profiles"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('profileDetail.backToProfiles')}
        </Link>
        <ProfileDetailSkeleton />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <Link
          to="/profiles"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('profileDetail.backToProfiles')}
        </Link>
        <ErrorState
          status={(error as { status?: number })?.status ?? 500}
          onRetry={() => void refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const profile = data

  return (
    <div className="space-y-6">
      <Link
        to="/profiles"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('profileDetail.backToProfiles')}
      </Link>

      <div className="flex items-center gap-3">
        <FolderOpen className="text-muted-foreground h-7 w-7" />
        <div>
          <h1 className="text-2xl font-bold">{t('profileDetail.pageTitle')}</h1>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground font-mono text-sm">{profile.id}</span>
            <CopyButton value={profile.id} />
          </div>
        </div>
      </div>

      <div className="grid gap-6 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>{t('profileDetail.cardInfo')}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <InfoRow label={t('profileDetail.labelMerchant')}>
              <span className="font-mono text-xs">{profile.merchant_id}</span>
            </InfoRow>
            <InfoRow label={t('profileDetail.labelConnectors')}>
              {profile.connectors_count}
            </InfoRow>
            <InfoRow label={t('profileDetail.labelRoutingRules')}>
              {profile.routing_rules_count}
            </InfoRow>
            <InfoRow label={t('profileDetail.labelCreated')}>
              <DateFormat date={profile.created_at} />
            </InfoRow>
          </CardContent>
        </Card>

        {profile.payment_response_hash_key && (
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <KeyRound className="h-4 w-4" />
                {t('profileDetail.hashKeyTitle')}
              </CardTitle>
              <CardDescription>{t('profileDetail.hashKeyDesc')}</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="flex items-center gap-2">
                <code className="bg-muted flex-1 rounded px-3 py-2 font-mono text-sm">
                  {profile.payment_response_hash_key}
                </code>
                <CopyButton value={profile.payment_response_hash_key} />
              </div>
            </CardContent>
          </Card>
        )}

        <Card>
          <CardHeader>
            <CardTitle>{t('profileDetail.cardWebhook')}</CardTitle>
          </CardHeader>
          <CardContent>
            <Form {...form}>
              <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                <FormField
                  control={form.control}
                  name="webhook_url"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t('profileDetail.urlLabel')}</FormLabel>
                      <FormControl>
                        <Input
                          placeholder={t('profileDetail.urlPlaceholder')}
                          {...field}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <Button type="submit" size="sm" disabled={updateMutation.isPending}>
                  {updateMutation.isPending ? t('common.saving') : t('common.save')}
                </Button>
              </form>
            </Form>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>{t('profileDetail.connectorsSection')}</CardTitle>
          <Button asChild size="sm" variant="outline">
            <Link to="/connectors">{t('profileDetail.connectorsLink')}</Link>
          </Button>
        </CardHeader>
        <CardContent>
          <p className="text-muted-foreground text-sm">
            {t('profileDetail.connectorsDesc')}
          </p>
        </CardContent>
      </Card>

      {/* Widget Preview — all available payment methods for this profile */}
      <PaymentWidgetPreview
        title={t('profileDetail.widgetPreview', 'Payment Widget')}
        description={t(
          'profileDetail.widgetPreviewDesc',
          'Preview the payment widget with all connectors for this profile',
        )}
      />

      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <CardTitle>{t('profileDetail.routingSection')}</CardTitle>
          <Button asChild size="sm" variant="outline">
            <Link to="/routing">{t('profileDetail.routingLink')}</Link>
          </Button>
        </CardHeader>
        <CardContent>
          <p className="text-muted-foreground text-sm">
            {t('profileDetail.routingDesc')}
          </p>
        </CardContent>
      </Card>
    </div>
  )
}

function InfoRow({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-baseline justify-between gap-4">
      <span className="text-muted-foreground text-sm">{label}</span>
      <div className="text-right text-sm">{children}</div>
    </div>
  )
}
