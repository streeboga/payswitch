import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useContextStore } from '@/stores/context'
import { ConfirmDialog } from '@/components/shared/confirm-dialog'
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip'

export function TestLiveToggle() {
  const { t } = useTranslation()
  const testMode = useContextStore((s) => s.testMode)
  const setTestMode = useContextStore((s) => s.setTestMode)
  const [confirmOpen, setConfirmOpen] = useState(false)

  const handleClick = () => {
    if (testMode) {
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

  return (
    <>
      <Tooltip>
        <TooltipTrigger asChild>
          <button
            className="hover:bg-accent flex items-center gap-1.5 rounded-md px-1.5 py-1 transition-colors"
            onClick={handleClick}
            data-testid="test-live-toggle"
            aria-label={
              testMode ? t('testLiveToggle.switchToLive') : t('testLiveToggle.switchToTest')
            }
          >
            <span
              className={`block size-2 rounded-full ${
                testMode ? 'bg-purple-500' : 'bg-red-500'
              }`}
            />
            <span
              className={`text-[10px] font-semibold uppercase ${
                testMode
                  ? 'text-purple-600 dark:text-purple-400'
                  : 'text-red-600 dark:text-red-400'
              }`}
            >
              {testMode ? 'Test' : 'Live'}
            </span>
          </button>
        </TooltipTrigger>
        <TooltipContent side="bottom" sideOffset={4}>
          {testMode ? t('testLiveToggle.switchToLive') : t('testLiveToggle.switchToTest')}
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
