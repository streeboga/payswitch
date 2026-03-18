<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>{{ $config->get('ui.title') ?? config('app.name') . ' - API Docs' }}</title>
    <script src="https://cdn.jsdelivr.net/npm/@scalar/api-reference"></script>
</head>
<body>
<div id="app"></div>
<script>
    const spec = @json($spec);

    Scalar.createApiReference(document.getElementById('app'), {
        spec: { content: spec },
        theme: '{{ $config->get('ui.theme', 'light') === 'dark' ? 'deepSpace' : 'default' }}',
        @if($config->get('ui.hide_try_it'))
        hiddenClients: true,
        @endif
    })
</script>
</body>
</html>
