/**
 * Plugin Types
 *
 * Type definitions for the plugin system used by the frontend.
 */

/**
 * Plugin manifest from the backend
 */
export interface PluginManifest {
  name: string
  slug: string
  version: string
  description?: string
  author?: string
  enabled?: boolean
  license_required?: boolean
  requires?: {
    laravel?: string
    plugins?: Record<string, string>
  }
  settings?: PluginSettings
  permissions?: Record<string, string>
  hooks?: Record<string, boolean>
  routes?: {
    web?: boolean
    api?: boolean
    admin?: boolean
  }
  frontend?: {
    slots?: Record<string, string[]>
    routes?: FrontendRoute[]
  }
}

/**
 * Plugin settings configuration
 */
export interface PluginSettings {
  icon?: string
  color?: string
  route?: string
  menu?: {
    enabled: boolean
    order?: number
    section?: string
    parent?: string | null
  }
  [key: string]: unknown
}

/**
 * Frontend route definition
 */
export interface FrontendRoute {
  path: string
  name: string
  component: string
  meta?: Record<string, unknown>
}

/**
 * Plugin data as returned by the API
 */
export interface Plugin {
  id: string
  name: string
  version: string
  description?: string
  enabled: boolean
  installed_at?: string
  icon?: string
  color?: string
  slots: Record<string, string[]>
  routes: FrontendRoute[]
  settings: PluginSettings
}

/**
 * Registered component in a slot
 */
export interface SlottedComponent {
  plugin_id: string
  plugin_name: string
  component: string
}

/**
 * Slot name types for type safety
 */
export type SlotName =
  | 'dashboard-widget'
  | 'header-link'
  | 'header-dropdown'
  | 'sidebar-widget'
  | 'sidebar-link'
  | 'profile-tab'
  | 'user-profile-widget'
  | 'admin-dashboard-widget'
  | 'settings-tab'
  | 'notification-item'
  | string

/**
 * Menu item from plugin
 */
export interface PluginMenuItem {
  plugin_id: string
  title: string
  icon?: string
  color?: string
  route?: string
  order: number
  parent?: string | null
}

/**
 * Plugin permission
 */
export interface PluginPermission {
  plugin: string
  description: string
  has_permission: boolean
}

/**
 * Marketplace plugin
 */
export interface MarketplacePlugin {
  id: string
  slug: string
  name: string
  version: string
  description: string
  author: string
  category: string
  price: number
  rating: number
  downloads: number
  icon: string
  license_required: boolean
  compatibility: {
    laravel?: string
  }
  screenshots: string[]
  features: string[]
  installed?: boolean
  installed_version?: string
  can_install?: boolean
}

/**
 * Sync result from marketplace
 */
export interface MarketplaceSyncResult {
  success: boolean
  license_valid?: boolean
  synced_at?: string
  updates_available?: {
    slug: string
    current_version: string
    latest_version: string
    changelog: string
  }[]
  authorized_plugins?: string[]
  subscription?: {
    tier: string
    expires: string
  }
  message?: string
}

/**
 * Plugin store state
 */
export interface HubState {
  plugins: Plugin[]
  slots: Record<string, SlottedComponent[]>
  menus: Record<string, PluginMenuItem[]>
  loading: boolean
  error: string | null
  lastFetched: Date | null
}
