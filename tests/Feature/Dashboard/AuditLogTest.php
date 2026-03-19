<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create(['org_id' => $org->id, 'name' => 'M']);
    $this->headers = ['X-Merchant-Key' => $this->merchant->key];
});

test('audit log returns paginated json:api response', function () {
    Activity::create([
        'log_name' => 'default',
        'description' => 'Payment created',
        'event' => 'created',
        'subject_type' => 'PaymentIntent',
        'subject_id' => 1,
        'causer_type' => 'User',
        'causer_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/audit-log', $this->headers);

    $response->assertOk()
        ->assertJsonPath('data.0.type', 'audit-logs')
        ->assertJsonPath('meta.total', 1);
});

test('audit log filters by event type', function () {
    Activity::create(['log_name' => 'default', 'description' => 'Created', 'event' => 'created']);
    Activity::create(['log_name' => 'default', 'description' => 'Updated', 'event' => 'updated']);

    $response = $this->actingAs($this->user)
        ->getJson('/api/v1/dashboard/audit-log?filter[event]=created', $this->headers);

    $response->assertOk()
        ->assertJsonPath('meta.total', 1);
});

test('audit log export returns csv', function () {
    Activity::create(['log_name' => 'default', 'description' => 'Test', 'event' => 'created']);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/audit-log/export', $this->headers);

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('audit log export has correct csv headers', function () {
    Activity::create(['log_name' => 'default', 'description' => 'Test', 'event' => 'created']);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/audit-log/export', $this->headers);

    $response->assertOk();

    $content = $response->streamedContent();
    $lines = array_filter(explode("\n", $content));
    $headerRow = str_getcsv($lines[0]);

    expect($headerRow)->toBe(['id', 'event', 'description', 'subject_type', 'subject_id', 'causer_id', 'created_at']);
});

test('audit log export contains entry data', function () {
    $activity = Activity::create([
        'log_name' => 'default',
        'description' => 'Payment created',
        'event' => 'created',
        'subject_type' => 'PaymentIntent',
        'subject_id' => 42,
        'causer_type' => 'User',
        'causer_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/audit-log/export', $this->headers);

    $response->assertOk();

    $content = $response->streamedContent();
    $lines = array_filter(explode("\n", $content));
    expect($lines)->toHaveCount(2); // header + 1 data row

    $dataRow = str_getcsv($lines[1]);
    expect($dataRow[0])->toBe((string) $activity->id)
        ->and($dataRow[1])->toBe('created')
        ->and($dataRow[2])->toBe('Payment created')
        ->and($dataRow[3])->toBe('PaymentIntent')
        ->and($dataRow[4])->toBe('42')
        ->and($dataRow[5])->toBe((string) $this->user->id);
});

test('audit log export sets correct filename', function () {
    $response = $this->actingAs($this->user)
        ->get('/api/v1/dashboard/audit-log/export', $this->headers);

    $response->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename=audit-log-export.csv');
});

test('audit log export requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/audit-log/export', $this->headers);
    $response->assertUnauthorized();
});

test('audit log export respects date filters', function () {
    Activity::create([
        'log_name' => 'default',
        'description' => 'Old entry',
        'event' => 'created',
        'created_at' => now()->subDays(10),
    ]);
    Activity::create([
        'log_name' => 'default',
        'description' => 'Recent entry',
        'event' => 'updated',
        'created_at' => now()->subDay(),
    ]);

    $from = now()->subDays(3)->format('Y-m-d');
    $to = now()->format('Y-m-d');

    $response = $this->actingAs($this->user)
        ->get("/api/v1/dashboard/audit-log/export?filter[from]={$from}&filter[to]={$to}", $this->headers);

    $response->assertOk();

    $content = $response->streamedContent();
    $lines = array_filter(explode("\n", $content));
    expect($lines)->toHaveCount(2); // header + 1 filtered row

    $dataRow = str_getcsv($lines[1]);
    expect($dataRow[2])->toBe('Recent entry');
});

test('audit log requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/audit-log', $this->headers);
    $response->assertUnauthorized();
});
