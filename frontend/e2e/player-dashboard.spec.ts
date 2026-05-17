import { test, expect, type Page } from '@playwright/test'

// ─── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Set up authenticated session in localStorage
 */
async function setupAuthSession(page: Page, options: { username?: string; isAdmin?: boolean } = {}) {
  await page.evaluate((opts) => {
    localStorage.setItem('user', JSON.stringify({
      id: 1,
      username: opts.username || 'testuser',
      email: 'test@example.com',
      is_admin: opts.isAdmin || false,
    }))
  }, { username: options.username || 'testuser', isAdmin: options.isAdmin || false })
}

/**
 * Mock the plugins API
 */
async function mockPluginsAPI(page: Page, plugins: unknown[] = []) {
  await page.route('**/api/v1/plugins/enabled*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        plugins: plugins,
        navigation: [],
        routes: [],
      }),
    }),
  )
}

/**
 * Mock the user profile API
 */
async function mockUserProfileAPI(page: Page, options: { username?: string; isAdmin?: boolean } = {}) {
  await page.route('**/api/v1/user/profile*', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        id: 1,
        username: options.username || 'testuser',
        email: 'test@example.com',
        is_admin: options.isAdmin || false,
      }),
    }),
  )
}

// ─── Welcome Banner Tests ────────────────────────────────────────────────────

test.describe('Welcome Banner', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('displays welcome banner with user name', async ({ page }) => {
    await page.goto('/')

    // Check for welcome banner
    await expect(page.locator('.welcome-banner')).toBeVisible()
    await expect(page.locator('.welcome-title')).toContainText('Welcome')
  })

  test('displays welcome subtitle', async ({ page }) => {
    await page.goto('/')

    // Check for subtitle
    await expect(page.locator('.welcome-subtitle')).toContainText('Manage your account')
  })

  test('displays logout button', async ({ page }) => {
    await page.goto('/')

    // Check for logout button
    await expect(page.locator('.logout-btn')).toBeVisible()
    await expect(page.locator('.logout-btn')).toContainText('Logout')
  })

  test('does not show admin link for non-admin users', async ({ page }) => {
    await setupAuthSession(page, { isAdmin: false })
    await page.goto('/')

    // Admin link should not be visible
    await expect(page.locator('.admin-link')).not.toBeVisible()
  })

  test('shows admin link for admin users', async ({ page }) => {
    await setupAuthSession(page, { isAdmin: true })
    await mockUserProfileAPI(page, { isAdmin: true })
    await page.goto('/')

    // Admin link should be visible
    await expect(page.locator('.admin-link')).toBeVisible()
    await expect(page.locator('.admin-link')).toContainText('Admin Panel')
  })
})

// ─── Quick Access Cards Tests ────────────────────────────────────────────────

test.describe('Quick Access Cards', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('displays quick access section', async ({ page }) => {
    await page.goto('/')

    // Check for quick access section
    await expect(page.locator('.quick-access')).toBeVisible()
  })

  test('displays Profile quick card', async ({ page }) => {
    await page.goto('/')

    const profileCard = page.locator('.quick-card').filter({ hasText: 'Profile' })
    await expect(profileCard).toBeVisible()
    await expect(profileCard.locator('.card-label')).toContainText('Profile')
    await expect(profileCard.locator('.card-desc')).toContainText('Edit your profile')
  })

  test('displays Settings quick card', async ({ page }) => {
    await page.goto('/')

    const settingsCard = page.locator('.quick-card').filter({ hasText: 'Settings' })
    await expect(settingsCard).toBeVisible()
    await expect(settingsCard.locator('.card-label')).toContainText('Settings')
    await expect(settingsCard.locator('.card-desc')).toContainText('Account settings')
  })

  test('displays Activity quick card', async ({ page }) => {
    await page.goto('/')

    const activityCard = page.locator('.quick-card').filter({ hasText: 'Activity' })
    await expect(activityCard).toBeVisible()
    await expect(activityCard.locator('.card-label')).toContainText('Activity')
    await expect(activityCard.locator('.card-desc')).toContainText('Recent activity')
  })

  test('displays Notifications quick card', async ({ page }) => {
    await page.goto('/')

    const notificationsCard = page.locator('.quick-card').filter({ hasText: 'Notifications' })
    await expect(notificationsCard).toBeVisible()
    await expect(notificationsCard.locator('.card-label')).toContainText('Notifications')
    await expect(notificationsCard.locator('.card-desc')).toContainText('View alerts')
  })

  test('has exactly 4 quick access cards', async ({ page }) => {
    await page.goto('/')

    // Should have exactly 4 quick cards in core
    const quickCards = page.locator('.quick-access .quick-card')
    await expect(quickCards).toHaveCount(4)
  })
})

// ─── Quick Card Navigation Tests ─────────────────────────────────────────────

test.describe('Quick Card Navigation', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('Profile card navigates to /profile', async ({ page }) => {
    await page.goto('/')
    await page.click('.quick-card:has-text("Profile")')
    await expect(page).toHaveURL(/\/profile/)
  })

  test('Settings card navigates to /settings', async ({ page }) => {
    await page.goto('/')
    await page.click('.quick-card:has-text("Settings")')
    await expect(page).toHaveURL(/\/settings/)
  })

  test('Activity card navigates to /activity', async ({ page }) => {
    await page.goto('/')
    await page.click('.quick-card:has-text("Activity")')
    await expect(page).toHaveURL(/\/activity/)
  })

  test('Notifications card navigates to /notifications', async ({ page }) => {
    await page.goto('/')
    await page.click('.quick-card:has-text("Notifications")')
    await expect(page).toHaveURL(/\/notifications/)
  })
})

// ─── Empty State Tests ───────────────────────────────────────────────────────

test.describe('Empty State (No Plugins)', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page, []) // No plugins
    await mockUserProfileAPI(page)
  })

  test('displays empty state when no plugins installed', async ({ page }) => {
    await page.goto('/')

    // Check for empty state
    await expect(page.locator('.empty-state')).toBeVisible()
  })

  test('displays empty state icon', async ({ page }) => {
    await page.goto('/')

    // Check for puzzle piece icon
    await expect(page.locator('.empty-icon')).toBeVisible()
    await expect(page.locator('.empty-icon')).toContainText('🧩')
  })

  test('displays empty state title', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('.empty-title')).toBeVisible()
    await expect(page.locator('.empty-title')).toContainText('No Plugins Installed')
  })

  test('displays empty state description', async ({ page }) => {
    await page.goto('/')

    await expect(page.locator('.empty-desc')).toBeVisible()
    await expect(page.locator('.empty-desc')).toContainText('Core Web APP OS')
  })

  test('shows install link for admin users', async ({ page }) => {
    await setupAuthSession(page, { isAdmin: true })
    await mockUserProfileAPI(page, { isAdmin: true })
    await page.goto('/')

    // Install link should be visible for admins
    await expect(page.locator('.install-link')).toBeVisible()
    await expect(page.locator('.install-link')).toContainText('Manage Plugins')
  })

  test('hides install link for non-admin users', async ({ page }) => {
    await setupAuthSession(page, { isAdmin: false })
    await page.goto('/')

    // Install link should not be visible for non-admins
    await expect(page.locator('.install-link')).not.toBeVisible()
  })
})

// ─── Plugin Feature Cards Tests ──────────────────────────────────────────────

test.describe('Plugin Feature Cards', () => {
  test('displays plugin feature cards when plugins are installed', async ({ page }) => {
    await setupAuthSession(page)

    // Mock plugins with navigation
    const mockPlugins = [
      {
        id: 'plugin-1',
        slug: 'example-plugin',
        name: 'Example Plugin',
        description: 'An example plugin for testing',
        icon: '📦',
        route_name: 'example',
        navigation: { enabled: true },
      },
    ]

    await page.route('**/api/v1/plugins/enabled*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          plugins: mockPlugins,
          navigation: [],
          routes: [],
        }),
      }),
    )

    await mockUserProfileAPI(page)
    await page.goto('/')

    // Should show features section
    await expect(page.locator('.section-title').filter({ hasText: 'Features' })).toBeVisible()

    // Should show feature card
    const featureCard = page.locator('.feature-card').filter({ hasText: 'Example Plugin' })
    await expect(featureCard).toBeVisible()
  })

  test('does not display features section when no plugins', async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page, [])
    await mockUserProfileAPI(page)
    await page.goto('/')

    // Should not show features section
    await expect(page.locator('.section-title').filter({ hasText: 'Features' })).not.toBeVisible()

    // Should not show any feature cards
    await expect(page.locator('.feature-card')).toHaveCount(0)
  })
})

// ─── Logout Tests ────────────────────────────────────────────────────────────

test.describe('Logout Functionality', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)

    // Mock logout API
    await page.route('**/api/v1/logout*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      }),
    )
  })

  test('logout button clears session and redirects to login', async ({ page }) => {
    await page.goto('/')

    // Click logout
    await page.click('.logout-btn')

    // Should redirect to login page
    await expect(page).toHaveURL(/\/login/)
  })
})

// ─── Responsive Design Tests ─────────────────────────────────────────────────

test.describe('Responsive Design', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('displays correctly on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await page.goto('/')

    // Welcome banner should be visible
    await expect(page.locator('.welcome-banner')).toBeVisible()

    // Quick access should be visible
    await expect(page.locator('.quick-access')).toBeVisible()

    // Quick cards should still be present
    const quickCards = page.locator('.quick-card')
    await expect(quickCards).toHaveCount(4)
  })

  test('displays correctly on tablet viewport', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 })
    await page.goto('/')

    // Welcome banner should be visible
    await expect(page.locator('.welcome-banner')).toBeVisible()

    // Quick access should be visible
    await expect(page.locator('.quick-access')).toBeVisible()
  })

  test('displays correctly on desktop viewport', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.goto('/')

    // Welcome banner should be visible
    await expect(page.locator('.welcome-banner')).toBeVisible()

    // Quick access should be visible
    await expect(page.locator('.quick-access')).toBeVisible()
  })
})

// ─── Authentication Guard Tests ──────────────────────────────────────────────

test.describe('Authentication Guard', () => {
  test('unauthenticated user is redirected to login', async ({ page }) => {
    // Clear any existing session
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())

    // Try to access home page
    await page.goto('/')

    // Should be redirected to login
    await expect(page).toHaveURL(/\/login/)
  })
})

// ─── No Gaming Content Tests ─────────────────────────────────────────────────

test.describe('No Gaming Content in Core', () => {
  test.beforeEach(async ({ page }) => {
    await setupAuthSession(page)
    await mockPluginsAPI(page)
    await mockUserProfileAPI(page)
  })

  test('does not display gaming-specific quick cards', async ({ page }) => {
    await page.goto('/')

    // Check that gaming cards are NOT present
    const gamingTerms = ['Crimes', 'Gym', 'Bank', 'Hospital', 'Jail', 'Travel', 'Shop', 'Scavenge']

    for (const term of gamingTerms) {
      const card = page.locator('.quick-card').filter({ hasText: term })
      await expect(card).toHaveCount(0)
    }
  })

  test('does not display gaming-specific feature cards without plugins', async ({ page }) => {
    await page.goto('/')

    // Check that gaming feature cards are NOT present in core
    const gamingTerms = ['theft', 'bullets', 'racing', 'organized crime']

    for (const term of gamingTerms) {
      const card = page.locator('.feature-card').filter({ hasText: new RegExp(term, 'i') })
      await expect(card).toHaveCount(0)
    }
  })
})
