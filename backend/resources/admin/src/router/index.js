import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import DashboardLayout from '../views/DashboardLayout.vue'
import DashboardHome from '../views/DashboardHome.vue'
import UsersView from '../views/UsersView.vue'

const router = createRouter({
  history: createWebHistory('/admin'),
  routes: [
    {
      path: '/',
      redirect: '/login'
    },
    // Installer Routes
    {
      path: '/install',
      name: 'installer-welcome',
      component: () => import('../views/Installer/Welcome.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/install/requirements',
      name: 'installer-requirements',
      component: () => import('../views/Installer/Requirements.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/install/database',
      name: 'installer-database',
      component: () => import('../views/Installer/Database.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/install/settings',
      name: 'installer-settings',
      component: () => import('../views/Installer/Settings.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/install/setup-admin',
      name: 'installer-admin',
      component: () => import('../views/Installer/Admin.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/install/install',
      name: 'installer-install',
      component: () => import('../views/Installer/Install.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/install/complete',
      name: 'installer-complete',
      component: () => import('../views/Installer/Complete.vue'),
      meta: { requiresGuest: true, isInstaller: true }
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { requiresGuest: true }
    },
    {
      path: '/license-required',
      name: 'license-required',
      component: () => import('../views/LicenseGateView.vue'),
      meta: { requiresAuth: true }
    },
    {
      path: '/dashboard',
      component: DashboardLayout,
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: DashboardHome
        },
        {
          path: '/notifications',
          name: 'notifications',
          component: () => import('../views/NotificationsView.vue')
        },
        // User Management
        {
          path: '/users',
          name: 'users',
          component: UsersView
        },
        {
          path: '/user-tools',
          name: 'user-tools',
          component: () => import('../views/UserToolsView.vue')
        },
        {
          path: '/roles',
          name: 'roles',
          component: () => import('../views/RolesView.vue')
        },
        // Configuration
        {
          path: '/settings',
          name: 'settings',
          component: () => import('../views/SettingsView.vue')
        },
        {
          path: '/license',
          name: 'license',
          component: () => import('../views/LicenseView.vue')
        },
        {
          path: '/email-settings',
          name: 'email-settings',
          component: () => import('../views/EmailSettingsView.vue')
        },
        {
          path: '/plugin-settings',
          name: 'plugin-settings',
          component: () => import('../views/PluginsView.vue')
        },
        // System
        {
          path: '/ip-bans',
          name: 'ip-bans',
          component: () => import('../views/IpBansView.vue')
        },
        {
          path: '/error-logs',
          name: 'error-logs',
          component: () => import('../views/ErrorLogsView.vue')
        },
        {
          path: '/activity-logs',
          name: 'activity-logs',
          component: () => import('../views/ActivityLogsView.vue')
        },
        {
          path: '/webhooks',
          name: 'webhooks',
          component: () => import('../views/WebhooksView.vue')
        },
        {
          path: '/security',
          name: 'security',
          component: () => import('../views/SecuritySettingsView.vue')
        },
        {
          path: '/backups',
          name: 'backups',
          component: () => import('../views/BackupManagerView.vue')
        },
        {
          path: '/system-health',
          name: 'system-health',
          component: () => import('../views/SystemHealthView.vue')
        },
        {
          path: '/api-keys',
          name: 'api-keys',
          component: () => import('../views/ApiKeysView.vue')
        },
        {
          path: '/sportsbot/dashboard',
          name: 'sportsbot-dashboard',
          component: () => import('../views/SportsBotDashboardView.vue')
        },
        {
          path: '/sportsbot/fixtures-today',
          name: 'sportsbot-fixtures-today',
          component: () => import('../views/SportsBotFixturesTodayView.vue')
        },
        {
          path: '/sportsbot/football-fixtures',
          name: 'sportsbot-football-fixtures',
          component: () => import('../views/SportsBotFootballFixturesView.vue')
        },
        {
          path: '/sportsbot/rugby-fixtures',
          name: 'sportsbot-rugby-fixtures',
          component: () => import('../views/SportsBotRugbyFixturesView.vue')
        },
        {
          path: '/sportsbot/fight-fixtures',
          name: 'sportsbot-fight-fixtures',
          component: () => import('../views/SportsBotFightFixturesView.vue')
        },
        {
          path: '/sportsbot/tv-guide',
          name: 'sportsbot-tv-guide',
          component: () => import('../views/SportsBotTvGuideView.vue')
        },
        {
          path: '/sportsbot/live-now',
          name: 'sportsbot-live-now',
          component: () => import('../views/SportsBotLiveNowView.vue')
        },
        {
          path: '/sportsbot/routing',
          name: 'sportsbot-routing',
          component: () => import('../views/SportsBotRoutingView.vue')
        },
        {
          path: '/sportsbot/coverage',
          name: 'sportsbot-coverage',
          component: () => import('../views/SportsBotCoverageView.vue')
        },
        {
          path: '/sportsbot/webhook-diagnostics',
          name: 'sportsbot-webhook-diagnostics',
          component: () => import('../views/SportsBotWebhookDiagnosticsView.vue')
        },
        {
          path: '/sportsbot/fixture-queue',
          name: 'sportsbot-fixture-queue',
          component: () => import('../views/SportsBotFixtureQueueView.vue')
        }
      ]
    }
  ]
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('admin_token')
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresGuest = to.matched.some(record => record.meta.requiresGuest)

  if (requiresAuth && !token) {
    next('/login')
  } else if (requiresGuest && token) {
    next('/dashboard')
  } else {
    next()
  }
})

export default router
