/**
 * Router Type Definitions for Core Web APP OS
 */

import 'vue-router'

/**
 * Route metadata interface for type safety
 */
export interface RouteMeta {
  requiresAuth?: boolean
  requiresGuest?: boolean
  title?: string
  layout?: string
  transition?: string
  keepAlive?: boolean
  plugin?: string
}

/**
 * Augment Vue Router types for route meta
 */
declare module 'vue-router' {
  interface RouteMeta {
    requiresAuth?: boolean
    requiresGuest?: boolean
    title?: string
    layout?: string
    transition?: string
    keepAlive?: boolean
    plugin?: string
  }
}

/**
 * Named routes for type-safe navigation
 * Core routes only - plugin routes are dynamically registered
 */
export type AppRouteName =
  // Authentication routes
  | 'login'
  | 'register'
  | 'forgot-password'
  | 'reset-password'
  // Core application routes
  | 'dashboard'
  | 'home'
  | 'profile'
  | 'activity'
  | 'settings'
  | 'notifications'
  | 'tickets'
  | 'announcements'
  // Utility routes
  | 'not-found'

/**
 * Route location for type-safe navigation
 */
export interface TypedRouteLocation {
  name: AppRouteName
  params?: Record<string, string | number>
  query?: Record<string, string | number | boolean>
}

/**
 * Navigation guard context
 */
export interface NavigationGuardContext {
  to: ReturnType<typeof import('vue-router').useRoute>
  from: ReturnType<typeof import('vue-router').useRoute>
  next: import('vue-router').NavigationGuardNext
}

/**
 * Breadcrumb item
 */
export interface BreadcrumbItem {
  label: string
  to?: string | { name: AppRouteName; params?: Record<string, string | number> }
  icon?: string
}

/**
 * Menu item for navigation
 */
export interface MenuItem {
  label: string
  to?: { name: AppRouteName }
  icon?: string
  badge?: number | string
  children?: MenuItem[]
  divider?: boolean
}
