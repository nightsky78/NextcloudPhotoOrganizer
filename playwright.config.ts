import { defineConfig } from '@playwright/test'

const baseURL = process.env.NC_E2E_BASE_URL || 'https://nextcloud.example.com'

export default defineConfig({
	testDir: './tests/E2E',
	timeout: 90_000,
	expect: {
		timeout: 15_000,
	},
	fullyParallel: false,
	retries: 1,
	workers: 1,
	use: {
		baseURL,
		ignoreHTTPSErrors: true,
		headless: true,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
	},
	reporter: [['list']],
})
