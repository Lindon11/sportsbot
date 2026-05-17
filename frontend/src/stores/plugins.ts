import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

/**
 * Frontend route definition from plugin manifest
 */
export interface PluginRoute {
  plugin: string
  path: string
  name: string | null
  component: string | null
  meta: Record<string, unknown>
}

/**
 * Navigation item from plugin manifest
 */
export interface PluginNavigationItem {
  slug: string
  name: string
  icon: string | null
  color: string | null
  route: string | null
  section: string
  order: number
}

/**
 * Complete plugin manifest from backend
 */
export interface Plugin {
  slug: string
  name: string
  version: string
  description: string
  icon: string | null
  color: string | null
  route_name: string | null
  frontend_routes: Array<{
    path: string
    name: string | null
    component: string | null
    meta: Record<string, unknown>
  }>
  navigation: {
    enabled: boolean
    section: string
    order: number
    parent: string | null
  }
  order: number
  has_api_routes: boolean
  has_web_routes: boolean
  has_admin_routes: boolean
  frontend_slots: string[]
  permissions: string[]
}

export const usePluginsStore = defineStore('plugins', () => {
  const plugins = ref<Plugin[]>([])
  const routes = ref<PluginRoute[]>([])
  const navigation = ref<PluginNavigationItem[]>([])
  const loaded = ref(false)
  const loading = ref(false)

  // Get enabled plugins as a map for quick lookup
  const enabledPlugins = computed(() => {
    const map = new Map<string, Plugin>()
    plugins.value.forEach(p => map.set(p.slug, p))
    return map
  })

  // Get plugins grouped by section for navigation
  const pluginsBySection = computed(() => {
    const sections: Record<string, PluginNavigationItem[]> = {}

    navigation.value
      .sort((a, b) => a.order - b.order)
      .forEach(item => {
        const section = item.section || 'main'
        if (!sections[section]) {
          sections[section] = []
        }
        sections[section].push(item)
      })

    return sections
  })

  // Get routes for dynamic route registration
  const pluginRoutes = computed(() => routes.value)

  // Check if a plugin is enabled
  function isEnabled(slug: string): boolean {
    return enabledPlugins.value.has(slug)
  }

  // Get a specific plugin
  function getPlugin(slug: string): Plugin | undefined {
    return enabledPlugins.value.get(slug)
  }

  // Get plugin by route name
  function getPluginByRoute(routeName: string): Plugin | undefined {
    return plugins.value.find(p =>
      p.route_name === routeName ||
      p.frontend_routes?.some(r => r.name === routeName)
    )
  }

  // Check if plugin has a specific frontend slot
  function hasSlot(slug: string, slotName: string): boolean {
    const plugin = getPlugin(slug)
    return plugin?.frontend_slots?.includes(slotName) ?? false
  }

  // Fetch enabled plugins from API
  async function fetchPlugins() {
    if (loaded.value || loading.value) return

    loading.value = true
    try {
      const response = await api.get('/api/v1/plugins/enabled')
      if (response.data.success) {
        plugins.value = response.data.plugins
        routes.value = response.data.routes || []
        navigation.value = response.data.navigation || []
        loaded.value = true
      }
    } catch (error) {
      console.error('Failed to fetch enabled plugins:', error)
    } finally {
      loading.value = false
    }
  }

  // Refresh plugins (force reload)
  async function refreshPlugins() {
    loaded.value = false
    loading.value = false
    api.clearCache('/api/v1/plugins/enabled')
    await fetchPlugins()
  }

  // Get plugins that provide a specific frontend slot
  function getPluginsWithSlot(slotName: string): Plugin[] {
    return plugins.value.filter(p =>
      p.frontend_slots?.includes(slotName)
    )
  }

  return {
    plugins,
    routes,
    navigation,
    loaded,
    loading,
    enabledPlugins,
    pluginsBySection,
    pluginRoutes,
    isEnabled,
    getPlugin,
    getPluginByRoute,
    hasSlot,
    fetchPlugins,
    refreshPlugins,
    getPluginsWithSlot
  }
})
