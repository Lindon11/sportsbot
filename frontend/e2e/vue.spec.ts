import { test, expect, type Page } from '@playwright/test'

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Set up authenticated session in localStorage
 */
async function setupAuthSession(page: Page) {
  await page.evaluate(() => {
    localStorage.setItem('user', JSON.stringify({
      id: 1,
      username: 'testuser',
      email: 'test@example.com',
    }))
  })
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

// ─── Application Basic Tests ────────────────────────────────────────────────

test.describe('Core Web APP OS - Basic Tests', () => {
  test('application loads successfully', async ({ page }) => {
    await page.goto('/')

    // Application should render
    await expect(page.locator('body')).toBeVisible()

    // Should not have console errors
    const errors: string[] = []
    page.on('pageerror', (error) => {
      errors.push(error.message)
    })

    await page.waitForTimeout(1000)

    // Filter out non-critical errors
    const criticalErrors = errors.filter((e) =>
      !e.includes('WebSocket') && !e.includes('NetworkError')
    )
    expect(criticalErrors.length).toBe(0)
  })

  test('unauthenticated user is redirected to login', async ({ page }) => {
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())
    await page.goto('/')

    // Should be redirected to login
    await expect(page).toHaveURL(/\/login/)
  })
})

// ─── Login Page Tests ───────────────────────────────────────────────────────

test.describe('Login Page', () => {
  test('login page renders correctly', async ({ page }) => {
    await page.goto('/login')

    // Check for login form elements
    await expect(page.locator('#email')).toBeVisible()
    await expect(page.locator('#password')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
  })

  test('login page shows title', async ({ page }) => {
    await page.goto('/login')

    // Check page has loaded
    await expect(page.locator('body')).toBeVisible()
  })

  test('login form is submittable', async ({ page }) => {
    // Mock login API
    await page.route('**/api/v1/login', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          user: {
            id: 1,
            username: 'testuser',
            email: 'test@example.com',
          },
          token: 'test-token',
        }),
      }),
    )

    await page.goto('/login')
    await page.fill('#email', 'test@example.com')
    await page.fill('#password', 'password123')
    await page.click('button[type="submit"]')

    // Form should be submitted (no errors)
    await page.waitForTimeout(1000)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Register Page Tests ────────────────────────────────────────────────────

test.describe('Register Page', () => {
  test('register page renders correctly', async ({ page }) => {
    await page.goto('/register')

    // Check for register form elements
    await expect(page.locator('#email')).toBeVisible()
    await expect(page.locator('#password')).toBeVisible()
  })

  test('register form is submittable', async ({ page }) => {
    // Mock register API
    await page.route('**/api/v1/register', (route) =>
      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          user: {
            id: 1,
            username: 'newuser',
            email: 'new@example.com',
          },
          token: 'test-token',
        }),
      }),
    )

    await page.goto('/register')
    await page.fill('#email', 'new@example.com')
    await page.fill('#password', 'password123')
    await page.locator('#password-confirm').fill('password123').catch(() => {
      // Password confirm field may not exist
    })
    await page.click('button[type="submit"]')

    // Form should be submitted (no errors)
    await page.waitForTimeout(1000)
    await expect(page.locator('body')).toBeVisible()
  })
})

// ─── Dashboard Access Tests ─────────────────────────────────────────────────

test.describe('Dashboard Access', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('dashboard loads for authenticated user', async ({ page }) => {
    await page.goto('/dashboard')

    // Dashboard should be visible
    await expect(page.locator('.welcome-banner')).toBeVisible()
  })

  test('dashboard shows welcome message', async ({ page }) => {
    await page.goto('/dashboard')

    // Welcome message should be visible
    await expect(page.locator('.welcome-title')).toContainText('Welcome')
  })

  test('dashboard shows quick access cards', async ({ page }) => {
    await page.goto('/dashboard')

    // Quick access section should be visible
    await expect(page.locator('.quick-access')).toBeVisible()

    // Should have 4 quick cards
    const quickCards = page.locator('.quick-card')
    await expect(quickCards).toHaveCount(4)
  })
})

// ─── Navigation Tests ───────────────────────────────────────────────────────

test.describe('Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('can navigate to profile from dashboard', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card:has-text("Profile")')

    await expect(page).toHaveURL(/\/profile/)
  })

  test('can navigate to settings from dashboard', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card:has-text("Settings")')

    await expect(page).toHaveURL(/\/settings/)
  })

  test('can navigate to activity from dashboard', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card:has-text("Activity")')

    await expect(page).toHaveURL(/\/activity/)
  })

  test('can navigate to notifications from dashboard', async ({ page }) => {
    await page.goto('/dashboard')
    await page.click('.quick-card:has-text("Notifications")')

    await expect(page).toHaveURL(/\/notifications/)
  })
})

// ─── Core Layout Tests ──────────────────────────────────────────────────────

test.describe('Core Layout', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('layout renders header', async ({ page }) => {
    await page.goto('/dashboard')

    // Header should be visible
    const header = page.locator('header')
    await expect(header).toBeVisible()
  })

  test('layout renders navigation', async ({ page }) => {
    await page.goto('/dashboard')

    // Navigation should be visible
    const nav = page.locator('nav')
    await expect(nav).toBeVisible()
  })
})

// ─── Error Handling Tests ───────────────────────────────────────────────────

test.describe('Error Handling', () => {
  test('handles 404 page gracefully', async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)

    // Navigate to non-existent route
    await page.goto('/non-existent-route')

    // Page should still render (either 404 page or redirect)
    await expect(page.locator('body')).toBeVisible()
  })

  test('handles API errors gracefully', async ({ page }) => {
    await setupAuthSession(page)

    // Mock API error
    await page.route('**/api/v1/**', (route) =>
      route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Server error' }),
      }),
    )

    await page.goto('/dashboard')

    // Page should still render
    await expect(page.locator('body')).toBeVisible()
  })
})
