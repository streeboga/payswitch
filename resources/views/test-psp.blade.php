<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test PSP — {{ $step === 'result' ? ($success ? 'Payment Approved' : 'Payment Declined') : 'Payment Confirmation' }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); padding: 40px; max-width: 420px; width: 100%; text-align: center; }
        .logo { font-size: 12px; color: #999; margin-bottom: 24px; letter-spacing: 2px; text-transform: uppercase; }
        .amount { font-size: 36px; font-weight: 700; margin-bottom: 4px; }
        .currency { font-size: 14px; color: #666; margin-bottom: 8px; }
        .payment-id { font-size: 11px; color: #bbb; margin-bottom: 32px; font-family: monospace; word-break: break-all; }
        .divider { height: 1px; background: #e0e0e0; margin: 0 -40px 32px; }
        .buttons { display: flex; gap: 12px; }
        .btn { flex: 1; padding: 14px; font-size: 16px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: opacity 0.15s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn:hover { opacity: 0.85; }
        .btn-approve { background: #22c55e; color: #fff; }
        .btn-decline { background: #ef4444; color: #fff; }
        .btn-primary { background: #0066ff; color: #fff; }
        .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .hint { font-size: 12px; color: #bbb; margin-top: 24px; }
        .result-icon { font-size: 56px; margin-bottom: 16px; }
        .result-title { font-size: 22px; font-weight: 700; margin-bottom: 8px; }
        .result-subtitle { font-size: 14px; color: #666; margin-bottom: 32px; }
        .result-success .result-title { color: #166534; }
        .result-fail .result-title { color: #991b1b; }
        .links { display: flex; flex-direction: column; gap: 10px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Test PSP Simulator</div>

        @if($step === 'checkout')
            <div class="amount">{{ $amount }} {{ $currency }}</div>
            <div class="currency">Payment Amount</div>
            <div class="payment-id">{{ $payment->key }}</div>
            <div class="divider"></div>
            <form method="POST" action="{{ url('/test-psp/' . $payment->key . '/complete') }}">
                @csrf
                <div class="buttons">
                    <button type="submit" name="action" value="approve" class="btn btn-approve">
                        Approve
                    </button>
                    <button type="submit" name="action" value="decline" class="btn btn-decline">
                        Decline
                    </button>
                </div>
            </form>
            <div class="hint">This is a test payment simulator. No real charges will be made.</div>
        @else
            <div class="{{ $success ? 'result-success' : 'result-fail' }}">
                <div class="result-icon">{{ $success ? '✅' : '❌' }}</div>
                <div class="result-title">{{ $success ? 'Payment Approved' : 'Payment Declined' }}</div>
                <div class="result-subtitle">
                    {{ $amount }} {{ $currency }}
                    <br>
                    <span style="font-size: 11px; color: #bbb; font-family: monospace;">{{ $payment->key }}</span>
                </div>
            </div>
            <div class="links">
                @if($returnUrl)
                    <a href="{{ $returnUrl }}" class="btn btn-primary">Return to Store</a>
                @endif
                <a href="{{ $dashboardUrl }}" class="btn btn-secondary">View in Dashboard</a>
            </div>
            <div class="hint">{{ $success ? 'The payment has been marked as succeeded.' : 'The payment has been marked as failed.' }}</div>
        @endif
    </div>
</body>
</html>
