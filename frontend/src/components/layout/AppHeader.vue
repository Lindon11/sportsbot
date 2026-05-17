<template>
  <header class="app-header">
    <div class="header-left">
      <button class="hamburger-menu" @click="emit('toggle-sidebar')" aria-label="Toggle menu">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <line x1="3" y1="12" x2="21" y2="12"></line>
          <line x1="3" y1="6" x2="21" y2="6"></line>
          <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
      </button>
      <router-link to="/dashboard" class="logo">
        <span class="logo-text">{{ appName }}</span>
      </router-link>
    </div>

    <div class="header-right">
      <!-- Plugin header slots will be injected here -->
      <div id="plugin-header-slot" class="plugin-slot"></div>

      <NotificationsDropdown />
      <UserMenu />
    </div>
  </header>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import NotificationsDropdown from './NotificationsDropdown.vue'
import UserMenu from './UserMenu.vue'

const emit = defineEmits<{
  'toggle-sidebar': []
}>()

// App name from environment or default
const appName = computed(() => import.meta.env.VITE_APP_NAME || 'Core Web App')
</script>

<style scoped>
.app-header {
  background: rgba(15, 20, 25, 0.95);
  border-bottom: 1px solid rgba(0, 188, 212, 0.1);
  padding: 0.75rem 1.5rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 2rem;
  position: sticky;
  top: 0;
  z-index: 100;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.hamburger-menu {
  display: none;
  background: none;
  border: none;
  color: #94a3b8;
  cursor: pointer;
  padding: 0.5rem;
  border-radius: 0.375rem;
  transition: background-color 0.2s, color 0.2s;
}

.hamburger-menu:hover {
  background: rgba(255, 255, 255, 0.1);
  color: #ffffff;
}

.logo {
  text-decoration: none;
  font-size: 1.5rem;
  font-weight: 700;
}

.logo-text {
  color: #ffffff;
}

.logo-text::first-letter {
  color: #00bcd4;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.plugin-slot {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

@media (max-width: 768px) {
  .hamburger-menu {
    display: flex;
    align-items: center;
    justify-content: center;
  }
}
</style>
