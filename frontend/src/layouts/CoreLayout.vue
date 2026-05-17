<template>
  <div class="core-layout">
    <AppHeader @toggle-sidebar="toggleSidebar" />
    <AppNav />
    <main class="main-content">
      <router-view />
    </main>
    <!-- Plugin slot for additional panels (e.g., chat) -->
    <div id="plugin-panels"></div>
  </div>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import AppHeader from '@/components/layout/AppHeader.vue'
import AppNav from '@/components/layout/AppNav.vue'

const toggleSidebar = () => {
  // Sidebar toggle handled by AppHeader
}

onMounted(async () => {
  // Core layout initialization
  // Plugins can hook into layout lifecycle via the plugin system
})
</script>

<style scoped>
.core-layout {
  min-height: 100vh;
  background: linear-gradient(180deg, #1a2332 0%, #0f1419 100%);
  color: #e2e8f0;
}

.main-content {
  padding: 2rem;
  min-height: calc(100vh - 180px);
}

@media (max-width: 768px) {
  .main-content {
    padding: 1rem;
  }
}
</style>
