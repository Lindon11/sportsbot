import { test, expect } from '@playwright/test'

/**
 * Integration Tests for LaravelCP Frontend-Backend Communication
 *
 * These tests require a running backend server.
 * Run with: npm run test:e2e -- --grep "Integration"
 *
 * Prerequisites:
 * 1. Backend running on http://localhost:8001 (or via Docker)
 * 2. Database migrated and seeded
 * 3. Frontend dev server running on http://localhost:5175
 */

test.describe.serial('Integration: Auth Flow', () => {
  const testUser = {
    username: `testuser_${Date.now()}`,
    email: `test_${Date.now()}@example.com`,
    password: 'TestPassword123!'
  }

  test.describe('Registration', () => {
    test('can register a new user', async ({ page }) => {
      await page.goto('/register')
      await page.evaluate(() => localStorage.clear())

      // Fill registration form
      await page.fill('input[placeholder="Username"]', testUser.username)
      await page.fill('input[type="email"]', testUser.email)
      const passwordInputs = page.locator('input[type="password"]')
      await passwordInputs.nth(0).fill(testUser.password)
      await passwordInputs.nth(1).fill(testUser.password)

      // Submit form
      await page.click('button[type="submit"]')

      // Wait for redirect to dashboard (successful registration)
      await page.waitForURL(/\/dashboard/, { timeout: 10000 })

      // Verify user is logged in
      const user = await page.evaluate(() => localStorage.getItem('user'))
      expect(user).toContain(testUser.username)
    })
  })

  test.describe('Login/Logout', () => {
    test.beforeEach(async ({ page }) => {
      await page.goto('/login')
      await page.evaluate(() => localStorage.clear())
    })

    test('can login with registered user', async ({ page }) => {
      // Fill login form
      await page.fill('#email', testUser.email)
      await page.fill('#password', testUser.password)

      // Submit form
      await page.click('button[type="submit"]')

      // Wait for redirect to dashboard
      await page.waitForURL(/\/dashboard/, { timeout: 10000 })

      // Verify user is stored
      const user = await page.evaluate(() => localStorage.getItem('user'))
      expect(user).toContain(testUser.username)
    })

    test('can login with username instead of email', async ({ page }) => {
      await page.fill('#email', testUser.username)
      await page.fill('#password', testUser.password)
      await page.click('button[type="submit"]')

      await page.waitForURL(/\/dashboard/, { timeout: 10000 })

      const user = await page.evaluate(() => localStorage.getItem('user'))
      expect(user).toContain(testUser.username)
    })

    test('shows error for invalid credentials', async ({ page }) => {
      await page.fill('#email', 'wrong@example.com')
      await page.fill('#password', 'wrongpassword')
      await page.click('button[type="submit"]')

      // Should stay on login page
      await page.waitForURL(/\/login/, { timeout: 5000 })

      // Should show error message
      await expect(page.locator('.error-message')).toBeVisible()
    })

    test('can logout', async ({ page }) => {
      // Login first
      await page.fill('#email', testUser.email)
      await page.fill('#password', testUser.password)
      await page.click('button[type="submit"]')
      await page.waitForURL(/\/dashboard/, { timeout: 10000 })

      // Clear localStorage to simulate logout
      await page.evaluate(() => {
        localStorage.removeItem('user')
        localStorage.removeItem('auth_token')
      })

      // Navigate to a protected route - should redirect to login
      await page.goto('/dashboard')
      await expect(page).toHaveURL(/\/login/, { timeout: 5000 })
    })
  })
})

test.describe('Integration: Protected Routes', () => {
  test('unauthenticated user cannot access dashboard', async ({ page }) => {
    // Navigate to a valid page first to access localStorage
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())
    await page.goto('/dashboard')

    // Should redirect to login
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 })
  })

  test('authenticated user can access dashboard', async ({ page }) => {
    // Navigate to a valid page first to access localStorage
    await page.goto('/login')
    // Mock authenticated state
    await page.evaluate(() => {
      localStorage.setItem('user', JSON.stringify({
        id: 1,
        username: 'testuser',
        email: 'test@example.com'
      }))
    })

    await page.goto('/dashboard')

    // Should stay on dashboard
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 5000 })
  })
})

test.describe('Integration: Error Handling', () => {
  test('handles network errors gracefully', async ({ page }) => {
    // Simulate offline
    await page.context().setOffline(true)

    await page.goto('/login')
    await page.fill('#email', 'test@example.com')
    await page.fill('#password', 'password123')
    await page.click('button[type="submit"]')

    // Should show error (not crash)
    await expect(page.locator('.error-message')).toBeVisible()

    await page.context().setOffline(false)
  })

  test('handles 401 responses by redirecting to login', async ({ page }) => {
    // Navigate to a valid page first to access localStorage
    await page.goto('/login')
    // Set invalid user data
    await page.evaluate(() => {
      localStorage.setItem('user', JSON.stringify({ id: 999, username: 'invalid' }))
    })

    // Mock API returning 401
    await page.route('**/api/v1/**', (route) => {
      route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Unauthenticated' })
      })
    })

    await page.goto('/dashboard')

    // Should redirect to login
    await expect(page).toHaveURL(/\/login/, { timeout: 5000 })
  })
})
