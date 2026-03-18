import { useQuery, useMutation } from '@tanstack/react-query'
import {
  dashboardAuditLog,
  type AuditLogListParams,
} from '@/api/endpoints/dashboard-audit-log'
import { useContextStore } from '@/stores/context'

const STALE_TIME = 30_000

export function useAuditLogList(params: AuditLogListParams = {}) {
  const merchantKey = useContextStore((s) => s.currentMerchantKey)

  return useQuery({
    queryKey: ['audit-log', 'list', params, merchantKey],
    queryFn: () => dashboardAuditLog.list(params),
    staleTime: STALE_TIME,
    enabled: !!merchantKey,
  })
}

export function useExportAuditLogCsv() {
  return useMutation({
    mutationFn: (params: AuditLogListParams) => dashboardAuditLog.exportCsv(params),
    onSuccess: (blob) => {
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`
      a.click()
      URL.revokeObjectURL(url)
    },
  })
}
