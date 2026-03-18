import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useContextStore } from '@/stores/context'
import { usePreferencesStore } from '@/stores/preferences'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

export function TestLiveToggle() {
  const { t } = useTranslation()
  const testMode = useContextStore((s) => s.testMode)
  const setTestMode = useContextStore((s) => s.setTestMode)
  const sidebarCollapsed = usePreferencesStore((s) => s.sidebarCollapsed)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const handleToggle = (checked: boolean) => {
    if (checked) {
      // Switching to Live — require confirmation
      setConfirmOpen(true)
    } else {
      // Switching back to Test — immediate
      setTestMode(true)
    }
  }

  const handleConfirmLive = () => {
    setTestMode(false)
    setConfirmOpen(false)
  }

  const handleCancelLive = () => {
    setConfirmOpen(false)
  }

  if (sidebarCollapsed) {
    return (
      <>
        <Tooltip>
          <TooltipTrigger asChild>
            <div
              className="mx-auto flex items-center justify-center py-2"
              data-testid="test-live-indicator"
            >
              <span
                className={`block size-2.5 rounded-full ${
                  testMode ? 'bg-purple-500' : 'bg-red-500'
                }`}
              />
            </div>
          </TooltipTrigger>
          <TooltipContent side="right" sideOffset={8}>
            {testMode ? t('testLiveToggle.testMode') : t('testLiveToggle.liveMode')}
          </TooltipContent>
        </Tooltip>
        <ConfirmDialog
          open={confirmOpen}
          onConfirm={handleConfirmLive}
          onCancel={handleCancelLive}
          title={t('testLiveToggle.confirmTitle')}
          description={t('testLiveToggle.confirmDesc')}
          confirmLabel={t('testLiveToggle.confirmButton')}
          cancelLabel={t('common.cancel')}
          destructive
        />
      </>
    )
  }

  return (
    <>
      <div
        className="flex items-center justify-between px-3 py-2"
        data-testid="test-live-toggle"
      >
        <div className="flex items-center gap-2">
          {testMode ? (
            <Badge
              data-testid="test-badge"
              className="bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400"
            >
              Test
            </Badge>
          ) : (
            <Badge data-testid="live-badge" variant="destructive">
              Live
            </Badge>
          )}
        </div>
        <Switch
          checked={!testMode}
          onCheckedChange={handleToggle}
          size="sm"
          aria-label={
            testMode ? t('testLiveToggle.switchToLive') : t('testLiveToggle.switchToTest')
          }
          className={!testMode ? 'data-[state=checked]:bg-red-500' : ''}
        />
      </div>
      <ConfirmDialog
        open={confirmOpen}
        onConfirm={handleConfirmLive}
        onCancel={handleCancelLive}
        title={t('testLiveToggle.confirmTitle')}
        description={t('testLiveToggle.confirmDesc')}
        confirmLabel={t('testLiveToggle.confirmButton')}
        cancelLabel={t('common.cancel')}
        destructive
      />
    </>
  )
}
