import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import LoginView from '@/views/LoginView.vue'
import { useAuthStore } from '@/stores/auth'

// ─── Router setup ────────────────────────────────────────────────────────────

function makeRouter() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', component: LoginView },
      { path: '/dashboard', component: { template: '<div>Dashboard</div>' } },
      { path: '/register', component: { template: '<div>Register</div>' } },
      { path: '/forgot-password', component: { template: '<div>Forgot</div>' } },
    ],
  })
}

// ─── Mount helper ────────────────────────────────────────────────────────────

async function mountLogin() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const router = makeRouter()
  await router.push('/login')
  await router.isReady()

  const wrapper = mount(LoginView, {
    global: {
      plugins: [pinia, router],
    },
  })

  const authStore = useAuthStore()
  return { wrapper, authStore, router }
}

// ─── Tests ───────────────────────────────────────────────────────────────────

describe('LoginView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  // ── Rendering ──────────────────────────────────────────────────────────────

  describe('rendering', () => {
    it('renders the email input', async () => {
      const { wrapper } = await mountLogin()
      expect(wrapper.find('#email').exists()).toBe(true)
    })

    it('renders the password input', async () => {
      const { wrapper } = await mountLogin()
      expect(wrapper.find('#password').exists()).toBe(true)
    })

    it('renders the submit button', async () => {
      const { wrapper } = await mountLogin()
      expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
    })

    it('shows "Sign in" text on the submit button by default', async () => {
      const { wrapper } = await mountLogin()
      expect(wrapper.find('button[type="submit"]').text()).toContain('Sign in')
    })

    it('renders a link to /register', async () => {
      const { wrapper } = await mountLogin()
      const link = wrapper.find('a[href="/register"]')
      expect(link.exists()).toBe(true)
    })

    it('renders a link to /forgot-password', async () => {
      const { wrapper } = await mountLogin()
      const link = wrapper.find('a[href="/forgot-password"]')
      expect(link.exists()).toBe(true)
    })

    it('renders the remember-me checkbox', async () => {
      const { wrapper } = await mountLogin()
      expect(wrapper.find('#remember').exists()).toBe(true)
    })

    it('does not show an error message initially', async () => {
      const { wrapper } = await mountLogin()
      expect(wrapper.find('.error-message').exists()).toBe(false)
    })
  })

  // ── Form interaction ───────────────────────────────────────────────────────

  describe('form interaction', () => {
    it('binds email input to form.email', async () => {
      const { wrapper } = await mountLogin()
      const input = wrapper.find('#email')
      await input.setValue('user@example.com')
      expect(input.element.value).toBe('user@example.com')
    })

    it('binds password input to form.password', async () => {
      const { wrapper } = await mountLogin()
      const input = wrapper.find('#password')
      await input.setValue('mypassword')
      expect(input.element.value).toBe('mypassword')
    })

    it('binds remember-me checkbox', async () => {
      const { wrapper } = await mountLogin()
      const checkbox = wrapper.find('#remember')
      await checkbox.setValue(true)
      expect(checkbox.element.checked).toBe(true)
    })
  })

  // ── Submission ─────────────────────────────────────────────────────────────

  describe('form submission', () => {
    it('calls authStore.login with email and password on submit', async () => {
      const { wrapper, authStore } = await mountLogin()
      authStore.login = vi.fn().mockResolvedValue(true)

      await wrapper.find('#email').setValue('test@example.com')
      await wrapper.find('#password').setValue('password123')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(authStore.login).toHaveBeenCalledWith({
        login: 'test@example.com',
        password: 'password123',
      })
    })

    it('redirects to /dashboard on successful login', async () => {
      const { wrapper, authStore, router } = await mountLogin()
      authStore.login = vi.fn().mockResolvedValue(true)

      await wrapper.find('#email').setValue('test@example.com')
      await wrapper.find('#password').setValue('password123')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(router.currentRoute.value.path).toBe('/dashboard')
    })

    it('does not redirect on failed login', async () => {
      const { wrapper, authStore, router } = await mountLogin()
      authStore.login = vi.fn().mockResolvedValue(false)

      await wrapper.find('#email').setValue('bad@example.com')
      await wrapper.find('#password').setValue('wrong')
      await wrapper.find('form').trigger('submit.prevent')
      await flushPromises()

      expect(router.currentRoute.value.path).toBe('/login')
    })
  })

  // ── Loading state ──────────────────────────────────────────────────────────

  describe('loading state', () => {
    it('disables the submit button while loading', async () => {
      const { wrapper, authStore } = await mountLogin()
      authStore.loading = true
      await flushPromises()

      const btn = wrapper.find('button[type="submit"]')
      expect(btn.attributes('disabled')).toBeDefined()
    })

    it('shows "Signing in..." text while loading', async () => {
      const { wrapper, authStore } = await mountLogin()
      authStore.loading = true
      await flushPromises()

      expect(wrapper.find('button[type="submit"]').text()).toContain('Signing in...')
    })

    it('enables the submit button when not loading', async () => {
      const { wrapper, authStore } = await mountLogin()
      authStore.loading = false
      await flushPromises()

      const btn = wrapper.find('button[type="submit"]')
      expect(btn.attributes('disabled')).toBeUndefined()
    })
  })

  // ── Error display ──────────────────────────────────────────────────────────

  describe('error display', () => {
    it('shows error message when authStore.error is set', async () => {
      const { wrapper, authStore } = await mountLogin()
      authStore.error = 'Invalid credentials'
      await flushPromises()

      const errorEl = wrapper.find('.error-message')
      expect(errorEl.exists()).toBe(true)
      expect(errorEl.text()).toContain('Invalid credentials')
    })

    it('hides error message when authStore.error is null', async () => {
      const { wrapper, authStore } = await mountLogin()
      authStore.error = null
      await flushPromises()

      expect(wrapper.find('.error-message').exists()).toBe(false)
    })
  })
})
