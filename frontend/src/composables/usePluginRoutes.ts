import { type Component, defineAsyncComponent } from 'vue'
import type { RouteRecordRaw, Router } from 'vue-router'
import { usePluginsStore, type PluginRoute } from '@/stores/plugins'

/**
 * Plugin route metadata extension
 */
declare module 'vue-router' {
  interface RouteMeta {
    plugin?: string
    title?: string
    requiresAuth?: boolean
    requiresGuest?: boolean
  }
}

/**
 * Core view components that plugins can reference
 * Gaming-specific views are provided by plugins via dynamic imports
 */
const coreComponentMap: Record<string, () => Promise<Component>> = {
  // Core views
  ProfileView: () => import('@/views/ProfileView.vue'),
  ActivityView: () => import('@/views/ActivityView.vue'),
  // Note: TicketsView and AnnouncementsView are plugin features
  // They should be provided by plugins via dynamic imports
}

/**
 * Dynamic component loader for plugin views
 * Plugins can provide their own Vue components in their bundle
 */
function getPluginComponentLoader(pluginSlug: string, componentName: string): (() => Promise<Component>) | null {
  // First, try to load from the plugin's bundle
  try {
    return defineAsyncComponent(() =>
      import(`@/plugins/${pluginSlug}/views/${componentName}.vue`)
    )
  } catch {
    console.warn(`Plugin component not found: ${pluginSlug}/${componentName}`)
    return null
  }
}

/**
 * Get the component loader for a given component name
 */
function getComponentLoader(componentName: string | null, pluginSlug?: string): (() => Promise<Component>) | null {
  if (!componentName) return null

  // Check if it's a core component
  if (coreComponentMap[componentName]) {
    return coreComponentMap[componentName]
  }

  // If we have a plugin slug, try to load from the plugin
  if (pluginSlug) {
    return getPluginComponentLoader(pluginSlug, componentName)
  }

  return null
}

/**
 * Convert a plugin route to a Vue Router route
 */
function pluginRouteToRouterRoute(route: PluginRoute): RouteRecordRaw | null {
  const componentLoader = getComponentLoader(route.component, route.plugin)

  if (!componentLoader) {
    console.warn(`Cannot create route for ${route.path}: component ${route.component} not found`)
    return null
  }

  return {
    path: route.path,
    name: route.name || undefined,
    component: defineAsyncComponent(componentLoader),
    meta: {
      plugin: route.plugin,
      ...route.meta,
    },
  }
}

/**
 * Register plugin routes with the router
 */
export function registerPluginRoutes(
  router: Router,
  pluginRoutes: PluginRoute[]
): void {
  // Get the existing routes
  const existingRoutes = router.getRoutes()
  const existingPaths = new Set(existingRoutes.map(r => r.path))

  // Convert plugin routes to router routes
  const newRoutes: RouteRecordRaw[] = []

  for (const pluginRoute of pluginRoutes) {
    // Skip if route already exists
    if (existingPaths.has(pluginRoute.path)) {
      continue
    }

    const routerRoute = pluginRouteToRouterRoute(pluginRoute)
    if (routerRoute) {
      newRoutes.push(routerRoute)
    }
  }

  // Find the CoreLayout route to add plugin routes as children
  const coreLayoutRoute = existingRoutes.find(r => r.path === '/' && r.children?.length)

  if (coreLayoutRoute) {
    // Add routes as children of CoreLayout
    for (const route of newRoutes) {
      try {
        router.addRoute(coreLayoutRoute.name as string, route)
      } catch (error) {
        console.warn(`Failed to register route ${route.path}:`, error)
      }
    }
  } else {
    // Fallback: add routes directly
    for (const route of newRoutes) {
      try {
        router.addRoute(route)
      } catch (error) {
        console.warn(`Failed to register route ${route.path}:`, error)
      }
    }
  }
}

/**
 * Unregister plugin routes from the router
 */
export function unregisterPluginRoutes(router: Router, pluginSlug: string): void {
  const routes = router.getRoutes()

  for (const route of routes) {
    if (route.meta?.plugin === pluginSlug && route.name) {
      router.removeRoute(route.name)
    }
  }
}

/**
 * Composable for plugin route management
 */
export function usePluginRoutes() {
  const pluginsStore = usePluginsStore()

  /**
   * Check if a route is provided by an enabled plugin
   */
  function isPluginRoute(routeName: string): boolean {
    return pluginsStore.getPluginByRoute(routeName) !== undefined
  }

  /**
   * Check if a plugin route is accessible (plugin is enabled)
   */
  function isRouteAccessible(routeName: string): boolean {
    const plugin = pluginsStore.getPluginByRoute(routeName)
    return plugin ? pluginsStore.isEnabled(plugin.slug) : true
  }

  /**
   * Get all routes for a specific plugin
   */
  function getRoutesForPlugin(slug: string): PluginRoute[] {
    return pluginsStore.routes.filter(r => r.plugin === slug)
  }

  /**
   * Get the component name for a route
   */
  function getComponentForRoute(routeName: string): string | null {
    const route = pluginsStore.routes.find(r => r.name === routeName)
    return route?.component || null
  }

  return {
    isPluginRoute,
    isRouteAccessible,
    getRoutesForPlugin,
    getComponentForRoute,
    registerPluginRoutes,
    unregisterPluginRoutes,
  }
}
