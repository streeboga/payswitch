import { AlertTriangle, FileQuestion, ShieldAlert } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'

interface ErrorConfig {
  icon: typeof AlertTriangle
  titleKey: string
  descriptionKey: string
}

const ERROR_CONFIG: Record<number, ErrorConfig> = {
  401: {
    icon: ShieldAlert,
    titleKey: 'errors.sessionExpired',
    descriptionKey: 'errors.sessionExpiredDesc',
  },
  404: {
    icon: FileQuestion,
    titleKey: 'errors.notFound',
    descriptionKey: 'errors.notFoundDesc',
  },
  500: {
    icon: AlertTriangle,
    titleKey: 'errors.somethingWrong',
    descriptionKey: 'errors.unexpectedError',
  },
}

const DEFAULT_CONFIG: ErrorConfig = {
  icon: AlertTriangle,
  titleKey: 'errors.somethingWrong',
  descriptionKey: 'errors.unexpectedError',
}

interface ErrorStateProps {
  status: number
  message?: string
  onRetry?: () => void
  className?: string
}

export function ErrorState({ status, message, onRetry, className }: ErrorStateProps) {
  const { t } = useTranslation()
  const config = ERROR_CONFIG[status] ?? DEFAULT_CONFIG
  const Icon = config.icon

  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center py-12 text-center',
        className,
      )}
    >
      <Icon className="text-muted-foreground mb-4 h-12 w-12" />
      <h3 className="text-foreground text-lg font-semibold">{t(config.titleKey)}</h3>
      <p className="text-muted-foreground mt-1 max-w-sm text-sm">
        {message ?? t(config.descriptionKey)}
      </p>
      {status === 401 ? (
        <Button asChild className="mt-4">
          <a href="/login">{t('errors.signIn')}</a>
        </Button>
      ) : (
        onRetry && (
          <Button type="button" onClick={onRetry} className="mt-4">
            {t('common.retry')}
          </Button>
        )
      )}
    </div>
  )
}
