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

test('audit log requires authentication', function () {
    $response = $this->getJson('/api/v1/dashboard/audit-log', $this->headers);
    $response->assertUnauthorized();
});
