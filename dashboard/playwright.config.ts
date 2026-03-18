import { defineConfig } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  retries: 0,
  workers: 1,
  use: {
    baseURL: 'http://localhost:3000',
    headless: true,
  },
  projects: [
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'auth',
      testMatch: /auth\.spec\.ts/,
      // Auth tests manage their own login state
    },
    {
      name: 'e2e',
      testIgnore: /auth\.(spec|setup)\.ts/,
      dependencies: ['setup'],
      use: {
        storageState: 'e2e/.auth/user.json',
      },
    },
  ],
})
