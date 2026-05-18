import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import type { RouteMeta } from '@/types/router'
import '@/types/router' // Import for route meta type augmentation
import { usePluginsStore } from '@/stores/plugins'
import { registerPluginRoutes } from '@/composables/usePluginRoutes'

// Re-export RouteMeta for convenience
export type { RouteMeta } from '@/types/router'

// Plugin slug to route mapping - used for static route definitions
// Dynamic routes are loaded from backend via PluginManifestService
// All gaming/utility plugins are now loaded dynamically from bundles
const pluginRoutes: Record<string, string[]> = {}

// Reverse mapping: route name -> plugin slug
const routeToPlugin: Record<string, string> = {}
Object.entries(pluginRoutes).forEach(([plugin, routes]) => {
  routes.forEach(route => {
    routeToPlugin[route] = plugin
  })
})

/**
 * Route definitions with lazy loading for optimal bundle size
 * Core routes only - gaming routes are provided by plugins
 */
const routes: RouteRecordRaw[] = [
  // Root redirect
  {
    path: '/',
    redirect: '/dashboard'
  },

  // Guest-only routes (authentication)
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { requiresGuest: true, title: 'Login' } satisfies RouteMeta
  },
  {
    path: '/register',
    name: 'register',
    component: () => import('@/views/RegisterView.vue'),
    meta: { requiresGuest: true, title: 'Register' } satisfies RouteMeta
  },
  {
    path: '/forgot-password',
    name: 'forgot-password',
    component: () => import('@/views/ForgotPasswordView.vue'),
    meta: { requiresGuest: true, title: 'Forgot Password' } satisfies RouteMeta
  },
  {
    path: '/reset-password',
    name: 'reset-password',
    component: () => import('@/views/ResetPasswordView.vue'),
    meta: { requiresGuest: true, title: 'Reset Password' } satisfies RouteMeta
  },

  // Authenticated routes with CoreLayout
  {
    path: '/',
    component: () => import('@/layouts/CoreLayout.vue'),
    meta: { requiresAuth: true } satisfies RouteMeta,
    children: [
      // Dashboard & Home (Core)
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/views/HomeView.vue'),
        meta: { title: 'Dashboard' } satisfies RouteMeta
      },
      {
        path: 'home',
        name: 'home',
        component: () => import('@/views/HomeView.vue'),
        meta: { title: 'Home' } satisfies RouteMeta
      },

      // Core Profile (Core)
      {
        path: 'profile',
        name: 'profile',
        component: () => import('@/views/ProfileView.vue'),
        meta: { title: 'Profile' } satisfies RouteMeta
      },

      // Activity Log (Core)
      {
        path: 'activity',
        name: 'activity',
        component: () => import('@/views/ActivityView.vue'),
        meta: { title: 'Activity' } satisfies RouteMeta
      },

      // User Settings (Core)
      {
        path: 'settings',
        name: 'settings',
        component: () => import('@/views/SettingsView.vue'),
        meta: { title: 'Settings' } satisfies RouteMeta
      },
      {
        path: 'notifications',
        name: 'notifications',
        component: () => import('@/views/NotificationsView.vue'),
        meta: { title: 'Notifications' } satisfies RouteMeta
      },

      // NOTE: Gaming routes (crimes, gym, hospital, bank, drugs, theft, racing,
      // jail, properties, bounty, detective, bullets, gang, organized-crime,
      // chat, messaging, achievements, leaderboards, employment, education,
      // quests, alliances, shop, market, stocks, casino, explore, hunting,
      // events, tournament, inventory, missions, combat, scavenge, skills,
      // forums, announcements, daily-rewards) are now provided by plugins.
      // Install the gaming bundle to restore these features.
    ]
  },

  // 404 Catch-all route
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Page Not Found' } satisfies RouteMeta
  }
]

/**
 * Create router instance
 */
const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior(_to, _from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    }
    return { top: 0 }
  }
})

/**
 * Initialize dynamic plugin routes
 * Called after plugins are loaded from the backend
 */
export async function initializePluginRoutes(): Promise<void> {
  const pluginsStore = usePluginsStore()

  // Fetch enabled plugins if not already loaded
  if (!pluginsStore.loaded) {
    await pluginsStore.fetchPlugins()
  }

  // Register dynamic routes from plugins
  if (pluginsStore.routes.length > 0) {
    registerPluginRoutes(router, pluginsStore.routes)
  }
}

/**
 * Navigation guard for authentication and plugin routes
 */
router.beforeEach(async (to, _from, next) => {
  const user = localStorage.getItem('user')
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresGuest = to.matched.some(record => record.meta.requiresGuest)

  // Update document title if route has a title
  const title = to.meta?.title as string | undefined
  if (title) {
    const appName = import.meta.env.VITE_APP_NAME || 'Core Web App'
    document.title = `${title} | ${appName}`
  }

  // Keep plugin routes registered for authenticated navigation. Other layout
  // components may fetch plugin manifests before the router sees them.
  const pluginsStore = usePluginsStore()
  if (user) {
    await initializePluginRoutes()

    const resolved = router.resolve(to.fullPath)
    if (to.name === 'not-found' && resolved.name !== 'not-found') {
      next({
        path: to.path,
        query: to.query,
        hash: to.hash,
        replace: true,
      })
      return
    }
  }

  if (requiresAuth && !user) {
    // Redirect to login if auth required but not authenticated
    next({ name: 'login', query: { redirect: to.fullPath } })
    return
  }

  if (requiresGuest && user) {
    // Redirect to dashboard if guest route but already authenticated
    next({ name: 'dashboard' })
    return
  }

  // Check if route requires an enabled plugin
  const pluginSlug = to.meta?.plugin as string | undefined
  if (pluginSlug && !pluginsStore.isEnabled(pluginSlug)) {
    // Plugin not enabled, redirect to dashboard
    next({ name: 'dashboard' })
    return
  }

  next()
})

export default router
