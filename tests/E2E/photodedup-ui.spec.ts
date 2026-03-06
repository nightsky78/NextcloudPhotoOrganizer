import { expect, test, type Page } from '@playwright/test'

const username = process.env.NC_E2E_USER
const password = process.env.NC_E2E_PASSWORD

async function loginIfNeeded(page: Page): Promise<void> {
	await page.goto('/login')

	const loginField = page.getByRole('textbox', { name: 'Account name or email' })
	if (await loginField.isVisible().catch(() => false)) {
		await loginField.fill(String(username))
		await page.getByRole('textbox', { name: 'Password' }).fill(String(password))
		await page.getByRole('button', { name: 'Log in', exact: true }).click()
	}
}

async function openPhotoDedup(page: Page): Promise<void> {
	await loginIfNeeded(page)
	await page.goto('/apps/photodedup/')
	await expect(page).toHaveURL(/\/apps\/photodedup\/?$/)
}

test.describe('PhotoDedup E2E', () => {
	test.skip(!username || !password, 'Set NC_E2E_USER and NC_E2E_PASSWORD to run Playwright E2E tests.')

	test.beforeEach(async ({ page }) => {
		await openPhotoDedup(page)
	})

	test('loads app shell with all tabs', async ({ page }) => {
		await expect(page.getByRole('button', { name: 'Duplicates', exact: true })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Classifier', exact: true })).toBeVisible()
		await expect(page.getByRole('button', { name: 'People', exact: true })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Locations', exact: true })).toBeVisible()
		await expect(page.getByRole('heading', { name: 'Photo Deduplicator' })).toBeVisible()
	})

	test('duplicates tab shows scope controls and scan action', async ({ page }) => {
		await expect(page.getByRole('button', { name: 'Whole drive' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Photos folder' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Scan for duplicates' })).toBeVisible()

		await page.getByRole('button', { name: 'Photos folder' }).click()
		await expect(page.getByRole('heading', { name: 'Photo Deduplicator' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Scan for duplicates' })).toBeVisible()

		await page.getByRole('button', { name: 'Whole drive' }).click()
		await expect(page.getByRole('button', { name: 'Scan for duplicates' })).toBeVisible()
	})

	test('classifier tab supports scope switching', async ({ page }) => {
		await page.getByRole('button', { name: 'Classifier', exact: true }).click()
		await expect(page.getByRole('heading', { name: 'Image Classifier' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Classify images' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Whole drive' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Photos folder' })).toBeVisible()

		await page.getByRole('button', { name: 'Photos folder' }).click()
		await expect(page.getByRole('heading', { name: 'Image Classifier' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Classify images' })).toBeVisible()

		await page.getByRole('button', { name: 'Whole drive' }).click()
		await expect(page.getByRole('heading', { name: 'Image Classifier' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Classify images' })).toBeVisible()
	})

	test('people tab supports scope switching', async ({ page }) => {
		await page.getByRole('button', { name: 'People', exact: true }).click()
		await expect(page.getByRole('heading', { name: 'People' })).toBeVisible()

		await page.getByRole('button', { name: 'Photos folder' }).click()
		await expect(page.getByRole('heading', { name: 'People' })).toBeVisible()
		await expect(page.locator('body')).toContainText(/No people clusters yet|face images|clusters/)

		await page.getByRole('button', { name: 'Whole drive' }).click()
		await expect(page.getByRole('heading', { name: 'People' })).toBeVisible()
		await expect(page.locator('body')).toContainText(/No people clusters yet|face images|clusters/)
	})

	test('locations map stays visible after scope switching back and forth', async ({ page }) => {
		await page.getByRole('button', { name: 'Locations', exact: true }).click()
		await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible()

		const emptyState = page.getByText('No geotagged photos found')
		if (await emptyState.isVisible().catch(() => false)) {
			test.skip(true, 'No geotagged photos available for map visibility assertions.')
		}

		const map = page.locator('.locations__map.leaflet-container')
		await expect(map).toBeVisible()

		await page.getByRole('button', { name: 'Photos folder' }).click()
		await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible()
		await expect(map).toBeVisible()

		await page.getByRole('button', { name: 'Whole drive' }).click()
		await expect(page.getByRole('heading', { name: 'Locations' })).toBeVisible()
		await expect(map).toBeVisible()
	})
})
