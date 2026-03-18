<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use Inertia\Inertia;
use Inertia\Response;
use Streeboga\PaymentData\Models\MerchantAccount;

final class MerchantsController
{
    public function index(): Response
    {
        $merchants = MerchantAccount::with('organization')->latest()->paginate(20)
            ->through(fn ($m) => [
                'id' => $m->key,
                'name' => $m->name,
                'organization' => $m->organization->name,
                'publishable_key' => $m->publishable_key,
                'created_at' => $m->created_at->format('d.m.Y H:i'),
            ]);

        return Inertia::render('dashboard/merchants/index', [
            'merchants' => $merchants,
        ]);
    }
}
