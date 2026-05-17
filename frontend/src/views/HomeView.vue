<template>
  <div class="home-view">
    <!-- Welcome Banner -->
    <div class="welcome-banner">
      <div class="welcome-content">
        <h1 class="welcome-title">Welcome, {{ userStore.displayName }}</h1>
        <p class="welcome-subtitle">Manage your account and access your applications</p>
      </div>
      <div class="welcome-actions">
        <router-link v-if="userStore.isAdmin" to="/admin" class="admin-link">
          Admin Panel
        </router-link>
        <button @click="handleLogout" class="logout-btn">Logout</button>
      </div>
    </div>

    <!-- Quick Access Cards (Core only) -->
    <div class="quick-access">
      <router-link to="/profile" class="quick-card">
        <span class="card-icon">👤</span>
        <div class="card-content">
          <span class="card-label">Profile</span>
          <span class="card-desc">Edit your profile</span>
        </div>
      </router-link>
      <router-link to="/settings" class="quick-card">
        <span class="card-icon">⚙️</span>
        <div class="card-content">
          <span class="card-label">Settings</span>
          <span class="card-desc">Account settings</span>
        </div>
      </router-link>
      <router-link to="/activity" class="quick-card">
        <span class="card-icon">📊</span>
        <div class="card-content">
          <span class="card-label">Activity</span>
          <span class="card-desc">Recent activity</span>
        </div>
      </router-link>
      <router-link to="/notifications" class="quick-card">
        <span class="card-icon">🔔</span>
        <div class="card-content">
          <span class="card-label">Notifications</span>
          <span class="card-desc">View alerts</span>
        </div>
      </router-link>
    </div>

    <!-- Plugin Widgets Section -->
    <template v-if="dashboardWidgets.length > 0">
      <h2 class="section-title">Applications</h2>
      <div class="widgets-grid">
        <component
          v-for="widget in dashboardWidgets"
          :key="widget.id"
          :is="widget.component"
          v-bind="widget.props"
        />
      </div>
    </template>

    <!-- Plugin Feature Cards (fallback for simple plugins) -->
    <template v-if="pluginFeatureCards.length > 0">
      <h2 class="section-title">Features</h2>
      <div class="features-grid">
        <router-link
          v-for="card in pluginFeatureCards"
          :key="card.slug"
          :to="card.route"
          class="feature-card"
        >
          <div class="feature-icon">{{ card.icon || '📦' }}</div>
          <h3 class="feature-title">{{ card.name }}</h3>
          <p class="feature-desc">{{ card.description }}</p>
        </router-link>
      </div>
    </template>

    <!-- Empty State (when no plugins) -->
    <div v-if="dashboardWidgets.length === 0 && pluginFeatureCards.length === 0" class="empty-state">
      <div class="empty-icon">🧩</div>
      <h3 class="empty-title">No Plugins Installed</h3>
      <p class="empty-desc">
        This is a clean Core Web APP OS installation. Install plugins to add features and functionality.
      </p>
      <router-link v-if="userStore.isAdmin" to="/admin/plugins" class="install-link">
        Manage Plugins
      </router-link>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useAuthStore } from '@/stores/auth'
import { usePluginsStore } from '@/stores/plugins'
import type { Component } from 'vue'

const router = useRouter()
const userStore = useUserStore()
const authStore = useAuthStore()
const pluginsStore = usePluginsStore()

// Dashboard widget type
interface DashboardWidget {
  id: string
  component: Component | null
  props: Record<string, unknown>
  width: 'full' | 'half' | 'third'
}

// Plugin feature card type
interface PluginFeatureCard {
  slug: string
  name: string
  description: string
  icon: string | null
  route: string
}

// Widgets loaded from plugins (for advanced plugin integration)
const dashboardWidgets = shallowRef<DashboardWidget[]>([])

// Simple feature cards from plugins that don't have widgets
const pluginFeatureCards = computed<PluginFeatureCard[]>(() => {
  return pluginsStore.plugins
    .filter(plugin => plugin.navigation?.enabled && plugin.route_name)
    .map(plugin => ({
      slug: plugin.slug,
      name: plugin.name,
      description: plugin.description,
      icon: plugin.icon,
      route: `/${plugin.route_name}`
    }))
    .sort((a, b) => a.name.localeCompare(b.name))
})

// Fetch user profile and plugins on mount
onMounted(async () => {
  // Load user profile if not loaded
  if (!userStore.isLoaded) {
    await userStore.fetchProfile()
  }

  // Load plugins if not loaded
  if (!pluginsStore.loaded) {
    await pluginsStore.fetchPlugins()
  }
})

// Handle logout
const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.home-view {
  max-width: 1400px;
  margin: 0 auto;
}

/* Welcome Banner */
.welcome-banner {
  background: linear-gradient(135deg, rgba(30, 41, 59, 0.8) 0%, rgba(15, 20, 25, 0.9) 100%);
  border: 1px solid rgba(0, 188, 212, 0.3);
  border-radius: 0.5rem;
  padding: 1.5rem 2rem;
  margin-bottom: 2rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.welcome-content {
  flex: 1;
}

.welcome-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #ffffff;
  margin: 0 0 0.25rem 0;
}

.welcome-subtitle {
  font-size: 0.9375rem;
  color: #94a3b8;
  margin: 0;
}

.welcome-actions {
  display: flex;
  gap: 1rem;
  align-items: center;
}

.admin-link {
  color: #00bcd4;
  text-decoration: none;
  font-weight: 600;
  font-size: 0.875rem;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  transition: all 0.2s;
}

.admin-link:hover {
  background: rgba(0, 188, 212, 0.1);
}

.logout-btn {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: white;
  border: none;
  padding: 0.625rem 1.25rem;
  border-radius: 0.375rem;
  font-weight: 700;
  font-size: 0.875rem;
  cursor: pointer;
  transition: all 0.2s;
}

.logout-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

/* Quick Access */
.quick-access {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1rem;
  margin-bottom: 2rem;
}

.quick-card {
  background: linear-gradient(135deg, rgba(30, 58, 138, 0.4) 0%, rgba(30, 64, 175, 0.4) 100%);
  border: 1px solid rgba(59, 130, 246, 0.3);
  border-radius: 0.5rem;
  padding: 1.25rem;
  display: flex;
  align-items: center;
  gap: 1rem;
  text-decoration: none;
  color: #e2e8f0;
  transition: all 0.3s;
}

.quick-card:hover {
  transform: translateY(-4px);
  border-color: #3b82f6;
  box-shadow: 0 8px 24px rgba(59, 130, 246, 0.2);
}

.card-icon {
  font-size: 1.75rem;
}

.card-content {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.card-label {
  font-size: 1rem;
  font-weight: 600;
}

.card-desc {
  font-size: 0.75rem;
  color: #94a3b8;
}

/* Section Title */
.section-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #f1f5f9;
  margin: 0 0 1rem 0;
}

/* Widgets Grid */
.widgets-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}

/* Features Grid */
.features-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.feature-card {
  background: linear-gradient(135deg, rgba(6, 95, 134, 0.4) 0%, rgba(14, 116, 144, 0.4) 100%);
  border: 1px solid rgba(8, 145, 178, 0.3);
  border-radius: 0.5rem;
  padding: 1.5rem;
  text-decoration: none;
  color: #e2e8f0;
  transition: all 0.3s;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.feature-card:hover {
  transform: translateY(-4px);
  border-color: #0891b2;
  box-shadow: 0 8px 24px rgba(8, 145, 178, 0.3);
  background: linear-gradient(135deg, rgba(6, 95, 134, 0.6) 0%, rgba(14, 116, 144, 0.6) 100%);
}

.feature-icon {
  font-size: 2.5rem;
  line-height: 1;
}

.feature-title {
  font-size: 1rem;
  font-weight: 700;
  margin: 0;
  color: #ffffff;
}

.feature-desc {
  font-size: 0.8125rem;
  color: #cbd5e1;
  margin: 0;
  line-height: 1.4;
}

/* Empty State */
.empty-state {
  text-align: center;
  padding: 4rem 2rem;
  background: rgba(30, 41, 59, 0.4);
  border: 1px dashed rgba(148, 163, 184, 0.3);
  border-radius: 0.5rem;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 1rem;
}

.empty-title {
  font-size: 1.25rem;
  font-weight: 700;
  color: #f1f5f9;
  margin: 0 0 0.5rem 0;
}

.empty-desc {
  font-size: 0.9375rem;
  color: #94a3b8;
  margin: 0 0 1.5rem 0;
  max-width: 400px;
  margin-left: auto;
  margin-right: auto;
}

.install-link {
  display: inline-block;
  background: linear-gradient(135deg, #00bcd4 0%, #0891b2 100%);
  color: white;
  text-decoration: none;
  padding: 0.75rem 1.5rem;
  border-radius: 0.375rem;
  font-weight: 600;
  transition: all 0.2s;
}

.install-link:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 188, 212, 0.3);
}

/* Responsive */
@media (max-width: 1024px) {
  .features-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .welcome-banner {
    flex-direction: column;
    gap: 1rem;
    text-align: center;
  }

  .quick-access {
    grid-template-columns: repeat(2, 1fr);
  }

  .features-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 480px) {
  .quick-access {
    grid-template-columns: 1fr;
  }
}
</style>
