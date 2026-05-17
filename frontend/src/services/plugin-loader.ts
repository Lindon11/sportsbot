import type { RouteRecordRaw } from 'vue-router'
import type { PluginRouteDefinition } from '@/types/plugin-route'
import { useHubStore } from '@/stores/hub'

/**
 * Plugin Loader Service
 *
 * Handles dynamic loading of plugin routes, components, and modules.
 * This service bridges the gap between the backend plugin registry
 * and the frontend router.
 */

// Cache for loaded plugin modules
const pluginModules: Map<string, PluginModule> = new Map()

/**
 * Plugin Module Structure
 */
export interface PluginModule {
  slug: string
  name: string
  version: string
  routes: RouteRecordRaw[]
  components: Map<string, unknown>
  stores: Map<string, unknown>
  services: Map<string, unknown>
}

/**
 * Get all registered plugins from the hub store
 */
export async function getActivePlugins(): Promise<string[]> {
  const hub = useHubStore()
  await hub.fetchPlugins()
  return hub.pluginIds
}

/**
 * Load a single plugin module
 */
export async function loadPluginModule(pluginSlug: string): Promise<PluginModule | null> {
  // Check cache first
  if (pluginModules.has(pluginSlug)) {
    return pluginModules.get(pluginSlug)!
  }

  try {
    // Try to load the plugin's index module
    const pluginIndex = await import(`@/plugins/${pluginSlug}/index.ts`)

    const module: PluginModule = {
      slug: pluginSlug,
      name: pluginIndex.name || pluginSlug,
      version: pluginIndex.version || '1.0.0',
      routes: await loadPluginRoutesFromModule(pluginSlug),
      components: new Map(),
      stores: new Map(),
      services: new Map(),
    }

    // Cache the module
    pluginModules.set(pluginSlug, module)

    return module
  } catch (error) {
    // Plugin might not have frontend files
    console.debug(`Plugin '${pluginSlug}' has no frontend module or failed to load:`, error)
    return null
  }
}

/**
 * Load routes from a plugin's routes.ts file
 */
async function loadPluginRoutesFromModule(pluginSlug: string): Promise<RouteRecordRaw[]> {
  try {
    const routesModule = await import(`@/plugins/${pluginSlug}/routes.ts`)
    const definitions: PluginRouteDefinition[] = routesModule.default || routesModule

    return definitions.map(route => ({
      path: route.path,
      name: route.name,
      component: () => import(`@/plugins/${pluginSlug}/views/${route.component}`),
      meta: {
        ...route.meta,
        plugin: pluginSlug,
      },
      children: route.children?.map(child => ({
        path: child.path,
        name: child.name,
        component: () => import(`@/plugins/${pluginSlug}/views/${child.component}`),
        meta: {
          ...child.meta,
          plugin: pluginSlug,
        },
      })),
    })) as RouteRecordRaw[]
  } catch {
    console.debug(`No routes found for plugin '${pluginSlug}'`)
    return []
  }
}

/**
 * Load all active plugins
 */
export async function loadAllPlugins(): Promise<PluginModule[]> {
  const activePlugins = await getActivePlugins()
  const modules: PluginModule[] = []

  for (const slug of activePlugins) {
    const module = await loadPluginModule(slug)
    if (module) {
      modules.push(module)
    }
  }

  return modules
}

/**
 * Get all routes from loaded plugins
 */
export function getPluginRoutes(): RouteRecordRaw[] {
  const routes: RouteRecordRaw[] = []

  for (const module of pluginModules.values()) {
    routes.push(...module.routes)
  }

  return routes
}

/**
 * Load and return all plugin routes for router
 */
export async function loadPluginRoutes(): Promise<RouteRecordRaw[]> {
  const modules = await loadAllPlugins()
  const routes: RouteRecordRaw[] = []

  for (const module of modules) {
    routes.push(...module.routes)
  }

  return routes
}

/**
 * Import a component from a plugin
 */
export async function importPluginComponent(
  pluginSlug: string,
  componentPath: string
): Promise<unknown> {
  try {
    const component = await import(`@/plugins/${pluginSlug}/${componentPath}`)
    return component.default || component
  } catch (error) {
    console.error(`Failed to load component '${componentPath}' from plugin '${pluginSlug}':`, error)
    return null
  }
}

/**
 * Get loaded plugin module
 */
export function getPluginModule(slug: string): PluginModule | undefined {
  return pluginModules.get(slug)
}

/**
 * Clear plugin module cache
 */
export function clearPluginCache(): void {
  pluginModules.clear()
}

/**
 * Plugin Routes for Lazy Loading
 *
 * These routes can be added to the main router configuration.
 * They will be loaded lazily when the plugin is accessed.
 */
export const pluginLazyRoutes: RouteRecordRaw[] = [
  // Placeholder for dynamically loaded plugin routes
  // The actual routes are added via router.addRoute() after plugins are loaded
]

/**
 * Create routes for a specific plugin based on its manifest
 */
export function createPluginRoutes(
  pluginSlug: string,
  routeDefinitions: PluginRouteDefinition[]
): RouteRecordRaw[] {
  return routeDefinitions.map(route => ({
    path: route.path,
    name: route.name,
    component: () => import(`@/plugins/${pluginSlug}/views/${route.component}`),
    meta: {
      ...route.meta,
      plugin: pluginSlug,
    },
    children: route.children?.map(child => ({
      path: child.path,
      name: child.name,
      component: () => import(`@/plugins/${pluginSlug}/views/${child.component}`),
      meta: {
        ...child.meta,
        plugin: pluginSlug,
      },
    })),
  })) as RouteRecordRaw[]
}

export default {
  loadPluginModule,
  loadAllPlugins,
  getPluginRoutes,
  loadPluginRoutes,
  importPluginComponent,
  getPluginModule,
  clearPluginCache,
  createPluginRoutes,
}
