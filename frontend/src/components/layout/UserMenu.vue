<template>
  <div class="user-menu">
    <button class="user-btn" @click.stop="toggleMenu">
      <span class="username">{{ username }}</span>
      <span class="dropdown-icon">v</span>
    </button>
    <div v-if="showMenu" class="user-dropdown">
      <router-link to="/profile" class="dropdown-item" @click="closeMenu">
        Profile
      </router-link>
      <router-link to="/settings" class="dropdown-item" @click="closeMenu">
        Settings
      </router-link>
      <router-link v-if="isAdmin" to="/admin" class="dropdown-item" @click="closeMenu">
        Admin Panel
      </router-link>
      <button class="dropdown-item" @click="handleLogout">
        Logout
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useUserStore } from '@/stores/user'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'

const router = useRouter()
const userStore = useUserStore()
const authStore = useAuthStore()

const showMenu = ref(false)

const username = computed(() => userStore.username)
const isAdmin = computed(() => userStore.isAdmin)

const toggleMenu = () => {
  showMenu.value = !showMenu.value
}

const closeMenu = () => {
  showMenu.value = false
}

const handleLogout = async () => {
  closeMenu()
  userStore.clearUser()
  await authStore.logout()
  router.push('/login')
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (!target.closest('.user-menu')) {
    closeMenu()
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>

<style scoped>
.user-menu {
  position: relative;
}

.user-btn {
  background: rgba(30, 41, 59, 0.5);
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 0.375rem;
  color: #e2e8f0;
  padding: 0.5rem 1rem;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 600;
  transition: all 0.2s;
}

.user-btn:hover {
  background: rgba(30, 41, 59, 0.8);
  border-color: #00bcd4;
}

.dropdown-icon {
  font-size: 0.625rem;
  color: #94a3b8;
}

.user-dropdown {
  position: absolute;
  top: calc(100% + 0.5rem);
  right: 0;
  background: #1e293b;
  border: 1px solid rgba(148, 163, 184, 0.15);
  border-radius: 0.5rem;
  min-width: 150px;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
  overflow: hidden;
  z-index: 1000;
}

.dropdown-item {
  display: block;
  width: 100%;
  padding: 0.75rem 1rem;
  background: none;
  border: none;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 0.875rem;
  cursor: pointer;
  text-align: left;
  transition: all 0.2s;
}

.dropdown-item:hover {
  background: rgba(0, 188, 212, 0.1);
  color: #00bcd4;
}
</style>
