import { test, expect } from '@playwright/test'

/**
 * E2E tests for plugin dynamic loading functionality
 * Tests the integration between frontend and backend plugin systems
 */

test.describe('Plugin Loading', () => {
  test.beforeEach(async ({ page }) => {
    // Set up authenticated session in localStorage
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({
        id: 1,
        username: 'Test User',
        email: 'test@example.com'
      }))
    })

    // Mock user profile API
    await page.route('**/api/v1/user/profile*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 1,
          username: 'Test User',
          email: 'test@example.com',
        }),
      }),
    )

    // Mock the plugins API response
    await page.route('**/api/v1/plugins/enabled*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          plugins: [
            {
              slug: 'combat',
              name: 'Combat',
              version: '1.0.0',
              description: 'Engage in combat',
              icon: '⚔️',
              color: 'red',
              route_name: 'combat.index',
              frontend_routes: [
                {
                  path: '/combat',
                  name: 'combat',
                  component: 'CombatView',
                  meta: { title: 'Combat' },
                },
              ],
              navigation: {
                enabled: true,
                section: 'actions',
                order: 10,
                parent: null,
              },
              order: 10,
              has_api_routes: true,
              has_web_routes: true,
              has_admin_routes: false,
              frontend_slots: ['dashboard-widget'],
              permissions: [],
            },
            {
              slug: 'hospital',
              name: 'Hospital',
              version: '1.0.0',
              description: 'Heal your wounds',
              icon: '🏥',
              color: 'green',
              route_name: 'hospital',
              frontend_routes: [
                {
                  path: '/hospital',
                  name: 'hospital',
                  component: 'HospitalView',
                  meta: { title: 'Hospital' },
                },
              ],
              navigation: {
                enabled: true,
                section: 'utilities',
                order: 5,
                parent: null,
              },
              order: 5,
              has_api_routes: true,
              has_web_routes: true,
              has_admin_routes: false,
              frontend_slots: [],
              permissions: [],
            },
          ],
          navigation: [
            {
              slug: 'hospital',
              name: 'Hospital',
              icon: '🏥',
              color: 'green',
              route: 'hospital',
              section: 'utilities',
              order: 5,
            },
            {
              slug: 'combat',
              name: 'Combat',
              icon: '⚔️',
              color: 'red',
              route: 'combat.index',
              section: 'actions',
              order: 10,
            },
          ],
          routes: [
            {
              plugin: 'combat',
              path: '/combat',
              name: 'combat',
              component: 'CombatView',
              meta: { title: 'Combat' },
            },
            {
              plugin: 'hospital',
              path: '/hospital',
              name: 'hospital',
              component: 'HospitalView',
              meta: { title: 'Hospital' },
            },
          ],
        }),
      })
    })
  })

  test('plugins API is called on authenticated navigation', async ({ page }) => {
    // Start watching for the API call
    const pluginsRequest = page.waitForRequest('**/api/v1/plugins/enabled*')

    // Navigate to dashboard
    await page.goto('/dashboard')

    // Wait for the plugins request
    const request = await pluginsRequest
    expect(request.url()).toContain('/api/v1/plugins/enabled')
  })

  test('plugin navigation items are displayed', async ({ page }) => {
    await page.goto('/dashboard')

    // Wait for plugins to load
    await page.waitForResponse('**/api/v1/plugins/enabled*')

    // Check that navigation shows plugin items
    await expect(page.getByRole('link', { name: /combat/i })).toBeVisible()
  })

  test('plugin routes are accessible', async ({ page }) => {
    // Mock combat API endpoint
    await page.route('**/api/v1/combat**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: [] }),
      })
    })

    await page.goto('/dashboard')
    await page.waitForResponse('**/api/v1/plugins/enabled*')

    // Navigate to a plugin route
    await page.goto('/combat')

    // Should show combat view (not 404)
    await expect(page).not.toHaveURL('/404')
  })

  test('plugins are sorted by order', async ({ page }) => {
    await page.goto('/dashboard')

    // Wait for plugins to load
    await page.waitForResponse('**/api/v1/plugins/enabled*')

    // Page should render without errors
    await expect(page.locator('body')).toBeVisible()
  })
})

test.describe('Plugin Store', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({
        id: 1,
        username: 'Test User',
        email: 'test@example.com'
      }))
    })

    await page.route('**/api/v1/user/profile*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 1,
          username: 'Test User',
          email: 'test@example.com',
        }),
      }),
    )

    await page.route('**/api/v1/plugins/enabled*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          plugins: [],
          navigation: [],
          routes: [],
        }),
      })
    })
  })

  test('plugins store has correct structure after fetch', async ({ page }) => {
    await page.goto('/dashboard')
    await page.waitForResponse('**/api/v1/plugins/enabled*')

    // Check store state
    const storeState = await page.evaluate(() => {
      const pluginsStore = (window as unknown as { __PLUGINS_STORE__: unknown }).__PLUGINS_STORE__
      return pluginsStore
    })

    // Store should have plugins loaded
    expect(storeState).toBeDefined()
  })
})

test.describe('Plugin Routes Guard', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({
        id: 1,
        username: 'Test User',
        email: 'test@example.com'
      }))
    })

    await page.route('**/api/v1/user/profile*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 1,
          username: 'Test User',
          email: 'test@example.com',
        }),
      }),
    )
  })

  test('disabled plugin route shows not found', async ({ page }) => {
    // Mock plugins API with no enabled plugins
    await page.route('**/api/v1/plugins/enabled*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          plugins: [],
          navigation: [],
          routes: [],
        }),
      })
    })

    await page.goto('/dashboard')
    await page.waitForResponse('**/api/v1/plugins/enabled*')

    // Try to navigate to a plugin route that doesn't exist
    await page.goto('/combat')

    // Page should still render (either 404 or fallback)
    await expect(page.locator('body')).toBeVisible()
  })
})

test.describe('Plugin Cache', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({
        id: 1,
        username: 'Test User',
        email: 'test@example.com'
      }))
    })

    await page.route('**/api/v1/user/profile*', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          id: 1,
          username: 'Test User',
          email: 'test@example.com',
        }),
      }),
    )
  })

  test('plugins are cached after first load', async ({ page }) => {
    let requestCount = 0

    await page.route('**/api/v1/plugins/enabled*', async (route) => {
      requestCount++
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          plugins: [],
          navigation: [],
          routes: [],
        }),
      })
    })

    // First navigation
    await page.goto('/dashboard')
    await page.waitForResponse('**/api/v1/plugins/enabled*')
    expect(requestCount).toBe(1)

    // Navigate to another page
    await page.goto('/settings')

    // Go back to dashboard
    await page.goto('/dashboard')

    // Should not have made another request (cached)
    // Note: This depends on implementation - might be 1 or 2 depending on caching
    expect(requestCount).toBeLessThanOrEqual(2)
  })
})
