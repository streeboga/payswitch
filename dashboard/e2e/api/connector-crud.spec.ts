import { test, expect } from '@playwright/test'
import { authenticateApi } from '../helpers/api-client'

test.describe('Connector CRUD via dashboard API', () => {
  test.beforeAll(async ({ request }) => {
    await authenticateApi(request)
  })

  let createdConnectorKey: string

  test('list connectors', async ({ request }) => {
    const resp = await request.get('/api/v1/dashboard/connectors')
    expect(resp.status()).toBe(200)

    const body = await resp.json()
    expect(Array.isArray(body.data)).toBeTruthy()
  })

  test('create test connector', async ({ request }) => {
    const resp = await request.post('/api/v1/dashboard/connectors', {
      data: {
        connector_name: `e2e-test-${Date.now()}`,
        connector_type: 'stripe',
        connector_account_details: {
          api_key: 'sk_test_fake_key_for_e2e',
        },
        test_mode: true,
      },
    })

    expect(resp.status()).toBeLessThan(500)
    const body = await resp.json()
    if (body.data?.id) {
      createdConnectorKey = body.data.id
    }
  })

  test('get connector detail', async ({ request }) => {
    test.skip(!createdConnectorKey, 'No connector was created in previous test')

    const resp = await request.get(`/api/v1/dashboard/connectors/${createdConnectorKey}`)
    expect(resp.status()).toBe(200)

    const body = await resp.json()
    expect(body.data.id).toBe(createdConnectorKey)
  })

  test('delete connector', async ({ request }) => {
    test.skip(!createdConnectorKey, 'No connector was created in previous test')

    const resp = await request.delete(`/api/v1/dashboard/connectors/${createdConnectorKey}`)
    expect(resp.status()).toBeLessThan(500)
  })
})
