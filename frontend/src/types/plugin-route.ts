import type { RouteRecordRaw } from 'vue-router'

/**
 * Plugin Route Definition
 *
 * Defines a route that a plugin can register with the frontend router.
 */
export interface PluginRouteDefinition {
  path: string
  name: string
  component: string
  meta?: PluginRouteMeta
  children?: PluginRouteDefinition[]
}

/**
 * Plugin Route Metadata
 */
export interface PluginRouteMeta {
  title?: string
  requiresAuth?: boolean
  requiresGuest?: boolean
  plugin?: string
  [key: string]: unknown
}

/**
 * Plugin Routes File Structure
 *
 * Each plugin can define a routes.ts file that exports its routes.
 */
export interface PluginRoutesModule {
  default: PluginRouteDefinition[]
}

/**
 * Convert plugin route definition to Vue Router route
 */
export function pluginRouteToVueRoute(
  route: PluginRouteDefinition,
  pluginSlug: string
): RouteRecordRaw {
  return {
    path: route.path,
    name: route.name,
    component: () => import(`@/plugins/${pluginSlug}/views/${route.component}`),
    meta: {
      ...route.meta,
      plugin: pluginSlug,
    },
    children: route.children?.map(child => pluginRouteToVueRoute(child, pluginSlug)),
  } as RouteRecordRaw
}

/**
 * Load routes for a specific plugin
 */
export async function loadPluginRoutes(pluginSlug: string): Promise<RouteRecordRaw[]> {
  try {
    const routesModule = await import(`@/plugins/${pluginSlug}/routes.ts`)
    const definitions: PluginRouteDefinition[] = routesModule.default || routesModule

    return definitions.map(route => pluginRouteToVueRoute(route, pluginSlug))
  } catch (error) {
    console.warn(`Failed to load routes for plugin '${pluginSlug}':`, error)
    return []
  }
}
