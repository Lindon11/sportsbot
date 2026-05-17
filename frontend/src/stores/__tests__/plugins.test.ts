import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { usePluginsStore, type Plugin, type PluginRoute, type PluginNavigationItem } from '../plugins'

// Mock the API module
vi.mock('@/services/api', () => ({
  default: {
    get: vi.fn(),
    clearCache: vi.fn(),
  },
}))

import api from '@/services/api'

describe('Plugin Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  describe('Initial State', () => {
    it('starts with empty plugins array', () => {
      const store = usePluginsStore()
      expect(store.plugins).toEqual([])
    })

    it('starts with empty routes array', () => {
      const store = usePluginsStore()
      expect(store.routes).toEqual([])
    })

    it('starts with empty navigation array', () => {
      const store = usePluginsStore()
      expect(store.navigation).toEqual([])
    })

    it('starts with loaded set to false', () => {
      const store = usePluginsStore()
      expect(store.loaded).toBe(false)
    })

    it('starts with loading set to false', () => {
      const store = usePluginsStore()
      expect(store.loading).toBe(false)
    })
  })

  describe('Computed Properties', () => {
    it('enabledPlugins returns a Map of plugins keyed by slug', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'test-plugin',
        name: 'Test Plugin',
        version: '1.0.0',
        description: 'A test plugin',
        icon: '🧪',
        color: 'blue',
        route_name: 'test-plugin',
        frontend_routes: [],
        navigation: { enabled: true, section: 'test', order: 1, parent: null },
        order: 1,
        has_api_routes: false,
        has_web_routes: true,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.enabledPlugins.size).toBe(1)
      expect(store.enabledPlugins.get('test-plugin')).toEqual(mockPlugin)
    })

    it('pluginsBySection groups navigation items by section', () => {
      const store = usePluginsStore()
      const navItems: PluginNavigationItem[] = [
        { slug: 'combat', name: 'Combat', icon: '⚔️', color: 'red', route: 'combat', section: 'actions', order: 2 },
        { slug: 'hospital', name: 'Hospital', icon: '🏥', color: 'green', route: 'hospital', section: 'utilities', order: 1 },
        { slug: 'jail', name: 'Jail', icon: '⛓️', color: 'gray', route: 'jail', section: 'utilities', order: 2 },
      ]

      store.navigation = navItems

      const sections = store.pluginsBySection
      expect(sections['utilities']?.length).toBe(2)
      expect(sections['actions']?.length).toBe(1)
    })

    it('pluginsBySection sorts items by order within sections', () => {
      const store = usePluginsStore()
      const navItems: PluginNavigationItem[] = [
        { slug: 'jail', name: 'Jail', icon: '⛓️', color: 'gray', route: 'jail', section: 'utilities', order: 2 },
        { slug: 'hospital', name: 'Hospital', icon: '🏥', color: 'green', route: 'hospital', section: 'utilities', order: 1 },
      ]

      store.navigation = navItems

      const sections = store.pluginsBySection
      expect(sections['utilities']?.[0]?.slug).toBe('hospital')
      expect(sections['utilities']?.[1]?.slug).toBe('jail')
    })

    it('pluginRoutes returns routes from store', () => {
      const store = usePluginsStore()
      const routes: PluginRoute[] = [
        { plugin: 'combat', path: '/combat', name: 'combat', component: 'CombatView', meta: {} },
      ]

      store.routes = routes

      expect(store.pluginRoutes).toEqual(routes)
    })
  })

  describe('isEnabled', () => {
    it('returns true when plugin is enabled', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'enabled-plugin',
        name: 'Enabled Plugin',
        version: '1.0.0',
        description: 'Test',
        icon: '✅',
        color: 'green',
        route_name: 'enabled-plugin',
        frontend_routes: [],
        navigation: { enabled: true, section: 'main', order: 1, parent: null },
        order: 1,
        has_api_routes: false,
        has_web_routes: true,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.isEnabled('enabled-plugin')).toBe(true)
    })

    it('returns false when plugin is not enabled', () => {
      const store = usePluginsStore()

      expect(store.isEnabled('non-existent-plugin')).toBe(false)
    })
  })

  describe('getPlugin', () => {
    it('returns plugin by slug', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'test-plugin',
        name: 'Test Plugin',
        version: '1.0.0',
        description: 'Test',
        icon: '🧪',
        color: 'blue',
        route_name: 'test-plugin',
        frontend_routes: [],
        navigation: { enabled: true, section: 'main', order: 1, parent: null },
        order: 1,
        has_api_routes: false,
        has_web_routes: true,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.getPlugin('test-plugin')).toEqual(mockPlugin)
    })

    it('returns undefined for non-existent plugin', () => {
      const store = usePluginsStore()

      expect(store.getPlugin('non-existent')).toBeUndefined()
    })
  })

  describe('getPluginByRoute', () => {
    it('finds plugin by route_name', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'combat',
        name: 'Combat',
        version: '1.0.0',
        description: 'Combat system',
        icon: '⚔️',
        color: 'red',
        route_name: 'combat.index',
        frontend_routes: [],
        navigation: { enabled: true, section: 'actions', order: 1, parent: null },
        order: 1,
        has_api_routes: true,
        has_web_routes: true,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.getPluginByRoute('combat.index')).toEqual(mockPlugin)
    })

    it('finds plugin by frontend_routes name', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'racing',
        name: 'Racing',
        version: '1.0.0',
        description: 'Racing system',
        icon: '🏎️',
        color: 'orange',
        route_name: null,
        frontend_routes: [
          { path: '/racing', name: 'racing-main', component: 'RacingView', meta: {} },
        ],
        navigation: { enabled: true, section: 'activities', order: 1, parent: null },
        order: 1,
        has_api_routes: true,
        has_web_routes: true,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.getPluginByRoute('racing-main')).toEqual(mockPlugin)
    })

    it('returns undefined when route not found', () => {
      const store = usePluginsStore()

      expect(store.getPluginByRoute('non-existent-route')).toBeUndefined()
    })
  })

  describe('hasSlot', () => {
    it('returns true when plugin has the slot', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'dashboard-widget',
        name: 'Dashboard Widget',
        version: '1.0.0',
        description: 'Dashboard widgets',
        icon: '📊',
        color: 'purple',
        route_name: null,
        frontend_routes: [],
        navigation: { enabled: false, section: 'main', order: 1, parent: null },
        order: 1,
        has_api_routes: false,
        has_web_routes: false,
        has_admin_routes: false,
        frontend_slots: ['dashboard-widget', 'sidebar-panel'],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.hasSlot('dashboard-widget', 'dashboard-widget')).toBe(true)
    })

    it('returns false when plugin does not have the slot', () => {
      const store = usePluginsStore()
      const mockPlugin: Plugin = {
        slug: 'test-plugin',
        name: 'Test Plugin',
        version: '1.0.0',
        description: 'Test',
        icon: '🧪',
        color: 'blue',
        route_name: null,
        frontend_routes: [],
        navigation: { enabled: false, section: 'main', order: 1, parent: null },
        order: 1,
        has_api_routes: false,
        has_web_routes: false,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [mockPlugin]

      expect(store.hasSlot('test-plugin', 'non-existent-slot')).toBe(false)
    })

    it('returns false when plugin does not exist', () => {
      const store = usePluginsStore()

      expect(store.hasSlot('non-existent', 'slot')).toBe(false)
    })
  })

  describe('fetchPlugins', () => {
    it('fetches plugins from API', async () => {
      const mockResponse = {
        data: {
          success: true,
          plugins: [
            {
              slug: 'combat',
              name: 'Combat',
              version: '1.0.0',
              description: 'Combat',
              icon: '⚔️',
              color: 'red',
              route_name: 'combat',
              frontend_routes: [],
              navigation: { enabled: true, section: 'actions', order: 1, parent: null },
              order: 1,
              has_api_routes: true,
              has_web_routes: true,
              has_admin_routes: false,
              frontend_slots: [],
              permissions: [],
            },
          ],
          navigation: [],
          routes: [],
        },
      }

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      vi.mocked(api.get).mockResolvedValue(mockResponse as any)

      const store = usePluginsStore()
      await store.fetchPlugins()

      expect(api.get).toHaveBeenCalledWith('/api/v1/plugins/enabled')
      expect(store.plugins).toHaveLength(1)
      expect(store.plugins[0].slug).toBe('combat')
      expect(store.loaded).toBe(true)
    })

    it('does not fetch if already loaded', async () => {
      const store = usePluginsStore()
      store.loaded = true

      await store.fetchPlugins()

      expect(api.get).not.toHaveBeenCalled()
    })

    it('does not fetch if currently loading', async () => {
      const store = usePluginsStore()
      store.loading = true

      await store.fetchPlugins()

      expect(api.get).not.toHaveBeenCalled()
    })

    it('handles API errors gracefully', async () => {
      vi.mocked(api.get).mockRejectedValue(new Error('Network error'))

      const store = usePluginsStore()
      await store.fetchPlugins()

      // Verify that the store state is correct after an error
      expect(store.loaded).toBe(false)
      expect(store.loading).toBe(false)
      expect(store.plugins).toEqual([])
    })
  })

  describe('refreshPlugins', () => {
    it('resets state and refetches', async () => {
      const mockResponse = {
        data: {
          success: true,
          plugins: [],
          navigation: [],
          routes: [],
        },
      }

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      vi.mocked(api.get).mockResolvedValue(mockResponse as any)
      vi.mocked(api.clearCache).mockImplementation(() => {})

      const store = usePluginsStore()
      store.loaded = true
      store.plugins = [
        {
          slug: 'old',
          name: 'Old Plugin',
          version: '1.0.0',
          description: '',
          icon: null,
          color: null,
          route_name: null,
          frontend_routes: [],
          navigation: { enabled: false, section: '', order: 0, parent: null },
          order: 0,
          has_api_routes: false,
          has_web_routes: false,
          has_admin_routes: false,
          frontend_slots: [],
          permissions: [],
        },
      ]

      await store.refreshPlugins()

      expect(api.clearCache).toHaveBeenCalledWith('/api/v1/plugins/enabled')
      expect(api.get).toHaveBeenCalled()
    })
  })

  describe('getPluginsWithSlot', () => {
    it('returns plugins that have the specified slot', () => {
      const store = usePluginsStore()
      const plugin1: Plugin = {
        slug: 'plugin-1',
        name: 'Plugin 1',
        version: '1.0.0',
        description: '',
        icon: null,
        color: null,
        route_name: null,
        frontend_routes: [],
        navigation: { enabled: false, section: '', order: 0, parent: null },
        order: 0,
        has_api_routes: false,
        has_web_routes: false,
        has_admin_routes: false,
        frontend_slots: ['dashboard-widget'],
        permissions: [],
      }
      const plugin2: Plugin = {
        slug: 'plugin-2',
        name: 'Plugin 2',
        version: '1.0.0',
        description: '',
        icon: null,
        color: null,
        route_name: null,
        frontend_routes: [],
        navigation: { enabled: false, section: '', order: 0, parent: null },
        order: 0,
        has_api_routes: false,
        has_web_routes: false,
        has_admin_routes: false,
        frontend_slots: ['dashboard-widget', 'sidebar-panel'],
        permissions: [],
      }
      const plugin3: Plugin = {
        slug: 'plugin-3',
        name: 'Plugin 3',
        version: '1.0.0',
        description: '',
        icon: null,
        color: null,
        route_name: null,
        frontend_routes: [],
        navigation: { enabled: false, section: '', order: 0, parent: null },
        order: 0,
        has_api_routes: false,
        has_web_routes: false,
        has_admin_routes: false,
        frontend_slots: [],
        permissions: [],
      }

      store.plugins = [plugin1, plugin2, plugin3]

      const result = store.getPluginsWithSlot('dashboard-widget')

      expect(result).toHaveLength(2)
      expect(result.map(p => p.slug)).toContain('plugin-1')
      expect(result.map(p => p.slug)).toContain('plugin-2')
      expect(result.map(p => p.slug)).not.toContain('plugin-3')
    })

    it('returns empty array when no plugins have the slot', () => {
      const store = usePluginsStore()

      const result = store.getPluginsWithSlot('non-existent-slot')

      expect(result).toEqual([])
    })
  })
})
