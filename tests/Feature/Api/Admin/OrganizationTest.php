<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['payswitch.admin_api_key' => 'admin_test_key']);
});

function adminHeaders(): array
{
    return ['api-key' => 'admin_test_key'];
}

test('can create organization with JSON:API format', function () {
    $response = $this->postJson('/api/v1/organizations', [
        'name' => 'Test Organization',
    ], adminHeaders());

    $response->assertStatus(201)
        ->assertHeader('Content-Type', 'application/vnd.api+json')
        ->assertHeader('Location')
        ->assertJsonStructure([
            'data' => [
                'type',
                'id',
                'attributes' => ['name', 'created_at'],
                'links' => ['self'],
            ],
        ])
        ->assertJsonPath('data.type', 'organizations')
        ->assertJsonPath('data.attributes.name', 'Test Organization');

    expect($response->json('data.id'))->toStartWith('org_');
});

test('cannot create organization without name', function () {
    $response = $this->postJson('/api/v1/organizations', [], adminHeaders());

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => [['status', 'code', 'detail', 'source']]]);
});

test('cannot create organization without admin key', function () {
    $response = $this->postJson('/api/v1/organizations', [
        'name' => 'Org',
    ]);

    $response->assertStatus(401);
});
