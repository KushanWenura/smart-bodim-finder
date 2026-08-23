import { defineConfig, devices } from '@playwright/test';

const isWindows = process.platform === 'win32';

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  workers: 1,
  retries: process.env.CI ? 2 : 0,
  reporter: [['list'], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:5173',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'desktop-chromium',
      use: {
        ...devices['Desktop Chrome'],
        ...(isWindows ? { channel: 'msedge' as const } : {}),
      },
    },
  ],
  expect: { timeout: 10_000 },
  timeout: 30_000,
});
