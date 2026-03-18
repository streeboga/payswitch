import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link } from '@tanstack/react-router'
import { useReactTable, getCoreRowModel, type ColumnDef } from '@tanstack/react-table'
import { ArrowLeft, Building2 } from 'lucide-react'

import type { MerchantAccountAttributes } from '@/api/types'
import {
  useOrganizationDetail,
  useOrganizationMerchants,
} from '@/hooks/use-organizations'
import { DataTable } from '@/components/data-table'
import { DateFormat } from '@/components/shared/date-format'
import { CopyButton } from '@/components/shared/copy-button'
import { ErrorState } from '@/components/shared/error-state'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { usePreferencesStore } from '@/stores/preferences'

// ─── Row Type ────────────────────────────────────────────────

type MerchantRow = MerchantAccountAttributes & { id: string }

// ─── Loading Skeleton ───────────────────────────────────────

function OrgDetailSkeleton() {
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

export function OrganizationDetailPage() {
  const { t } = useTranslation()
  const { orgKey } = useParams({ strict: false }) as { orgKey: string }
  const density = usePreferencesStore((s) => s.density)

  const {
    data: orgData,
    isLoading: orgLoading,
    isError: orgError,
    error: orgErr,
    refetch: orgRefetch,
  } = useOrganizationDetail(orgKey)

  const { data: merchantsData, isLoading: merchantsLoading } =
    useOrganizationMerchants(orgKey)

  // ─── Columns ────────────────────────────────────────────────

  const merchantColumns: ColumnDef<MerchantRow, unknown>[] = [
    {
      accessorKey: 'id',
      header: t('common.id'),
      size: 220,
      cell: ({ row }) => {
        const id = row.original.id
        return (
          <div className="flex items-center gap-1">
            <Link
              to="/merchants/$merchantKey"
              params={{ merchantKey: id }}
              className="text-primary font-mono text-sm hover:underline"
            >
              {id}
            </Link>
            <CopyButton value={id} />
          </div>
        )
      },
      enableSorting: false,
    },
    {
      accessorKey: 'name',
      header: t('organizationDetail.columnName'),
      cell: ({ row }) => (
        <Link
          to="/merchants/$merchantKey"
          params={{ merchantKey: row.original.id }}
          className="text-primary hover:underline"
        >
          {row.original.name}
        </Link>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'publishable_key',
      header: t('organizationDetail.columnPublishableKey'),
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <span className="font-mono text-xs">{row.original.publishable_key}</span>
          <CopyButton value={row.original.publishable_key} />
        </div>
      ),
      enableSorting: false,
    },
    {
      accessorKey: 'created_at',
      header: t('organizationDetail.columnDate'),
      cell: ({ row }) => <DateFormat date={row.original.created_at} />,
      enableSorting: false,
    },
  ]

  const merchantRows = useMemo<MerchantRow[]>(() => {
    return merchantsData?.items ?? []
  }, [merchantsData])

  const table = useReactTable({
    data: merchantRows,
    columns: merchantColumns,
    getCoreRowModel: getCoreRowModel(),
  })

  if (orgLoading) {
    return (
      <div className="space-y-6">
        <Link
          to="/organizations"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('organizationDetail.backToOrganizations')}
        </Link>
        <OrgDetailSkeleton />
      </div>
    )
  }

  if (orgError) {
    return (
      <div className="space-y-6">
        <Link
          to="/organizations"
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('organizationDetail.backToOrganizations')}
        </Link>
        <ErrorState
          status={(orgErr as { status?: number })?.status ?? 500}
          onRetry={() => void orgRefetch()}
        />
      </div>
    )
  }

  if (!orgData) return null

  const org = orgData

  return (
    <div className="space-y-6">
      <Link
        to="/organizations"
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm"
      >
        <ArrowLeft className="h-4 w-4" />
        {t('organizationDetail.backToOrganizations')}
      </Link>

      <div className="flex items-center gap-3">
        <Building2 className="text-muted-foreground h-7 w-7" />
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-2xl font-bold">{org.name}</h1>
          </div>
          <div className="flex items-center gap-2">
            <span className="text-muted-foreground font-mono text-sm">{org.id}</span>
            <CopyButton value={org.id} />
          </div>
        </div>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>{t('organizationDetail.cardInfo')}</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <InfoRow label={t('organizationDetail.labelName')}>{org.name}</InfoRow>
          <InfoRow label={t('organizationDetail.labelCreated')}>
            <DateFormat date={org.created_at} />
          </InfoRow>
        </CardContent>
      </Card>

      <div>
        <h2 className="mb-4 text-xl font-semibold">{t('organizationDetail.merchantsTitle', { count: merchantRows.length })}</h2>
        <DataTable
          table={table}
          columns={merchantColumns}
          isLoading={merchantsLoading}
          isError={false}
          onRetry={() => {}}
          emptyTitle={t('organizationDetail.emptyMerchants')}
          emptyDescription={t('organizationDetail.emptyMerchantsDesc')}
          density={density}
        />
      </div>
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
