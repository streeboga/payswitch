<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected MerchantAccount $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = Organization::create(['name' => 'Test Org']);
        $this->merchant = MerchantAccount::create([
            'org_id' => $this->organization->id,
            'name' => 'Test Merchant',
        ]);
    }

    /**
     * Build a JSON:API request body for create/update.
     */
    protected function jsonApiData(string $type, array $attributes, ?string $id = null): array
    {
        $data = [
            'data' => [
                'type' => $type,
                'attributes' => $attributes,
            ],
        ];

        if ($id !== null) {
            $data['data']['id'] = $id;
        }

        return $data;
    }

    /**
     * Get merchant headers for dashboard requests.
     */
    protected function merchantHeaders(): array
    {
        return ['X-Merchant-Key' => $this->merchant->key];
    }

    /**
     * Make an authenticated GET request with merchant context.
     */
    protected function apiGet(string $uri, array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->getJson($uri, array_merge($this->merchantHeaders(), $headers));
    }

    /**
     * Make an authenticated POST request with merchant context.
     */
    protected function apiPost(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->postJson($uri, $data, array_merge($this->merchantHeaders(), $headers));
    }

    /**
     * Make an authenticated PATCH request with merchant context.
     */
    protected function apiPatch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->patchJson($uri, $data, array_merge($this->merchantHeaders(), $headers));
    }

    /**
     * Make an authenticated DELETE request with merchant context.
     */
    protected function apiDelete(string $uri, array $headers = []): TestResponse
    {
        return $this->actingAs($this->user)
            ->deleteJson($uri, [], array_merge($this->merchantHeaders(), $headers));
    }

    /**
     * Assert a JSON:API single resource response structure.
     */
    protected function assertJsonApiResource(TestResponse $response, string $type, int $status = 200): TestResponse
    {
        return $response
            ->assertStatus($status)
            ->assertJsonStructure(['data' => ['type', 'id', 'attributes']])
            ->assertJsonPath('data.type', $type);
    }

    /**
     * Assert a JSON:API collection response structure.
     */
    protected function assertJsonApiCollection(TestResponse $response, string $type): TestResponse
    {
        $response->assertOk()
            ->assertJsonStructure(['data', 'meta', 'links']);

        if (count($response->json('data')) > 0) {
            $response->assertJsonPath('data.0.type', $type);
        }

        return $response;
    }

    /**
     * Assert a JSON:API error response structure.
     */
    protected function assertJsonApiError(TestResponse $response, int $status): TestResponse
    {
        return $response
            ->assertStatus($status)
            ->assertJsonStructure(['errors' => [['status', 'title', 'detail']]]);
    }
}
