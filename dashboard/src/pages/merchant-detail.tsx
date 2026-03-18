import { useTranslation } from 'react-i18next'
import { useParams, Link } from '@tanstack/react-router'
import { ArrowLeft, Store, Key, Plug, Route, LayoutDashboard } from 'lucide-react'

import { useMerchantDetail } from '@/hooks/use-organizations'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { ErrorState } from '@/components/shared/error-state'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Button } from '@/components/ui/button'

// ─── Loading Skeleton ───────────────────────────────────────

function MerchantDetailSkeleton() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Skeleton className="h-8 w-8" />
        <Skeleton className="h-8 w-64" />
      </div>
      <Skeleton className="h-32" />
      <Skeleton className="h-64" />
    </div>
  )
}

// ─── Page Component ─────────────────────────────────────────

export function MerchantDetailPage() {
  const { t } = useTranslation()
  const { merchantKey } = useParams({ strict: false }) as { merchantKey: string }
  const { data, isLoading, isError, error, refetch } = useMerchantDetail(merchantKey)

  if (isLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/merchants"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('merchantDetail.backToMerchants')}
        </Link>
        <MerchantDetailSkeleton />
      </div>
    )
  }

  if (isError) {
    return (
      <div className="space-y-6">
        <Link
          to="/merchants"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('merchantDetail.backToMerchants')}
        </Link>
        <ErrorState
          status={(error as { status?: number })?.status ?? 500}
          onRetry={() => void refetch()}
        />
      </div>
    )
  }

  if (!data) return null

  const merchant = data

  return (
    <div className="space-y-6">
      <Link
        to="/merchants"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('merchantDetail.backToMerchants')}
      </Link>

      <div className="flex items-center gap-3">
        <Store className="text-muted-foreground h-7 w-7" />
        <div>
          <h1 className="text-2xl font-bold">{merchant.name}</h1>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground font-mono text-sm">{merchant.id}</span>
            <CopyButton value={merchant.id} />
          </div>
        </div>
      </div>

      <Tabs defaultValue="overview">
        <TabsList>
          <TabsTrigger value="overview" className="gap-1.5">
            <LayoutDashboard className="h-4 w-4" />
            {t('merchantDetail.tabOverview')}
          </TabsTrigger>
          <TabsTrigger value="profiles" className="gap-1.5">
            <Store className="h-4 w-4" />
            {t('merchantDetail.tabProfiles')}
          </TabsTrigger>
          <TabsTrigger value="keys" className="gap-1.5">
            <Key className="h-4 w-4" />
            {t('merchantDetail.tabKeys')}
          </TabsTrigger>
          <TabsTrigger value="connectors" className="gap-1.5">
            <Plug className="h-4 w-4" />
            {t('merchantDetail.tabConnectors')}
          </TabsTrigger>
          <TabsTrigger value="routing" className="gap-1.5">
            <Route className="h-4 w-4" />
            {t('merchantDetail.tabRouting')}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-6">
          <div className="grid gap-6 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>{t('merchantDetail.cardInfo')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <InfoRow label={t('merchantDetail.labelName')}>{merchant.name}</InfoRow>
                <InfoRow label={t('merchantDetail.labelPublishableKey')}>
                  <div className="flex items-center gap-1">
                    <span className="font-mono text-xs">{merchant.publishable_key}</span>
                    <CopyButton value={merchant.publishable_key} />
                  </div>
                </InfoRow>
                <InfoRow label={t('merchantDetail.labelOrganization')}>
                  <span className="font-mono text-xs">{merchant.organization_id}</span>
                </InfoRow>
                <InfoRow label={t('merchantDetail.labelCreated')}>
                  <DateFormat date={merchant.created_at} />
                </InfoRow>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('merchantDetail.cardStats')}</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <InfoRow label={t('merchantDetail.labelProfilesCount')}>{merchant.profiles_count}</InfoRow>
                <InfoRow label={t('merchantDetail.labelConnectorsCount')}>{merchant.connectors_count}</InfoRow>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="profiles" className="mt-6">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>{t('merchantDetail.profilesSection')}</CardTitle>
              <Button asChild size="sm" variant="outline">
                <Link to="/profiles">{t('merchantDetail.profilesLink')}</Link>
              </Button>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground text-sm">
                {t('merchantDetail.profilesDesc')}
              </p>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="keys" className="mt-6">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>{t('merchantDetail.keysSection')}</CardTitle>
              <Button asChild size="sm" variant="outline">
                <Link to="/api-keys">{t('merchantDetail.keysLink')}</Link>
              </Button>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground text-sm">
                {t('merchantDetail.keysDesc')}
              </p>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="connectors" className="mt-6">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>{t('merchantDetail.connectorsSection')}</CardTitle>
              <Button asChild size="sm" variant="outline">
                <Link to="/connectors">{t('merchantDetail.connectorsLink')}</Link>
              </Button>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground text-sm">
                {t('merchantDetail.connectorsDesc')}
              </p>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="routing" className="mt-6">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>{t('merchantDetail.routingSection')}</CardTitle>
              <Button asChild size="sm" variant="outline">
                <Link to="/routing">{t('merchantDetail.routingLink')}</Link>
              </Button>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground text-sm">
                {t('merchantDetail.routingDesc')}
              </p>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
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
