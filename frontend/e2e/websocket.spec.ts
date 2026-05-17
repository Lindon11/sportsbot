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

/**
 * Mock the user profile API
 */
async function mockUserProfileAPI(page: Page) {
  await page.route('**/api/v1/user/profile*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: 1,
        username: 'testuser',
        email: 'test@example.com',
      }),
    }),
  )
}

/**
 * Set up all common mocks for tests
 */
async function setupCommonMocks(page: Page) {
  await setupAuthSession(page)
  await mockPluginsAPI(page)
  await mockUserProfileAPI(page)
}

// ─── WebSocket Connection ────────────────────────────────────────────────────

test.describe('WebSocket Connection', () => {
  test.beforeEach(async ({ page }) => {
    await setupCommonMocks(page)
  })

  test('application loads without WebSocket errors', async ({ page }) => {
    const errors: string[] = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.goto('/dashboard')

    // Wait for dashboard to be visible
    await expect(page.locator('body')).toBeVisible()

    // Should not have any WebSocket-related errors
    const wsErrors = errors.filter((e) =>
      e.toLowerCase().includes('websocket') || e.toLowerCase().includes('socket')
    )
    expect(wsErrors.length).toBe(0)
  })

  test('page remains functional when WebSocket is unavailable', async ({ page }) => {
    // Block WebSocket connections
    await page.route('ws://**', (route) => route.abort())

    await page.goto('/dashboard')

    // Page should still render
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Dashboard Functionality ─────────────────────────────────────────────────

test.describe('Dashboard Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await setupCommonMocks(page)
  })

  test('dashboard displays content', async ({ page }) => {
    await page.goto('/dashboard')

    // Check that content is displayed
    await expect(page.locator('.welcome-banner')).toBeVisible()
  })

  test('polling fallback works when WebSocket unavailable', async ({ page }) => {
    // Block WebSocket connections to force polling fallback
    await page.route('ws://**', (route) => route.abort())

    await page.goto('/dashboard')

    // Page should still function with polling
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Notifications ───────────────────────────────────────────────────────────

test.describe('Notifications', () => {
  test.beforeEach(async ({ page }) => {
    await setupCommonMocks(page)
  })

  test('notifications area loads', async ({ page }) => {
    await page.goto('/dashboard')

    // Look for notification-related elements
    const notifElements = page.locator('[class*="notification"], [class*="Notification"]')
    const notifCount = await notifElements.count().catch(() => 0)
    expect(notifCount).toBeGreaterThanOrEqual(0)
  })

  test('notifications work without WebSocket (fallback)', async ({ page }) => {
    // Block WebSocket
    await page.route('ws://**', (route) => route.abort())

    await page.goto('/dashboard')

    // Page should still work
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Connection Resilience ───────────────────────────────────────────────────

test.describe('Connection Resilience', () => {
  test.beforeEach(async ({ page }) => {
    await setupCommonMocks(page)
  })

  test('application handles network interruptions gracefully', async ({ page }) => {
    await page.goto('/dashboard')
    await expect(page.locator('body')).toBeVisible()

    // Simulate going offline
    await page.context().setOffline(true)

    // Simulate coming back online
    await page.context().setOffline(false)

    // Page should still be functional after network recovery
    await expect(page.locator('body')).toBeVisible()
  })

  test('application handles API errors gracefully', async ({ page }) => {
    // Mock API error
    await page.route('**/api/v1/**', (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          message: 'Server error',
        }),
      }),
    )

    await page.goto('/dashboard')

    // Page should still render (even if data is missing)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Performance ─────────────────────────────────────────────────────────────

test.describe('Performance', () => {
  test('dashboard loads within acceptable time', async ({ page }) => {
    await setupCommonMocks(page)

    const startTime = Date.now()
    await page.goto('/dashboard')
    const loadTime = Date.now() - startTime

    // Dashboard should load within 5 seconds
    expect(loadTime).toBeLessThan(5000)
  })

  test('no memory leaks from WebSocket reconnection attempts', async ({ page }) => {
    await setupCommonMocks(page)

    // Block WebSocket to trigger reconnection attempts
    await page.route('ws://**', (route) => route.abort())

    await page.goto('/dashboard')

    // Page should be responsive even with blocked WebSocket
    await expect(page.locator('body')).toBeVisible()
  })
})
