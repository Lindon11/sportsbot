import { test, expect, type Page } from '@playwright/test'

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Set up authenticated session in localStorage
 */
async function setupAuthSession(page: Page, options: { username?: string } = {}) {
  await page.evaluate((username) => {
    localStorage.setItem('user', JSON.stringify({
      id: 1,
      username: username || 'testuser',
      email: 'test@example.com',
    }))
  }, options.username || 'testuser')
}

/**
 * Mock the plugins API
 */
async function mockPluginsAPI(page: Page) {
  await page.route('**/api/v1/plugins/enabled*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        plugins: [],
        navigation: [],
        routes: [],
      }),
    }),
  )
}

// ─── Dashboard Page (Core) ───────────────────────────────────────────────────

test.describe('Dashboard Page', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('displays welcome banner on dashboard', async ({ page }) => {
    await page.goto('/dashboard')

    // Check for welcome banner
    await expect(page.locator('.welcome-banner')).toBeVisible()
    await expect(page.locator('.welcome-title')).toContainText('Welcome')
  })

  test('displays quick access cards', async ({ page }) => {
    await page.goto('/dashboard')

    // Check for quick access section
    await expect(page.locator('.quick-access')).toBeVisible()

    // Check for core quick cards
    await expect(page.locator('.quick-card').filter({ hasText: 'Profile' })).toBeVisible()
    await expect(page.locator('.quick-card').filter({ hasText: 'Settings' })).toBeVisible()
    await expect(page.locator('.quick-card').filter({ hasText: 'Activity' })).toBeVisible()
    await expect(page.locator('.quick-card').filter({ hasText: 'Notifications' })).toBeVisible()
  })

  test('displays empty state when no plugins installed', async ({ page }) => {
    await page.goto('/dashboard')

    // Check for empty state (since we mocked no plugins)
    await expect(page.locator('.empty-state')).toBeVisible()
    await expect(page.locator('.empty-title')).toContainText('No Plugins Installed')
  })

  test('displays logout button', async ({ page }) => {
    await page.goto('/dashboard')

    // Check for logout buttonअव
    await expect(page.locator('.logout-btn')).toBeVisible()
    await expect(page.locator('.logout-btn')).toContainText('Logout')
  })
})

// ─── Core Routes Navigation ──────────────────────────────────────────────────

test.describe('Core Routes Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('can navigate to profile page', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card[href="/profile"]')
    await expect(page).toHaveURL(/\/profile/)
  })

  test('can navigate to settings page', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card[href="/settings"]')
    await expect(page).toHaveURL(/\/settings/)
  })

  test('can navigate to activity page', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card[href="/activity"]')
    await expect(page).toHaveURL(/\/activity/)
  })

  test('can navigate to notifications page', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card[href="/notifications"]')
    await expect(page).toHaveURL(/\/notifications/)
  })
})

// ─── Profile Page ────────────────────────────────────────────────────────────

test.describe('Profile Page', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('displays profile page', async ({ page }) => {
    await page.goto('/profile')

    // Profile page should load
    await expect(page).toHaveURL(/\/profile/)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Settings Page ───────────────────────────────────────────────────────────

test.describe('Settings Page', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('displays settings page', async ({ page }) => {
    await page.goto('/settings')

    // Settings page should load
    await expect(page).toHaveURL(/\/settings/)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Activity Page ───────────────────────────────────────────────────────────

test.describe('Activity Page', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('displays activity page', async ({ page }) => {
    // Mock activity API
    await page.route('**/api/v1/activity*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [],
        }),
      }),
    )

    await page.goto('/activity')

    // Activity page should load
    await expect(page).toHaveURL(/\/activity/)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Notifications Page ──────────────────────────────────────────────────────

test.describe('Notifications Page', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('displays notifications page', async ({ page }) => {
    // Mock notifications API
    await page.route('**/api/v1/notifications*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [],
        }),
      }),
    )

    await page.goto('/notifications')

    // Notifications page should load
    await expect(page).toHaveURL(/\/notifications/)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Authentication Guard ────────────────────────────────────────────────────

test.describe('Authentication Guard', () => {
  test('unauthenticated user is redirected to login from dashboard', async ({ page }) => {
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())
    await page.goto('/dashboard')

    await expect(page).toHaveURL(/\/login/)
  })

  test('unauthenticated user is redirected to login from profile', async ({ page }) => {
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())
    await page.goto('/profile')

    await expect(page).toHaveURL(/\/login/)
  })

  test('unauthenticated user is redirected to login from settings', async ({ page }) => {
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())
    await page.goto('/settings')

    await expect(page).toHaveURL(/\/login/)
  })
})

// ─── Plugin Routes (Non-existent in Core) ─────────────────────────────────────

test.describe('Plugin Routes (Not in Core)', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('gaming routes show 404 when no plugins installed', async ({ page }) => {
    // These routes are no longer in core - they come from plugins
    const gamingRoutes = ['/crimes', '/gym', '/bank', '/hospital', '/jail', '/travel', '/shop']

    for (const route of gamingRoutes) {
      await page.goto(route)
      // Should show 404 or redirect to dashboard (depends on router config)
      const url = page.url()
      const is404 = url.includes('404') || url.includes('not-found')
      const isDashboard = url.includes('dashboard')
      expect(is404 || isDashboard).toBe(true)
    }
  })
})

// ─── Responsive Design ───────────────────────────────────────────────────────

test.describe('Responsive Design', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
  })

  test('displays correctly on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await page.goto('/dashboard')

    await expect(page.locator('.welcome-banner')).toBeVisible()
    await expect(page.locator('.quick-access')).toBeVisible()
  })

  test('displays correctly on tablet viewport', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 })
    await page.goto('/dashboard')

    await expect(page.locator('.welcome-banner')).toBeVisible()
    await expect(page.locator('.quick-access')).toBeVisible()
  })

  test('displays correctly on desktop viewport', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.goto('/dashboard')

    await expect(page.locator('.welcome-banner')).toBeVisible()
    await expect(page.locator('.quick-access')).toBeVisible()
  })
})
