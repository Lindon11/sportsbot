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
          path: '/live-env',
          name: 'live-env',
          component: () => import('../views/LiveEnvView.vue')
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
          path: '/sportsbot/autopilot',
          name: 'sportsbot-autopilot',
          component: () => import('../views/SportsBotAutopilotView.vue')
        },
        {
          path: '/sportsbot/post-timings',
          name: 'sportsbot-post-timings',
          component: () => import('../views/SportsBotPostTimingsView.vue')
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
                path: '/sportsbot/routing',
          name: 'sportsbot-routing',
          component: () => import('../views/SportsBotRoutingView.vue')
        },
        {
          path: '/sportsbot/discord-routes',
          name: 'sportsbot-discord-routes',
          component: () => import('../views/SportsBotDiscordRoutesView.vue')
        },
        {
          path: '/sportsbot/coverage',
          name: 'sportsbot-coverage',
          component: () => import('../views/SportsBotCoverageView.vue')
        },
        {
          path: '/sportsbot/scraper-settings',
          name: 'sportsbot-scraper-settings',
          component: () => import('../views/SportsBotScraperSettingsView.vue')
        },
        {
          path: '/sportsbot/telegram-settings',
          name: 'sportsbot-telegram-settings',
          component: () => import('../views/SportsBotTelegramSettingsView.vue')
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
        },
        {
          path: '/sportsbot/update',
          name: 'sportsbot-update',
          component: () => import('../views/SportsBotUpdateView.vue')
        },
        {
          path: '/sportsbot/motorsport-fixtures',
          name: 'sportsbot-motorsport-fixtures',
          component: () => import('../views/SportsBotMotorsportFixturesView.vue')
        },
        {
          path: '/sportsbot/usa-sports-fixtures',
          name: 'sportsbot-usa-sports-fixtures',
          component: () => import('../views/SportsBotSportFixturesView.vue'),
          props: {
            label: 'USA Sports Fixtures TV',
            routeKey: 'BASKETBALL',
            emoji: '🇺🇸',
            description: 'Preview and publish NBA, MLB, NFL and NHL fixtures to their assigned Telegram topics.',
            sports: [
              { sport: 'basketball', label: 'Basketball' },
              { sport: 'baseball', label: 'Baseball' },
              { sport: 'american_football', label: 'American Football' },
              { sport: 'ice_hockey', label: 'Ice Hockey' }
            ]
          }
        },
        {
          path: '/sportsbot/other-sports-fixtures',
          name: 'sportsbot-other-sports-fixtures',
          component: () => import('../views/SportsBotSportFixturesView.vue'),
          props: {
            label: 'Other Sports Fixtures TV',
            routeKey: 'TENNIS',
            emoji: '🏅',
            description: 'Preview and publish tennis, cricket and golf fixtures to their assigned Telegram topics.',
            sports: [
              { sport: 'tennis', label: 'Tennis' },
              { sport: 'cricket', label: 'Cricket' },
              { sport: 'golf', label: 'Golf' }
            ]
          }
        },
            {
                path: '/sportsbot/highlights',
                name: 'sportsbot-highlights',
                component: () => import('../views/SportsBotHighlightsView.vue')
            },
            {
                path: '/sportsbot/highlights-queue',
                name: 'sportsbot-highlights-queue',
                component: () => import('../views/SportsBotHighlightsQueueView.vue')
            },
        {
          path: '/sportsbot/tennis-fixtures',
          redirect: '/sportsbot/other-sports-fixtures'
        },
        {
          path: '/sportsbot/cricket-fixtures',
          redirect: '/sportsbot/other-sports-fixtures'
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
