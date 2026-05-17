<template>
  <nav class="main-nav">
    <!-- Core navigation items -->
    <router-link to="/dashboard" class="nav-item" active-class="active">
      <span class="nav-icon">🏠</span>
      <span class="nav-label">DASHBOARD</span>
    </router-link>

    <!-- Dynamic plugin navigation -->
    <template v-for="item in sortedNavigation" :key="item.slug">
      <router-link
        v-if="item.route"
        :to="item.route"
        class="nav-item"
        active-class="active"
      >
        <span class="nav-icon">{{ item.icon || '📦' }}</span>
        <span class="nav-label">{{ item.name.toUpperCase() }}</span>
      </router-link>
    </template>

    <!-- Core settings (always show) -->
    <router-link to="/settings" class="nav-item" active-class="active">
      <span class="nav-icon">⚙️</span>
      <span class="nav-label">SETTINGS</span>
    </router-link>
  </nav>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { usePluginsStore } from '@/stores/plugins'

const pluginsStore = usePluginsStore()

// Get navigation items sorted by order
const sortedNavigation = computed(() => {
  return [...pluginsStore.navigation]
    .filter(item => item.section === 'main' || !item.section)
    .sort((a, b) => a.order - b.order)
})

// Fetch plugins on mount if not loaded
onMounted(() => {
  if (!pluginsStore.loaded) {
    pluginsStore.fetchPlugins()
  }
})
</script>

<style scoped>
.main-nav {
  background: rgba(30, 41, 59, 0.4);
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  display: flex;
  justify-content: center;
  gap: 0.5rem;
  padding: 0 1.5rem;
}

.nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  padding: 1rem 1.25rem;
  text-decoration: none;
  color: #94a3b8;
  font-size: 0.75rem;
  font-weight: 600;
  letter-spacing: 0.05em;
  transition: all 0.2s;
  position: relative;
}

.nav-item:hover {
  color: #e2e8f0;
  background: rgba(30, 41, 59, 0.6);
}

.nav-item.active {
  color: #00bcd4;
  background: rgba(0, 188, 212, 0.1);
}

.nav-item.active::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 2px;
  background: #00bcd4;
}

.nav-icon {
  font-size: 1.25rem;
}

@media (max-width: 768px) {
  .main-nav {
    overflow-x: auto;
    justify-content: flex-start;
  }

  .nav-item {
    flex-shrink: 0;
  }
}
</style>
