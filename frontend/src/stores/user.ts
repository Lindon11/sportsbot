import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

/**
 * Core user profile data - no gaming-specific fields
 * Gaming stats can be provided by plugins via their own stores
 */
export interface UserProfile {
  id: number
  username: string
  name: string
  email: string
  avatar?: string
  bio?: string
  timezone?: string
  locale?: string
  createdAt?: string
  lastActive?: string
}

/**
 * User notification settings
 */
export interface UserNotificationSettings {
  emailNotifications: boolean
  pushNotifications: boolean
  soundEnabled: boolean
}

/**
 * User preferences
 */
export interface UserPreferences {
  theme: 'light' | 'dark' | 'system'
  language: string
  compactMode: boolean
}

/**
 * User store - manages core user profile data
 * This is the base store for the Core Web APP OS
 * Plugins can extend user data via their own stores (e.g., playerStore for gaming)
 */
export const useUserStore = defineStore('user', () => {
  // State
  const profile = ref<UserProfile | null>(null)
  const roles = ref<string[]>([])
  const permissions = ref<string[]>([])
  const notificationSettings = ref<UserNotificationSettings | null>(null)
  const preferences = ref<UserPreferences | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const lastFetched = ref<Date | null>(null)

  // Computed - Basic info
  const userId = computed(() => profile.value?.id ?? null)
  // Fallback to name if username is null/undefined/empty
  const username = computed(() => profile.value?.username || profile.value?.name || 'Guest')
  const displayName = computed(() => profile.value?.name || profile.value?.username || 'Guest')
  const email = computed(() => profile.value?.email ?? null)
  const avatar = computed(() => profile.value?.avatar ?? null)
  const bio = computed(() => profile.value?.bio ?? null)

  // Computed - Permissions
  const isAdmin = computed(() => roles.value.includes('admin') || roles.value.includes('super-admin'))
  const isModerator = computed(() => roles.value.includes('moderator') || isAdmin.value)
  const hasPermission = computed(() => (permission: string) => permissions.value.includes(permission))
  const hasRole = computed(() => (role: string) => roles.value.includes(role))

  // Computed - Status
  const isAuthenticated = computed(() => !!profile.value?.id)
  const isLoaded = computed(() => !!profile.value)

  /**
   * Transform API response to store format
   */
  function transformUserData(data: Record<string, unknown>): UserProfile {
    // Get username and name from API response
    const apiUsername = data.username as string | null | undefined
    const apiName = data.name as string | null | undefined

    // Username falls back to name if not provided
    const finalUsername = apiUsername || apiName || 'Guest'
    // Name falls back to username if not provided
    const finalName = apiName || apiUsername || 'Guest'

    return {
      id: data.id as number,
      username: finalUsername,
      name: finalName,
      email: (data.email as string) ?? '',
      avatar: (data.avatar as string) ?? (data.profile_photo_url as string) ?? undefined,
      bio: (data.bio as string) ?? undefined,
      timezone: (data.timezone as string) ?? undefined,
      locale: (data.locale as string) ?? undefined,
      createdAt: (data.created_at as string) ?? undefined,
      lastActive: (data.last_active as string) ?? undefined,
    }
  }

  /**
   * Fetch user profile from API
   */
  async function fetchProfile(): Promise<boolean> {
    if (loading.value) return false

    loading.value = true
    error.value = null

    try {
      const response = await api.get('/api/v1/user')
      const data = response.data as Record<string, unknown>

      // Transform and set profile
      profile.value = transformUserData(data)

      // Extract roles and permissions
      const rawRoles = data.roles as Array<string | { name: string }> | undefined
      if (rawRoles) {
        roles.value = rawRoles.map(r => typeof r === 'string' ? r : r.name)
      }

      const rawPermissions = data.permissions as Array<string | { name: string }> | undefined
      if (rawPermissions) {
        permissions.value = rawPermissions.map(p => typeof p === 'string' ? p : p.name)
      }

      lastFetched.value = new Date()
      return true
    } catch {
      error.value = 'Failed to fetch user profile'
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Update user profile
   */
  async function updateProfile(updates: Partial<Pick<UserProfile, 'name' | 'bio' | 'timezone' | 'locale'>>): Promise<boolean> {
    if (!profile.value) return false

    loading.value = true
    error.value = null

    try {
      const response = await api.patch('/api/v1/user/profile', updates)
      const data = response.data as Record<string, unknown>

      if (data.success !== false) {
        // Update local profile
        if (updates.name) profile.value.name = updates.name
        if (updates.bio !== undefined) profile.value.bio = updates.bio
        if (updates.timezone) profile.value.timezone = updates.timezone
        if (updates.locale) profile.value.locale = updates.locale
        return true
      }
      return false
    } catch {
      error.value = 'Failed to update profile'
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Update avatar
   */
  async function updateAvatar(file: File): Promise<boolean> {
    if (!profile.value) return false

    loading.value = true
    error.value = null

    try {
      const formData = new FormData()
      formData.append('avatar', file)

      const response = await api.post('/api/v1/user/avatar', formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      const data = response.data as { avatar?: string }

      if (data.avatar) {
        profile.value.avatar = data.avatar
        return true
      }
      return false
    } catch {
      error.value = 'Failed to update avatar'
      return false
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch notification settings
   */
  async function fetchNotificationSettings(): Promise<boolean> {
    try {
      const response = await api.get('/api/v1/user/notification-settings')
      notificationSettings.value = response.data as UserNotificationSettings
      return true
    } catch {
      return false
    }
  }

  /**
   * Update notification settings
   */
  async function updateNotificationSettings(settings: Partial<UserNotificationSettings>): Promise<boolean> {
    try {
      const response = await api.patch('/api/v1/user/notification-settings', settings)
      if (response.data) {
        notificationSettings.value = {
          ...notificationSettings.value,
          ...settings
        } as UserNotificationSettings
        return true
      }
      return false
    } catch {
      return false
    }
  }

  /**
   * Fetch user preferences
   */
  async function fetchPreferences(): Promise<boolean> {
    try {
      const response = await api.get('/api/v1/user/preferences')
      preferences.value = response.data as UserPreferences
      return true
    } catch {
      return false
    }
  }

  /**
   * Update user preferences
   */
  async function updatePreferences(prefs: Partial<UserPreferences>): Promise<boolean> {
    try {
      const response = await api.patch('/api/v1/user/preferences', prefs)
      if (response.data) {
        preferences.value = {
          ...preferences.value,
          ...prefs
        } as UserPreferences
        return true
      }
      return false
    } catch {
      return false
    }
  }

  /**
   * Check if user has a specific permission
   */
  function can(permission: string): boolean {
    return permissions.value.includes(permission)
  }

  /**
   * Check if user has a specific role
   */
  function has(role: string): boolean {
    return roles.value.includes(role)
  }

  /**
   * Clear all user data
   */
  function clearUser(): void {
    profile.value = null
    roles.value = []
    permissions.value = []
    notificationSettings.value = null
    preferences.value = null
    lastFetched.value = null
    error.value = null
  }

  return {
    // State
    profile,
    roles,
    permissions,
    notificationSettings,
    preferences,
    loading,
    error,
    lastFetched,

    // Computed - Basic info
    userId,
    username,
    displayName,
    email,
    avatar,
    bio,

    // Computed - Permissions
    isAdmin,
    isModerator,
    hasPermission,
    hasRole,

    // Computed - Status
    isAuthenticated,
    isLoaded,

    // Actions
    fetchProfile,
    updateProfile,
    updateAvatar,
    fetchNotificationSettings,
    updateNotificationSettings,
    fetchPreferences,
    updatePreferences,
    can,
    has,
    clearUser,
  }
})
