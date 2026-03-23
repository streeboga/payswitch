import { h } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import type { QrData, WidgetTranslations } from '../types';
import type { PaymentApi } from '../api';

interface QrPaymentProps {
  qrData: QrData;
  api: PaymentApi;
  paymentKey: string;
  clientSecret: string;
  t: WidgetTranslations;
  onSuccess: () => void;
  onError: (message: string) => void;
}

const s = {
  container: {
    textAlign: 'center' as const,
    padding: '16px',
  },
  qrWrapper: {
    display: 'inline-block',
    padding: '16px',
    backgroundColor: '#fff',
    borderRadius: '12px',
    border: '1px solid #e0e0e0',
    marginBottom: '12px',
  },
  hint: {
    fontSize: '14px',
    color: '#666',
    marginBottom: '8px',
  },
  timer: {
    fontSize: '12px',
    color: '#999',
    marginTop: '8px',
  },
  expired: {
    padding: '16px',
    textAlign: 'center' as const,
    color: '#dc2626',
    backgroundColor: '#fef2f2',
    borderRadius: '8px',
  },
};

export function QrPayment({ qrData, api, paymentKey, clientSecret, t, onSuccess, onError }: QrPaymentProps) {
  const [expired, setExpired] = useState(false);
  const [timeLeft, setTimeLeft] = useState<number | null>(null);
  const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);

  useEffect(() => {
    // Start polling for status
    pollRef.current = setInterval(async () => {
      try {
        const result = await api.getPaymentStatus(paymentKey, clientSecret);
        if (result.status === 'succeeded') {
          cleanup();
          onSuccess();
        } else if (result.status === 'failed' || result.status === 'cancelled') {
          cleanup();
          onError(t.qrPaymentFailed);
        }
      } catch {
        // Silently continue polling
      }
    }, 3000);

    // Countdown timer
    if (qrData.expiresAt) {
      const expiresMs = new Date(qrData.expiresAt).getTime();
      const updateTimer = () => {
        const remaining = Math.max(0, Math.floor((expiresMs - Date.now()) / 1000));
        setTimeLeft(remaining);
        if (remaining <= 0) {
          cleanup();
          setExpired(true);
        }
      };
      updateTimer();
      timerRef.current = setInterval(updateTimer, 1000);
    }

    return cleanup;
  }, []);

  function cleanup() {
    if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; }
    if (timerRef.current) { clearInterval(timerRef.current); timerRef.current = null; }
  }

  if (expired) {
    return <div style={s.expired}>{t.qrExpired}</div>;
  }

  const formatTime = (seconds: number): string => {
    const m = Math.floor(seconds / 60);
    const sec = seconds % 60;
    return `${m}:${sec.toString().padStart(2, '0')}`;
  };

  return (
    <div style={s.container}>
      <div style={s.qrWrapper}>
        {renderQr(qrData)}
      </div>
      <div style={s.hint}>{t.scanWithBankApp}</div>
      {timeLeft !== null && timeLeft > 0 && (
        <div style={s.timer}>{formatTime(timeLeft)}</div>
      )}
    </div>
  );
}

function renderQr(qrData: QrData) {
  if (qrData.format === 'svg') {
    return <div dangerouslySetInnerHTML={{ __html: qrData.data }} />;
  }
  if (qrData.format === 'base64_png') {
    return <img src={`data:image/png;base64,${qrData.data}`} alt="QR" width="200" height="200" />;
  }
  // 'payload' — render the raw data as text (merchant can use a QR library if needed)
  return (
    <div style={{ padding: '16px', fontFamily: 'monospace', fontSize: '12px', wordBreak: 'break-all' as const }}>
      {qrData.data}
    </div>
  );
}
