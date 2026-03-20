<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Test PSP — Payment Confirmation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); padding: 40px; max-width: 400px; width: 100%; text-align: center; }
        .logo { font-size: 14px; color: #999; margin-bottom: 24px; letter-spacing: 2px; text-transform: uppercase; }
        .amount { font-size: 36px; font-weight: 700; margin-bottom: 4px; }
        .currency { font-size: 14px; color: #666; margin-bottom: 24px; }
        .payment-id { font-size: 12px; color: #999; margin-bottom: 32px; word-break: break-all; }
        .divider { height: 1px; background: #e0e0e0; margin: 0 -40px 32px; }
        .buttons { display: flex; gap: 12px; }
        .btn { flex: 1; padding: 14px; font-size: 16px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; transition: opacity 0.15s; }
        .btn:hover { opacity: 0.85; }
        .btn-approve { background: #22c55e; color: #fff; }
        .btn-decline { background: #ef4444; color: #fff; }
        .hint { font-size: 12px; color: #999; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">Test PSP Simulator</div>
        <div class="amount">{{ $amount }} {{ $currency }}</div>
        <div class="currency">Payment Amount</div>
        <div class="payment-id">{{ $payment->key }}</div>
        <div class="divider"></div>
        <form method="POST" action="{{ url('/test-psp/' . $payment->key . '/complete') }}">
            @csrf
            <div class="buttons">
                <button type="submit" name="action" value="approve" class="btn btn-approve">
                    ✓ Approve
                </button>
                <button type="submit" name="action" value="decline" class="btn btn-decline">
                    ✗ Decline
                </button>
            </div>
        </form>
        <div class="hint">This is a test payment simulator. No real charges will be made.</div>
    </div>
</body>
</html>
