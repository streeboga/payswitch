@php
    $lang = request()->query('lang', 'ru');
    $langFile = resource_path("lang/test-psp/{$lang}.json");
    if (!file_exists($langFile)) {
        $langFile = resource_path('lang/test-psp/en.json');
        $lang = 'en';
    }
    $s = json_decode(file_get_contents($langFile), true);

    // Available languages — add new file to resources/lang/test-psp/{code}.json to extend
    $availableLangs = collect(glob(resource_path('lang/test-psp/*.json')))
        ->map(fn ($f) => pathinfo($f, PATHINFO_FILENAME))
        ->values()
        ->all();
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $s['logo'] }} — {{ $step === 'result' ? ($success ? $s['title_success'] : $s['title_fail']) : $s['title_checkout'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.1); padding: 40px; max-width: 420px; width: 100%; text-align: center; position: relative; }
        .lang-switch { position: absolute; top: 16px; right: 16px; display: flex; gap: 4px; }
        .lang-btn { font-size: 12px; color: #999; text-decoration: none; border: 1px solid #e0e0e0; border-radius: 4px; padding: 3px 8px; text-transform: uppercase; }
        .lang-btn:hover { background: #f3f4f6; color: #333; }
        .lang-btn.active { background: #0066ff; color: #fff; border-color: #0066ff; }
        .logo { font-size: 12px; color: #999; margin-bottom: 24px; letter-spacing: 2px; text-transform: uppercase; }
        .amount { font-size: 36px; font-weight: 700; margin-bottom: 4px; }
        .meta { font-size: 14px; color: #666; margin-bottom: 8px; }
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
        <div class="lang-switch">
            @foreach($availableLangs as $code)
                <a href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}" class="lang-btn {{ $lang === $code ? 'active' : '' }}">{{ $code }}</a>
            @endforeach
        </div>
        <div class="logo">{{ $s['logo'] }}</div>

        @if($step === 'checkout')
            <div class="amount">{{ $amount }} {{ $currency }}</div>
            <div class="meta">{{ $s['amount_label'] }}</div>
            <div class="payment-id">{{ $payment->key }}</div>
            <div class="divider"></div>
            <form method="POST" action="{{ url('/test-psp/' . $payment->key . '/complete') }}?lang={{ $lang }}">
                @csrf
                <div class="buttons">
                    <button type="submit" name="action" value="approve" class="btn btn-approve">{{ $s['approve'] }}</button>
                    <button type="submit" name="action" value="decline" class="btn btn-decline">{{ $s['decline'] }}</button>
                </div>
            </form>
            <div class="hint">{{ $s['hint_checkout'] }}</div>
        @else
            <div class="{{ $success ? 'result-success' : 'result-fail' }}">
                <div class="result-icon">{{ $success ? '✅' : '❌' }}</div>
                <div class="result-title">{{ $success ? $s['title_success'] : $s['title_fail'] }}</div>
                <div class="result-subtitle">
                    {{ $amount }} {{ $currency }}<br>
                    <span style="font-size: 11px; color: #bbb; font-family: monospace;">{{ $payment->key }}</span>
                </div>
            </div>
            <div class="links">
                @if($returnUrl)
                    <a href="{{ $returnUrl }}" class="btn btn-primary">{{ $s['return_store'] }}</a>
                @endif
                <a href="{{ $dashboardUrl }}" class="btn btn-secondary">{{ $s['view_dashboard'] }}</a>
            </div>
            <div class="hint">{{ $success ? $s['hint_success'] : $s['hint_fail'] }}</div>
        @endif
    </div>
</body>
</html>
