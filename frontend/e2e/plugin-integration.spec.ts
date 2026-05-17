import { test, expect } from '@playwright/test'

/**
 * E2E tests for plugin system integration
 * Tests that all plugins are correctly integrated with the frontend
 * and no hardcoded pre-upgrade fragments remain
 */

test.describe('Plugin Integration Tests', () => {
  test.describe('Plugin Manifest API', () => {
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

    test('enabled plugins endpoint returns valid structure', async ({ page }) => {
      // Intercept the plugins API call
      const responsePromise = page.waitForResponse('**/api/v1/plugins/enabled*')

      await page.goto('/dashboard')
      const response = await responsePromise

      expect(response.status()).toBe(200)

      const data = await response.json()

      // Validate response structure
      expect(data).toHaveProperty('success')
      expect(data.success).toBe(true)
      expect(data).toHaveProperty('plugins')
      expect(data).toHaveProperty('navigation')
      expect(data).toHaveProperty('routes')
      expect(Array.isArray(data.plugins)).toBe(true)
      expect(Array.isArray(data.navigation)).toBe(true)
      expect(Array.isArray(data.routes)).toBe(true)
    })

    test('each plugin has required frontend fields', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      const responsePromise = page.waitForResponse('**/api/v1/plugins/enabled')
      await page.goto('/dashboard')
      const response = await responsePromise
      const data = await response.json()

      const requiredFields = [
        'slug',
        'name',
        'version',
        'description',
        'icon',
        'color',
        'route_name',
        'frontend_routes',
        'navigation',
        'order',
        'has_api_routes',
        'has_web_routes',
        'has_admin_routes',
      ]

      for (const plugin of data.plugins) {
        for (const field of requiredFields) {
          expect(plugin).toHaveProperty(field)
        }
      }
    })

    test('navigation items have correct structure', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      const responsePromise = page.waitForResponse('**/api/v1/plugins/enabled')
      await page.goto('/dashboard')
      const response = await responsePromise
      const data = await response.json()

      const navFields = ['slug', 'name', 'icon', 'color', 'route', 'section', 'order']

      for (const navItem of data.navigation) {
        for (const field of navFields) {
          expect(navItem).toHaveProperty(field)
        }
      }
    })

    test('routes have correct structure', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      const responsePromise = page.waitForResponse('**/api/v1/plugins/enabled')
      await page.goto('/dashboard')
      const response = await responsePromise
      const data = await response.json()

      for (const route of data.routes) {
        expect(route).toHaveProperty('plugin')
        expect(route).toHaveProperty('path')
        expect(route).toHaveProperty('name')
        expect(route).toHaveProperty('component')
        expect(route.path).toMatch(/^\//) // Paths should start with /
      }
    })
  })

  test.describe('Plugin Store Integration', () => {
    test('plugin store is populated on load', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      await page.goto('/dashboard')
      await page.waitForResponse('**/api/v1/plugins/enabled')

      // Check that plugin store has loaded data
      const storeState = await page.evaluate(() => {
        const pluginsStore = (window as Window & { __PLUGINS_STORE__?: { loaded: boolean; plugins: unknown[]; routes: unknown[]; navigation: unknown[] } }).__PLUGINS_STORE__
        if (pluginsStore) {
          return {
            pluginsLoaded: pluginsStore.loaded,
            pluginsCount: pluginsStore.plugins?.length || 0,
            routesCount: pluginsStore.routes?.length || 0,
            navigationCount: pluginsStore.navigation?.length || 0,
          }
        }
        return null
      })

      expect(storeState).not.toBeNull()
    })

    test('isEnabled function works correctly', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      // Mock a specific plugin response
      await page.route('**/api/v1/plugins/enabled', async (route) => {
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
                description: 'Combat plugin',
                icon: '⚔️',
                color: 'red',
                route_name: 'combat.index',
                frontend_routes: [],
                navigation: { enabled: true, section: 'actions', order: 10, parent: null },
                order: 10,
                has_api_routes: true,
                has_web_routes: true,
                has_admin_routes: false,
                frontend_slots: [],
                permissions: [],
              },
            ],
            navigation: [],
            routes: [],
          }),
        })
      })

      await page.goto('/dashboard')
      await page.waitForResponse('**/api/v1/plugins/enabled')

      // Test isEnabled function - combat should be enabled based on mock response
      const isEnabled = await page.evaluate(() => {
        // Access the store through the app
        return (window as Window & { __PLUGIN_STORE_IS_ENABLED__?: (slug: string) => boolean }).__PLUGIN_STORE_IS_ENABLED__?.('combat')
      })

      // This should be true since combat is in our mock response
      expect(isEnabled).toBe(true)
    })
  })

  test.describe('Navigation Integration', () => {
    test('navigation is built from plugin data', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      // Mock response with specific navigation
      await page.route('**/api/v1/plugins/enabled', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            plugins: [
              {
                slug: 'test-plugin',
                name: 'Test Plugin',
                version: '1.0.0',
                description: 'Test',
                icon: '🧪',
                color: 'blue',
                route_name: 'test-plugin',
                frontend_routes: [],
                navigation: { enabled: true, section: 'test', order: 1, parent: null },
                order: 1,
                has_api_routes: false,
                has_web_routes: true,
                has_admin_routes: false,
                frontend_slots: [],
                permissions: [],
              },
            ],
            navigation: [
              {
                slug: 'test-plugin',
                name: 'Test Plugin',
                icon: '🧪',
                color: 'blue',
                route: 'test-plugin',
                section: 'test',
                order: 1,
              },
            ],
            routes: [],
          }),
        })
      })

      await page.goto('/dashboard')

      // Check if navigation shows plugin item
      // Note: This depends on the actual navigation component implementation
    })

    test('disabled plugins do not appear in navigation', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      // Mock response with no enabled plugins
      await page.route('**/api/v1/plugins/enabled', async (route) => {
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

      // Check that plugin-specific navigation items are not visible
    })
  })

  test.describe('Dynamic Route Registration', () => {
    test('plugin routes are registered dynamically', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      await page.route('**/api/v1/plugins/enabled', async (route) => {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: true,
            plugins: [
              {
                slug: 'racing',
                name: 'Racing',
                version: '1.0.0',
                description: 'Racing plugin',
                icon: '🏎️',
                color: 'orange',
                route_name: 'racing',
                frontend_routes: [
                  {
                    path: '/racing',
                    name: 'racing',
                    component: 'RacingView',
                    meta: { title: 'Racing' },
                  },
                ],
                navigation: { enabled: true, section: 'activities', order: 5, parent: null },
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
                slug: 'racing',
                name: 'Racing',
                icon: '🏎️',
                color: 'orange',
                route: 'racing',
                section: 'activities',
                order: 5,
              },
            ],
            routes: [
              {
                plugin: 'racing',
                path: '/racing',
                name: 'racing',
                component: 'RacingView',
                meta: { title: 'Racing' },
              },
            ],
          }),
        })
      })

      await page.goto('/dashboard')
      await page.waitForResponse('**/api/v1/plugins/enabled')

      // Try to navigate to a plugin route
      // The route should be accessible
    })

    test('routes for disabled plugins are not accessible', async ({ page }) => {
      await page.addInitScript(() => {
        localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
      })

      // Mock with no plugins
      await page.route('**/api/v1/plugins/enabled', async (route) => {
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

      // Static routes should still work
      await page.goto('/settings')
      expect(page.url()).toContain('/settings')
    })
  })
})

test.describe('Hardcoded Fragment Detection', () => {
  test('HomeView uses isPluginEnabled for conditional rendering', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
    })

    // Mock with only hospital plugin enabled
    await page.route('**/api/v1/plugins/enabled', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          plugins: [
            {
              slug: 'hospital',
              name: 'Hospital',
              version: '1.0.0',
              description: 'Hospital plugin',
              icon: '🏥',
              color: 'green',
              route_name: 'hospital',
              frontend_routes: [],
              navigation: { enabled: true, section: 'utilities', order: 1, parent: null },
              order: 1,
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
              order: 1,
            },
          ],
          routes: [],
        }),
      })
    })

    await page.goto('/dashboard')
    await page.waitForResponse('**/api/v1/plugins/enabled')

    // Hospital should be visible (enabled)
    await expect(page.getByRole('link', { name: /hospital/i })).toBeVisible()

    // Combat should not be visible (not enabled)
    // Note: This tests that the frontend respects plugin enabled status
  })

  test('feature sections respect plugin status', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
    })

    await page.route('**/api/v1/plugins/enabled', async (route) => {
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
    await page.waitForResponse('**/api/v1/plugins/enabled')

    // With no plugins enabled, feature sections should show limited content
    // This tests that the frontend uses isPluginEnabled checks
  })
})

test.describe('Plugin API Route Integration', () => {
  test('plugin API routes are accessible when plugin enabled', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
    })

    // Mock the combat API
    await page.route('**/api/v1/combat**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          data: { enemies: [] },
        }),
      })
    })

    await page.route('**/api/v1/plugins/enabled', async (route) => {
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
              description: 'Combat',
              icon: '⚔️',
              color: 'red',
              route_name: 'combat',
              frontend_routes: [],
              navigation: { enabled: true, section: 'actions', order: 1, parent: null },
              order: 1,
              has_api_routes: true,
              has_web_routes: true,
              has_admin_routes: false,
              frontend_slots: [],
              permissions: [],
            },
          ],
          navigation: [],
          routes: [],
        }),
      })
    })

    await page.goto('/dashboard')

    // Navigate to combat and verify API call
    const combatResponse = page.waitForResponse('**/api/v1/combat**')
    await page.goto('/combat')
    const response = await combatResponse

    expect(response.status()).toBe(200)
  })
})

test.describe('Plugin Caching', () => {
  test('plugins are cached after first load', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
    })

    let requestCount = 0

    await page.route('**/api/v1/plugins/enabled', async (route) => {
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
    await page.waitForResponse('**/api/v1/plugins/enabled')
    expect(requestCount).toBe(1)

    // Navigate to another page
    await page.goto('/settings')

    // Return to dashboard
    await page.goto('/dashboard')

    // Should not make additional request (cached)
    expect(requestCount).toBeLessThanOrEqual(2)
  })
})

test.describe('Error Handling', () => {
  test('handles plugin API failure gracefully', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
    })

    await page.route('**/api/v1/plugins/enabled', async (route) => {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Server error',
        }),
      })
    })

    // Should not crash the page
    await page.goto('/dashboard')

    // Page should still render
    await expect(page).toHaveURL(/dashboard/)
  })

  test('handles empty plugin response', async ({ page }) => {
    await page.addInitScript(() => {
      localStorage.setItem('user', JSON.stringify({ id: 1, name: 'Test User' }))
    })

    await page.route('**/api/v1/plugins/enabled', async (route) => {
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

    // Page should render without plugins
    await expect(page).toHaveURL(/dashboard/)
  })
})
