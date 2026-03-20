import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { useTranslation } from 'react-i18next'

const API_BASE = import.meta.env.VITE_BACKEND_URL ?? ''

interface TestPspPayment {
  amount: number
  currency: string
  status: string
  description?: string
  return_url?: string
}

interface TestPspResult {
  success: boolean
  return_url?: string
  dashboard_url?: string
}

function formatAmount(amount: number, currency: string, locale: string): string {
  try {
    return new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      minimumFractionDigits: 2,
    }).format(amount / 100)
  } catch {
    return `${(amount / 100).toFixed(2)} ${currency}`
  }
}

export function TestPspPage() {
  const { paymentKey } = useParams({ strict: false }) as { paymentKey: string }
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()

  const [payment, setPayment] = useState<TestPspPayment | null>(null)
  const [result, setResult] = useState<TestPspResult | null>(null)
  const [loading, setLoading] = useState(true)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    fetch(`${API_BASE}/api/v1/test-psp/${paymentKey}`)
      .then((res) => {
        if (!res.ok) throw new Error('not_found')
        return res.json()
      })
      .then((json) => {
        setPayment(json.data.attributes)
        setLoading(false)
      })
      .catch(() => {
        setError(t('testPsp.notFound'))
        setLoading(false)
      })
  }, [paymentKey, t])

  const handleAction = useCallback(
    async (action: 'approve' | 'decline') => {
      setSubmitting(true)
      try {
        const res = await fetch(`${API_BASE}/api/v1/test-psp/${paymentKey}/complete`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action }),
        })
        const json = await res.json()
        setResult({
          success: json.data.attributes.success,
          return_url: json.data.attributes.return_url,
          dashboard_url: json.data.attributes.dashboard_url,
        })
      } catch {
        setError('Failed to process payment')
      } finally {
        setSubmitting(false)
      }
    },
    [paymentKey],
  )

  if (loading) {
    return (
      <div style={styles.page}>
        <div style={styles.card}>
          <div style={styles.center}>{t('testPsp.loading')}</div>
        </div>
      </div>
    )
  }

  if (error) {
    return (
      <div style={styles.page}>
        <div style={styles.card}>
          <div style={styles.error}>{error}</div>
        </div>
      </div>
    )
  }

  // Result state
  if (result) {
    return (
      <div style={styles.page}>
        <div style={styles.card}>
          <div style={styles.logo}>{t('testPsp.logo')}</div>
          <div
            style={{
              ...styles.resultIcon,
              color: result.success ? '#16a34a' : '#dc2626',
            }}
          >
            {result.success ? '✓' : '✗'}
          </div>
          <h1 style={styles.title}>
            {result.success ? t('testPsp.titleSuccess') : t('testPsp.titleFail')}
          </h1>
          {payment && (
            <div style={styles.amount}>
              {formatAmount(payment.amount, payment.currency, i18n.language)}
            </div>
          )}
          <div style={styles.links}>
            {result.return_url && (
              <a href={result.return_url} style={styles.linkPrimary}>
                {t('testPsp.returnStore')}
              </a>
            )}
            {result.dashboard_url && (
              <button
                type="button"
                style={styles.linkSecondary}
                onClick={() => {
                  // SPA navigation — preserves auth session
                  const path = new URL(result.dashboard_url!, window.location.origin).pathname
                  navigate({ to: path })
                }}
              >
                {t('testPsp.viewDashboard')}
              </button>
            )}
          </div>
        </div>
      </div>
    )
  }

  // Checkout state
  return (
    <div style={styles.page}>
      <div style={styles.card}>
        <div style={styles.logo}>{t('testPsp.logo')}</div>
        <h1 style={styles.title}>{t('testPsp.title')}</h1>
        {payment && (
          <>
            <div style={styles.amountLabel}>{t('testPsp.amountLabel')}</div>
            <div style={styles.amount}>
              {formatAmount(payment.amount, payment.currency, i18n.language)}
            </div>
            {payment.description && (
              <div style={styles.description}>{payment.description}</div>
            )}
          </>
        )}
        <div style={styles.actions}>
          <button
            type="button"
            style={styles.approveBtn}
            disabled={submitting}
            onClick={() => handleAction('approve')}
          >
            {t('testPsp.approve')}
          </button>
          <button
            type="button"
            style={styles.declineBtn}
            disabled={submitting}
            onClick={() => handleAction('decline')}
          >
            {t('testPsp.decline')}
          </button>
        </div>
        <div style={styles.hints}>
          <div style={styles.hint}>{t('testPsp.hintApprove')}</div>
          <div style={styles.hint}>{t('testPsp.hintDecline')}</div>
        </div>
      </div>
    </div>
  )
}

const styles: Record<string, React.CSSProperties> = {
  page: {
    minHeight: '100vh',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    background: '#f5f5f5',
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    padding: '16px',
  },
  card: {
    background: '#fff',
    borderRadius: '12px',
    padding: '32px',
    maxWidth: '400px',
    width: '100%',
    boxShadow: '0 4px 24px rgba(0,0,0,0.08)',
    textAlign: 'center',
  },
  logo: {
    fontSize: '14px',
    fontWeight: 600,
    color: '#666',
    marginBottom: '24px',
    textTransform: 'uppercase',
    letterSpacing: '1px',
  },
  title: {
    fontSize: '20px',
    fontWeight: 600,
    margin: '0 0 16px',
    color: '#1a1a1a',
  },
  amountLabel: {
    fontSize: '13px',
    color: '#999',
    marginBottom: '4px',
  },
  amount: {
    fontSize: '28px',
    fontWeight: 700,
    marginBottom: '8px',
    color: '#1a1a1a',
  },
  description: {
    fontSize: '14px',
    color: '#666',
    marginBottom: '24px',
  },
  actions: {
    display: 'flex',
    flexDirection: 'column',
    gap: '12px',
    marginTop: '24px',
  },
  approveBtn: {
    padding: '14px',
    fontSize: '16px',
    fontWeight: 600,
    color: '#fff',
    backgroundColor: '#16a34a',
    border: 'none',
    borderRadius: '8px',
    cursor: 'pointer',
  },
  declineBtn: {
    padding: '14px',
    fontSize: '16px',
    fontWeight: 600,
    color: '#fff',
    backgroundColor: '#dc2626',
    border: 'none',
    borderRadius: '8px',
    cursor: 'pointer',
  },
  hints: {
    display: 'flex',
    justifyContent: 'space-between',
    marginTop: '8px',
    gap: '12px',
  },
  hint: {
    fontSize: '11px',
    color: '#999',
    flex: 1,
  },
  center: {
    padding: '24px',
    color: '#666',
  },
  error: {
    padding: '16px',
    color: '#dc2626',
    backgroundColor: '#fef2f2',
    borderRadius: '8px',
  },
  resultIcon: {
    fontSize: '48px',
    fontWeight: 700,
    marginBottom: '8px',
  },
  links: {
    display: 'flex',
    flexDirection: 'column',
    gap: '12px',
    marginTop: '24px',
  },
  linkPrimary: {
    padding: '12px',
    fontSize: '14px',
    fontWeight: 600,
    color: '#fff',
    backgroundColor: '#0066ff',
    borderRadius: '8px',
    textDecoration: 'none',
    textAlign: 'center',
  },
  linkSecondary: {
    padding: '12px',
    fontSize: '14px',
    fontWeight: 500,
    color: '#0066ff',
    backgroundColor: '#f0f7ff',
    borderRadius: '8px',
    textDecoration: 'none',
    textAlign: 'center',
  },
}
