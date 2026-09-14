<?php

use App\Jobs\CleanExpiredPaymentsJob;
use App\Jobs\ReconcileWebhookEventsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(CleanExpiredPaymentsJob::class)->everyFiveMinutes();
Schedule::job(ReconcileWebhookEventsJob::class)->everyFiveMinutes()->withoutOverlapping();
