import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api.ts'
import type { User, LoginCredentials, RegisterData } from '@/types/user'

// Re-export types for backward compatibility
export type { User, LoginCredentials, RegisterData } from '@/types/user'

/**
 * Auth store - manages authentication state and actions
 */
export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)

  // Computed
  const isAuthenticated = computed(() => !!user.value)

  /**
   * Login user with credentials
   * Uses JSON POST to Laravel API v1 endpoint
   */
  async function login(credentials: LoginCredentials): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      // Laravel API uses POST /api/v1/login with JSON body
      // Response: { user: UserResource, token: string } or { two_factor_required: true, challenge_token: string }
      const response = await api.post<{ user: User; token?: string; two_factor_required?: boolean; challenge_token?: string }>(
        '/api/v1/login',
        {
          login: credentials.login || credentials.email,
          password: credentials.password
        }
      )

      const data = response.data

      // Handle 2FA requirement
      if (data.two_factor_required) {
        // Store challenge token for 2FA verification
        sessionStorage.setItem('2fa_challenge', data.challenge_token || '')
        error.value = 'Two-factor authentication required'
        // Note: The calling code should handle redirect to 2FA page
        return false
      }

      if (data.user) {
        user.value = data.user
        localStorage.setItem('user', JSON.stringify(user.value))
        // Store token if provided (for Sanctum token auth)
        if (data.token) {
          localStorage.setItem('auth_token', data.token)
        }
        return true
      } else {
        error.value = 'Login failed'
        return false
      }
    } catch (err) {
      const axiosError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      // Handle Laravel validation errors
      const validationErrors = axiosError.response?.data?.errors
      if (validationErrors) {
        const firstError = Object.values(validationErrors)[0]
        error.value = Array.isArray(firstError) ? firstError[0] ?? 'Login failed' : 'Login failed'
      } else {
        error.value = axiosError.response?.data?.message ?? 'Login failed'
      }
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Register a new user
   * Uses JSON POST to Laravel API v1 endpoint
   */
  async function register(userData: RegisterData): Promise<boolean> {
    loading.value = true
    error.value = null

    try {
      // Laravel API uses POST /api/v1/register with JSON body
      // Response: { user: UserResource, token: string }
      const response = await api.post<{ user: User; token: string }>(
        '/api/v1/register',
        {
          username: userData.username,
          email: userData.email,
          password: userData.password,
          password_confirmation: userData.password_confirmation
        }
      )

      const data = response.data

      if (data.user) {
        user.value = data.user
        localStorage.setItem('user', JSON.stringify(user.value))
        // Store token if provided (for Sanctum token auth)
        if (data.token) {
          localStorage.setItem('auth_token', data.token)
        }
        return true
      } else {
        error.value = 'Registration failed'
        return false
      }
    } catch (err) {
      const axiosError = err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }
      // Handle Laravel validation errors
      const validationErrors = axiosError.response?.data?.errors
      if (validationErrors) {
        const firstError = Object.values(validationErrors)[0]
        error.value = Array.isArray(firstError) ? firstError[0] ?? 'Registration failed' : 'Registration failed'
      } else {
        error.value = axiosError.response?.data?.message ?? 'Registration failed'
      }
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Logout the current user
   */
  async function logout(): Promise<void> {
    try {
      await api.post('/api/v1/logout')
    } catch {
      // Proceed with local logout regardless of API response
    } finally {
      user.value = null
      localStorage.removeItem('user')
      localStorage.removeItem('auth_token')
    }
  }

  /**
   * Fetch the current authenticated user
   * Silently handles errors when not authenticated
   */
  async function fetchUser(): Promise<void> {
    // Skip API call if no auth token stored
    const token = localStorage.getItem('auth_token')
    if (!token) {
      user.value = null
      return
    }

    try {
      // Backend returns UserResource directly
      const response = await api.get<User>('/api/v1/user')
      const userData = response.data

      if (userData && userData.id) {
        user.value = userData
        localStorage.setItem('user', JSON.stringify(user.value))
      } else {
        user.value = null
        localStorage.removeItem('user')
        localStorage.removeItem('auth_token')
      }
    } catch {
      // Silently handle errors (expected when not authenticated)
      user.value = null
      localStorage.removeItem('user')
      localStorage.removeItem('auth_token')
    }
  }

  /**
   * Initialize auth state from localStorage and verify session
   */
  async function init(): Promise<void> {
    const storedUser = localStorage.getItem('user')
    if (storedUser) {
      try {
        user.value = JSON.parse(storedUser) as User
        // Verify the session is still active
        await fetchUser()
      } catch {
        user.value = null
        localStorage.removeItem('user')
      }
    }
  }

  /**
   * Clear any error message
   */
  function clearError(): void {
    error.value = null
  }

  return {
    // State
    user,
    loading,
    error,
    // Computed
    isAuthenticated,
    // Actions
    login,
    register,
    logout,
    fetchUser,
    init,
    clearError
  }
})
