import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import RegisterView from '@/views/RegisterView.vue'
import { useAuthStore } from '@/stores/auth'

// ─── Router setup ────────────────────────────────────────────────────────────

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/register', component: RegisterView },
      { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/login', component: { template: '<div>Login</div>' } },
    ],
  })
}

// ─── Mount helper ────────────────────────────────────────────────────────────

async function mountRegister() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  await router.push('/register')
  await router.isReady()

  const wrapper = mount(RegisterView, {
    global: {
      plugins: [pinia, router],
    },
  })

  const authStore = useAuthStore()
  return { wrapper, authStore, router }
}

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('RegisterView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── Rendering ──────────────────────────────────────────────────────────────

  describe('rendering', () => {
    it('renders the username input', async () => {
      const { wrapper } = await mountRegister()
      const input = wrapper.find('input[placeholder="Username"]')
      expect(input.exists()).toBe(true)
    })

    it('renders the email input', async () => {
      const { wrapper } = await mountRegister()
      const input = wrapper.find('input[type="email"]')
      expect(input.exists()).toBe(true)
    })

    it('renders the password input', async () => {
      const { wrapper } = await mountRegister()
      const inputs = wrapper.findAll('input[type="password"]')
      expect(inputs.length).toBeGreaterThanOrEqual(1)
    })

    it('renders the confirm password input', async () => {
      const { wrapper } = await mountRegister()
      const inputs = wrapper.findAll('input[type="password"]')
      expect(inputs.length).toBe(2)
    })

    it('renders the submit button', async () => {
      const { wrapper } = await mountRegister()
      expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
    })

    it('shows "Create Account" text on the submit button by default', async () => {
      const { wrapper } = await mountRegister()
      expect(wrapper.find('button[type="submit"]').text()).toContain('Create Account')
    })

    it('renders a link to /login', async () => {
      const { wrapper } = await mountRegister()
      const link = wrapper.find('a[href="/login"]')
      expect(link.exists()).toBe(true)
    })

    it('does not show an error message initially', async () => {
      const { wrapper } = await mountRegister()
      expect(wrapper.find('.error-message').exists()).toBe(false)
    })

    it('renders the page title "Create Account"', async () => {
      const { wrapper } = await mountRegister()
      expect(wrapper.text()).toContain('Create Account')
    })
  })

  // ── Form interaction ───────────────────────────────────────────────────────

  describe('form interaction', () => {
    it('binds username input', async () => {
      const { wrapper } = await mountRegister()
      const input = wrapper.find('input[placeholder="Username"]')
      await input.setValue('newuser')
      expect(input.element.value).toBe('newuser')
    })

    it('binds email input', async () => {
      const { wrapper } = await mountRegister()
      const input = wrapper.find('input[type="email"]')
      await input.setValue('new@example.com')
      expect(input.element.value).toBe('new@example.com')
    })

    it('binds password input', async () => {
      const { wrapper } = await mountRegister()
      const inputs = wrapper.findAll('input[type="password"]')
      await inputs[0].setValue('password123')
      expect(inputs[0].element.value).toBe('password123')
    })

    it('binds confirm password input', async () => {
      const { wrapper } = await mountRegister()
      const inputs = wrapper.findAll('input[type="password"]')
      await inputs[1].setValue('password123')
      expect(inputs[1].element.value).toBe('password123')
    })
  })

  // ── Submission ─────────────────────────────────────────────────────────────

  describe('form submission', () => {
    async function fillAndSubmit(wrapper, authStore, overrides = {}) {
      authStore.register = vi.fn().mockResolvedValue(overrides.success ?? true)

      const fields = {
        username: 'newuser',
        email: 'new@example.com',
        password: 'password123',
        password_confirmation: 'password123',
        ...overrides.fields,
      }

      await wrapper.find('input[placeholder="Username"]').setValue(fields.username)
      await wrapper.find('input[type="email"]').setValue(fields.email)
      const passwordInputs = wrapper.findAll('input[type="password"]')
      await passwordInputs[0].setValue(fields.password)
      await passwordInputs[1].setValue(fields.password_confirmation)
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()
    }

    it('calls authStore.register with all form fields on submit', async () => {
      const { wrapper, authStore } = await mountRegister()
      await fillAndSubmit(wrapper, authStore)

      expect(authStore.register).toHaveBeenCalledWith({
        username: 'newuser',
        email: 'new@example.com',
        password: 'password123',
        password_confirmation: 'password123',
      })
    })

    it('redirects to /dashboard on successful registration', async () => {
      const { wrapper, authStore, router } = await mountRegister()
      await fillAndSubmit(wrapper, authStore, { success: true })

      expect(router.currentRoute.value.path).toBe('/dashboard')
    })

    it('does not redirect on failed registration', async () => {
      const { wrapper, authStore, router } = await mountRegister()
      await fillAndSubmit(wrapper, authStore, { success: false })

      expect(router.currentRoute.value.path).toBe('/register')
    })
  })

  // ── Loading state ──────────────────────────────────────────────────────────

  describe('loading state', () => {
    it('disables the submit button while loading', async () => {
      const { wrapper, authStore } = await mountRegister()
      authStore.loading = true
      await flushPromises()

      const btn = wrapper.find('button[type="submit"]')
      expect(btn.attributes('disabled')).toBeDefined()
    })

    it('shows "Creating account..." text while loading', async () => {
      const { wrapper, authStore } = await mountRegister()
      authStore.loading = true
      await flushPromises()

      expect(wrapper.find('button[type="submit"]').text()).toContain('Creating account...')
    })

    it('enables the submit button when not loading', async () => {
      const { wrapper, authStore } = await mountRegister()
      authStore.loading = false
      await flushPromises()

      const btn = wrapper.find('button[type="submit"]')
      expect(btn.attributes('disabled')).toBeUndefined()
    })
  })

  // ── Error display ──────────────────────────────────────────────────────────

  describe('error display', () => {
    it('shows error message when authStore.error is set', async () => {
      const { wrapper, authStore } = await mountRegister()
      authStore.error = 'Email already taken'
      await flushPromises()

      const errorEl = wrapper.find('.error-message')
      expect(errorEl.exists()).toBe(true)
      expect(errorEl.text()).toContain('Email already taken')
    })

    it('hides error message when authStore.error is null', async () => {
      const { wrapper, authStore } = await mountRegister()
      authStore.error = null
      await flushPromises()

      expect(wrapper.find('.error-message').exists()).toBe(false)
    })

    it('shows a new error after a failed registration attempt', async () => {
      const { wrapper, authStore } = await mountRegister()
      authStore.register = vi.fn().mockResolvedValue(false)
      authStore.error = 'Username already taken'
      await flushPromises()

      expect(wrapper.find('.error-message').text()).toContain('Username already taken')
    })
  })

  // ── Input validation attributes ────────────────────────────────────────────

  describe('input validation attributes', () => {
    it('password input has minlength="8"', async () => {
      const { wrapper } = await mountRegister()
      const passwordInput = wrapper.findAll('input[type="password"]')[0]
      expect(passwordInput.attributes('minlength')).toBe('8')
    })

    it('all required fields have the required attribute', async () => {
      const { wrapper } = await mountRegister()
      const usernameInput = wrapper.find('input[placeholder="Username"]')
      const emailInput = wrapper.find('input[type="email"]')
      const passwordInputs = wrapper.findAll('input[type="password"]')

      expect(usernameInput.attributes('required')).toBeDefined()
      expect(emailInput.attributes('required')).toBeDefined()
      expect(passwordInputs[0].attributes('required')).toBeDefined()
      expect(passwordInputs[1].attributes('required')).toBeDefined()
    })
  })
})
