import { useState } from 'react'
import { Download, Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui/button'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

interface TableExportProps {
  onExport: () => Promise<Blob>
  filename?: string
  disabled?: boolean
}

export function TableExport({
  onExport,
  filename = 'export.csv',
  disabled = false,
}: TableExportProps) {
  const { t } = useTranslation()
  const [exporting, setExporting] = useState(false)

  const handleExport = async () => {
    setExporting(true)
    try {
      const blob = await onExport()
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = filename
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } finally {
      setExporting(false)
    }
  }

  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          onClick={handleExport}
          disabled={disabled || exporting}
        >
          {exporting ? (
            <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
          ) : (
            <Download className="mr-1.5 h-3.5 w-3.5" />
          )}
          {t('table.exportButton')}
        </Button>
      </TooltipTrigger>
      <TooltipContent>{t('table.exportTooltip')}</TooltipContent>
    </Tooltip>
  )
}
