import { test, expect, type Page } from '@playwright/test'

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Navigate to the login page and wait for it to be ready. */
async function gotoLogin(page: Page) {
  await page.goto('/login')
  await expect(page.locator('#email')).toBeVisible()
}

/** Navigate to the register page and wait for it to be ready. */
async function gotoRegister(page: Page) {
  await page.goto('/register')
  await expect(page.locator('input[placeholder="Username"]')).toBeVisible()
}

/** Fill and submit the login form. */
async function submitLogin(page: Page, email: string, password: string) {
  await page.fill('#email', email)
  await page.fill('#password', password)
  await page.click('button[type="submit"]')
}

/** Fill and submit the registration form. */
async function submitRegister(
  page: Page,
  username: string,
  email: string,
  password: string,
  confirmPassword: string,
) {
  await page.fill('input[placeholder="Username"]', username)
  await page.fill('input[type="email"]', email)
  const passwordInputs = page.locator('input[type="password"]')
  await passwordInputs.nth(0).fill(password)
  await passwordInputs.nth(1).fill(confirmPassword)
  await page.click('button[type="submit"]')
}

// ─── Login Page ───────────────────────────────────────────────────────────────

test.describe('Login Page', () => {
  test.beforeEach(async ({ page }) => {
    // Clear any stored session before each test
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())
  })

  test('displays the login form', async ({ page }) => {
    await gotoLogin(page)

    await expect(page.locator('#email')).toBeVisible()
    await expect(page.locator('#password')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toContainText('Sign in')
  })

  test('displays the page branding', async ({ page }) => {
    await gotoLogin(page)
    await expect(page.getByText('Welcome Back')).toBeVisible()
  })

  test('has a link to the registration page', async ({ page }) => {
    await gotoLogin(page)
    const registerLink = page.locator('a[href="/register"]')
    await expect(registerLink).toBeVisible()
  })

  test('has a forgot password link', async ({ page }) => {
    await gotoLogin(page)
    const forgotLink = page.locator('a[href="/forgot-password"]')
    await expect(forgotLink).toBeVisible()
  })

  test('navigates to register page when clicking the register link', async ({ page }) => {
    await gotoLogin(page)
    await page.click('a[href="/register"]')
    await expect(page).toHaveURL(/\/register/)
  })

  test('navigates to forgot-password page when clicking the link', async ({ page }) => {
    await gotoLogin(page)
    await page.click('a[href="/forgot-password"]')
    await expect(page).toHaveURL(/\/forgot-password/)
  })

  test('shows loading state while submitting', async ({ page }) => {
    await gotoLogin(page)

    // Intercept the login API call and delay it so we can observe the loading state
    await page.route('**/api/v1/login**', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 500))
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: false, message: 'Invalid credentials' }),
      })
    })

    await page.fill('#email', 'test@example.com')
    await page.fill('#password', 'password123')
    await page.click('button[type="submit"]')

    // Button should be disabled and show loading text
    await expect(page.locator('button[type="submit"]')).toBeDisabled()
    await expect(page.locator('button[type="submit"]')).toContainText('Signing in...')
  })

  test('shows error message on failed login', async ({ page }) => {
    await gotoLogin(page)

    // Mock a failed login response (Laravel validation error format)
    await page.route('**/api/v1/login**', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The provided credentials are incorrect.',
          errors: { login: ['The provided credentials are incorrect.'] }
        }),
      }),
    )

    await submitLogin(page, 'wrong@example.com', 'wrongpassword')

    await expect(page.locator('.error-message')).toBeVisible()
    // Accept either specific backend error or generic fallback
    const errorText = await page.locator('.error-message').textContent()
    expect(errorText).toMatch(/credentials are incorrect|login failed/i)
  })

  test('successful login clears error state and completes', async ({ page }) => {
    await gotoLogin(page)

    // Mock a successful login response (Laravel format: { user, token })
    await page.route('**/api/v1/login**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          user: { id: 1, username: 'testuser', email: 'test@example.com' },
          token: 'test-auth-token-12345'
        }),
      }),
    )

    // Fill and submit the form
    await page.fill('#email', 'test@example.com')
    await page.fill('#password', 'password123')
    await page.click('button[type="submit"]')

    // Wait for loading to complete (button should be enabled)
    await expect(page.locator('button[type="submit"]')).toBeEnabled({ timeout: 5000 })

    // No error message should be shown
    await expect(page.locator('.error-message')).toBeHidden()

    // User should be stored in localStorage
    const user = await page.evaluate(() => localStorage.getItem('user'))
    expect(user).toContain('testuser')
  })

  test('does not redirect on failed login', async ({ page }) => {
    await gotoLogin(page)

    await page.route('**/api/v1/login**', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Invalid credentials',
          errors: { login: ['Invalid credentials'] }
        }),
      }),
    )

    await submitLogin(page, 'bad@example.com', 'wrongpassword')

    await expect(page).toHaveURL(/\/login/)
  })

  test('email field accepts text input', async ({ page }) => {
    await gotoLogin(page)
    await page.fill('#email', 'user@example.com')
    await expect(page.locator('#email')).toHaveValue('user@example.com')
  })

  test('password field masks input', async ({ page }) => {
    await gotoLogin(page)
    const passwordInput = page.locator('#password')
    await expect(passwordInput).toHaveAttribute('type', 'password')
  })

  test('remember me checkbox is present and toggleable', async ({ page }) => {
    await gotoLogin(page)
    const checkbox = page.locator('#remember')
    await expect(checkbox).toBeVisible()
    await checkbox.check()
    await expect(checkbox).toBeChecked()
    await checkbox.uncheck()
    await expect(checkbox).not.toBeChecked()
  })
})

// ─── Register Page ────────────────────────────────────────────────────────────

test.describe('Register Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/register')
    await page.evaluate(() => localStorage.clear())
  })

  test('displays the registration form', async ({ page }) => {
    await gotoRegister(page)

    await expect(page.locator('input[placeholder="Username"]')).toBeVisible()
    await expect(page.locator('input[type="email"]')).toBeVisible()
    await expect(page.locator('input[type="password"]').first()).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toBeVisible()
    await expect(page.locator('button[type="submit"]')).toContainText('Create Account')
  })

  test('displays the page title', async ({ page }) => {
    await gotoRegister(page)
    await expect(page.getByRole('heading', { name: 'Create Account' })).toBeVisible()
  })

  test('has a link back to the login page', async ({ page }) => {
    await gotoRegister(page)
    const loginLink = page.locator('a[href="/login"]')
    await expect(loginLink).toBeVisible()
  })

  test('navigates to login page when clicking the sign-in link', async ({ page }) => {
    await gotoRegister(page)
    await page.click('a[href="/login"]')
    await expect(page).toHaveURL(/\/login/)
  })

  test('shows loading state while submitting', async ({ page }) => {
    await gotoRegister(page)

    await page.route('**/api/v1/register**', async (route) => {
      await new Promise((resolve) => setTimeout(resolve, 500))
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The email has already been taken.',
          errors: { email: ['The email has already been taken.'] }
        }),
      })
    })

    await submitRegister(page, 'newuser', 'new@example.com', 'password123', 'password123')

    await expect(page.locator('button[type="submit"]')).toBeDisabled()
    await expect(page.locator('button[type="submit"]')).toContainText('Creating account...')
  })

  test('shows error message on failed registration', async ({ page }) => {
    await gotoRegister(page)

    await page.route('**/api/v1/register**', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'The email has already been taken.',
          errors: { email: ['The email has already been taken.'] }
        }),
      }),
    )

    await submitRegister(page, 'existinguser', 'taken@example.com', 'password123', 'password123')

    await expect(page.locator('.error-message')).toBeVisible()
    // Accept either specific backend error or generic fallback
    const errorText = await page.locator('.error-message').textContent()
    expect(errorText).toMatch(/email has already been taken|registration failed/i)
  })

  test('successful registration completes without errors', async ({ page }) => {
    await gotoRegister(page)

    // Mock the register endpoint (Laravel format: { user, token })
    await page.route('**/api/v1/register**', (route) =>
      route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          user: { id: 2, username: 'newuser', email: 'new@example.com' },
          token: 'test-auth-token-67890'
        }),
      }),
    )

    // Fill and submit the form
    await page.fill('input[placeholder="Username"]', 'newuser')
    await page.fill('input[type="email"]', 'new@example.com')
    const passwordInputs = page.locator('input[type="password"]')
    await passwordInputs.nth(0).fill('password123')
    await passwordInputs.nth(1).fill('password123')
    await page.click('button[type="submit"]')

    // Wait for loading to complete
    await expect(page.locator('button[type="submit"]')).toBeEnabled({ timeout: 5000 })

    // No error message should be shown
    await expect(page.locator('.error-message')).toBeHidden()

    // User should be stored in localStorage
    const user = await page.evaluate(() => localStorage.getItem('user'))
    expect(user).toContain('newuser')
  })

  test('does not redirect on failed registration', async ({ page }) => {
    await gotoRegister(page)

    await page.route('**/api/v1/register**', (route) =>
      route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Registration failed',
          errors: { email: ['Registration failed'] }
        }),
      }),
    )

    await submitRegister(page, 'newuser', 'new@example.com', 'password123', 'password123')

    await expect(page).toHaveURL(/\/register/)
  })

  test('password fields mask input', async ({ page }) => {
    await gotoRegister(page)
    const passwordInputs = page.locator('input[type="password"]')
    await expect(passwordInputs).toHaveCount(2)
  })

  test('all form fields accept input', async ({ page }) => {
    await gotoRegister(page)

    await page.fill('input[placeholder="Username"]', 'testuser')
    await page.fill('input[type="email"]', 'test@example.com')
    const passwordInputs = page.locator('input[type="password"]')
    await passwordInputs.nth(0).fill('password123')
    await passwordInputs.nth(1).fill('password123')

    await expect(page.locator('input[placeholder="Username"]')).toHaveValue('testuser')
    await expect(page.locator('input[type="email"]')).toHaveValue('test@example.com')
    await expect(passwordInputs.nth(0)).toHaveValue('password123')
    await expect(passwordInputs.nth(1)).toHaveValue('password123')
  })
})

// ─── Auth Flow Navigation ─────────────────────────────────────────────────────

test.describe('Auth flow navigation', () => {
  test('unauthenticated user is redirected to /login when visiting /dashboard', async ({
    page,
  }) => {
    // Ensure no auth token in localStorage
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())

    await page.goto('/dashboard')
    await expect(page).toHaveURL(/\/login/)
  })

  test('/ redirects to /dashboard (or /login if unauthenticated)', async ({ page }) => {
    await page.goto('/login')
    await page.evaluate(() => localStorage.clear())

    await page.goto('/')
    // Without auth, should end up at /login (via /dashboard redirect + auth guard)
    await expect(page).toHaveURL(/\/(login|dashboard)/)
  })

  test('login page is accessible without authentication', async ({ page }) => {
    await page.goto('/login')
    await expect(page).toHaveURL(/\/login/)
    await expect(page.locator('#email')).toBeVisible()
  })

  test('register page is accessible without authentication', async ({ page }) => {
    await page.goto('/register')
    await expect(page).toHaveURL(/\/register/)
    await expect(page.locator('input[placeholder="Username"]')).toBeVisible()
  })
})
