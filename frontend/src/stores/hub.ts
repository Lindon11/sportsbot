import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'
import type {
  Plugin,
  SlottedComponent,
  PluginMenuItem,
  SlotName
} from '@/types/plugin'

/**
 * Hub Store - Plugin Registry for the Frontend
 *
 * Manages the list of active plugins and their injected components.
 * Provides methods to check plugin status and retrieve components for slots.
 */
export const useHubStore = defineStore('hub', () => {
  // State
  const plugins = ref<Plugin[]>([])
  const slots = ref<Record<string, SlottedComponent[]>>({})
  const menus = ref<Record<string, PluginMenuItem[]>>({})
  const loading = ref(false)
  const error = ref<string | null>(null)
  const lastFetched = ref<Date | null>(null)

  // Cache duration in milliseconds (5 minutes)
  const CACHE_DURATION = 5 * 60 * 1000

  // Computed
  const pluginIds = computed(() => plugins.value.map(p => p.id))

  const pluginNames = computed(() =>
    plugins.value.map(p => ({ id: p.id, name: p.name, icon: p.icon }))
  )

  const totalPlugins = computed(() => plugins.value.length)

  /**
   * Check if a plugin is active
   */
  function isPluginActive(pluginId: string): boolean {
    return plugins.value.some(p => p.id === pluginId && p.enabled)
  }

  /**
   * Get a plugin by ID
   */
  function getPlugin(pluginId: string): Plugin | undefined {
    return plugins.value.find(p => p.id === pluginId)
  }

  /**
   * Get components registered for a specific slot
   */
  function getComponentsForSlot(slotName: SlotName): SlottedComponent[] {
    return slots.value[slotName] || []
  }

  /**
   * Check if a slot has any components
   */
  function hasSlotComponents(slotName: SlotName): boolean {
    const components = slots.value[slotName]
    return !!(components && components.length > 0)
  }

  /**
   * Get menu items for a section
   */
  function getMenuItems(section: string): PluginMenuItem[] {
    return menus.value[section] || []
  }

  /**
   * Fetch plugins from the API
   */
  async function fetchPlugins(forceRefresh = false): Promise<void> {
    // Check cache
    if (!forceRefresh && lastFetched.value) {
      const elapsed = Date.now() - lastFetched.value.getTime()
      if (elapsed < CACHE_DURATION) {
        return // Use cached data
      }
    }

    loading.value = true
    error.value = null

    try {
      // Fetch plugins and slots in parallel
      const [pluginsResponse, slotsResponse, menusResponse] = await Promise.all([
        api.get('/api/core/plugins'),
        api.get('/api/core/plugins/slots'),
        api.get('/api/core/plugins/menus')
      ])

      const pluginsData = pluginsResponse.data as { success: boolean; data: Plugin[] }
      const slotsData = slotsResponse.data as { success: boolean; data: Record<string, SlottedComponent[]> }
      const menusData = menusResponse.data as { success: boolean; data: Record<string, PluginMenuItem[]> }

      if (pluginsData.success) {
        plugins.value = pluginsData.data
      }

      if (slotsData.success) {
        slots.value = slotsData.data
      }

      if (menusData.success) {
        menus.value = menusData.data
      }

      lastFetched.value = new Date()
    } catch (err) {
      const axiosError = err as { response?: { data?: { message?: string } } }
      error.value = axiosError.response?.data?.message || 'Failed to fetch plugins'
      console.error('Failed to fetch plugins:', err)
    } finally {
      loading.value = false
    }
  }

  /**
   * Refresh plugin data
   */
  async function refresh(): Promise<void> {
    await fetchPlugins(true)
  }

  /**
   * Get plugin slots
   */
  function getPluginSlots(pluginId: string): Record<string, string[]> {
    const plugin = getPlugin(pluginId)
    return plugin?.slots || {}
  }

  /**
   * Get plugin routes
   */
  function getPluginRoutes(pluginId: string): Plugin['routes'] {
    const plugin = getPlugin(pluginId)
    return plugin?.routes || []
  }

  /**
   * Check if cache is stale
   */
  function isCacheStale(): boolean {
    if (!lastFetched.value) return true
    const elapsed = Date.now() - lastFetched.value.getTime()
    return elapsed >= CACHE_DURATION
  }

  /**
   * Clear store state
   */
  function clear(): void {
    plugins.value = []
    slots.value = {}
    menus.value = {}
    error.value = null
    lastFetched.value = null
  }

  /**
   * Register a component for a slot dynamically (for plugin frontend use)
   */
  function registerSlotComponent(
    slotName: SlotName,
    component: SlottedComponent
  ): void {
    if (!slots.value[slotName]) {
      slots.value[slotName] = []
    }

    // Avoid duplicates
    const exists = slots.value[slotName].some(
      c => c.plugin_id === component.plugin_id && c.component === component.component
    )

    if (!exists) {
      slots.value[slotName].push(component)
    }
  }

  /**
   * Unregister a component from a slot
   */
  function unregisterSlotComponent(
    slotName: SlotName,
    pluginId: string,
    componentName: string
  ): void {
    if (slots.value[slotName]) {
      slots.value[slotName] = slots.value[slotName].filter(
        c => !(c.plugin_id === pluginId && c.component === componentName)
      )
    }
  }

  /**
   * Get plugin setting
   */
  function getPluginSetting(pluginId: string, key: string, defaultValue?: unknown): unknown {
    const plugin = getPlugin(pluginId)
    if (!plugin?.settings) return defaultValue

    const keys = key.split('.')
    let value: unknown = plugin.settings

    for (const k of keys) {
      if (value && typeof value === 'object' && k in value) {
        value = (value as Record<string, unknown>)[k]
      } else {
        return defaultValue
      }
    }

    return value
  }

  return {
    // State
    plugins,
    slots,
    menus,
    loading,
    error,
    lastFetched,

    // Computed
    pluginIds,
    pluginNames,
    totalPlugins,

    // Actions
    fetchPlugins,
    refresh,
    clear,
    isPluginActive,
    getPlugin,
    getComponentsForSlot,
    hasSlotComponents,
    getMenuItems,
    getPluginSlots,
    getPluginRoutes,
    isCacheStale,
    registerSlotComponent,
    unregisterSlotComponent,
    getPluginSetting
  }
})
