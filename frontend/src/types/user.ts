/**
 * Core User Type Definitions for Web APP OS
 */

/**
 * Basic user information
 */
export interface User {
  id: number
  username: string
  name?: string
  email: string
  avatar?: string
  roles?: string[]
  permissions?: string[]
}

/**
 * User credentials for login
 */
export interface LoginCredentials {
  login?: string
  email?: string
  password: string
  remember?: boolean
}

/**
 * Data for user registration
 */
export interface RegisterData {
  username: string
  email: string
  password: string
  password_confirmation: string
}

/**
 * User settings
 */
export interface UserSettings {
  id: number
  userId: number
  theme: 'light' | 'dark' | 'system'
  language: string
  notificationsEnabled: boolean
  emailNotifications: boolean
  soundEnabled: boolean
  compactMode: boolean
}

/**
 * User session information
 */
export interface UserSession {
  id: string
  userId: number
  ipAddress: string
  userAgent: string
  createdAt: string
  lastActivity: string
  isActive: boolean
}

/**
 * Role definition
 */
export interface Role {
  id: number
  name: string
  displayName: string
  description?: string
  permissions: Permission[]
}

/**
 * Permission definition
 */
export interface Permission {
  id: number
  name: string
  displayName: string
  description?: string
}
