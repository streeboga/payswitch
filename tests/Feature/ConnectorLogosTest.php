<?php

declare(strict_types=1);

it('has a file in public/ for every connector logoPath', function () {
    $paths = collect(glob(base_path('packages/streeboga/payment-connectors/src/Drivers/*Connector.php')))
        ->map(fn ($f) => 'Streeboga\\PaymentConnectors\\Drivers\\'.basename($f, '.php'))
        ->map(fn (string $c): ?string => $c::capabilities()->logoPath)
        ->filter();

    expect($paths)->not->toBeEmpty();
    foreach ($paths as $p) {
        expect(file_exists(public_path(ltrim($p, '/'))))->toBeTrue("missing {$p}");
    }
});
