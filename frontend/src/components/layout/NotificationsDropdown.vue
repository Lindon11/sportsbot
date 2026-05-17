<template>
  <div class="notifications-menu">
    <button class="icon-btn" title="Notifications" @click.stop="toggleDropdown">
      <span>🔔</span>
      <span v-if="unreadCount > 0" class="badge">{{ badgeText }}</span>
    </button>
    <div v-if="showDropdown" class="notifications-dropdown">
      <div class="notifications-header">
        <span>Notifications</span>
        <button v-if="unreadCount > 0" class="mark-read-btn" @click="markAllRead">
          Mark all read
        </button>
      </div>
      <div class="notifications-list">
        <div v-if="notifications.length === 0" class="no-notifications">
          No notifications
        </div>
        <div
          v-for="notification in notifications.slice(0, 10)"
          :key="notification.id"
          class="notification-item"
          :class="{ unread: !notification.read_at }"
          @click="markAsRead(notification.id)"
        >
          <div class="notification-title">{{ notification.title }}</div>
          <div class="notification-message">{{ notification.message }}</div>
          <div class="notification-time">{{ formatTime(notification.created_at) }}</div>
        </div>
      </div>
      <router-link to="/notifications" class="notifications-footer" @click="closeDropdown">
        View all notifications
      </router-link>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useNotificationsStore } from '@/stores/notifications'

const notificationsStore = useNotificationsStore()

const showDropdown = ref(false)

const notifications = computed(() => notificationsStore.notifications)
const unreadCount = computed(() => notificationsStore.unreadCount)

const badgeText = computed(() => {
  return unreadCount.value > 99 ? '99+' : String(unreadCount.value)
})

const toggleDropdown = () => {
  showDropdown.value = !showDropdown.value
  if (showDropdown.value) {
    notificationsStore.fetchNotifications()
  }
}

const closeDropdown = () => {
  showDropdown.value = false
}

const markAsRead = (id: number) => {
  notificationsStore.markAsRead(id)
}

const markAllRead = () => {
  notificationsStore.markAllAsRead()
}

const formatTime = (dateString: string): string => {
  const date = new Date(dateString)
  return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
}

const handleClickOutside = (e: MouseEvent) => {
  const target = e.target as HTMLElement
  if (!target.closest('.notifications-menu')) {
    closeDropdown()
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
.notifications-menu {
  position: relative;
}

.icon-btn {
  background: none;
  border: none;
  color: #94a3b8;
  font-size: 1rem;
  cursor: pointer;
  padding: 0.5rem;
  transition: color 0.2s;
  position: relative;
}

.icon-btn:hover {
  color: #00bcd4;
}

.badge {
  position: absolute;
  top: 0;
  right: 0;
  background: #ef4444;
  color: white;
  font-size: 0.625rem;
  font-weight: 700;
  padding: 0.125rem 0.375rem;
  border-radius: 9999px;
  min-width: 1rem;
  text-align: center;
}

.notifications-dropdown {
  position: absolute;
  top: calc(100% + 0.5rem);
  right: 0;
  width: 320px;
  max-height: 400px;
  background: #1e293b;
  border: 1px solid rgba(148, 163, 184, 0.15);
  border-radius: 0.5rem;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
  overflow: hidden;
  z-index: 1000;
}

.notifications-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  font-weight: 600;
  font-size: 0.875rem;
}

.mark-read-btn {
  background: none;
  border: none;
  color: #00bcd4;
  font-size: 0.75rem;
  cursor: pointer;
}

.mark-read-btn:hover {
  text-decoration: underline;
}

.notifications-list {
  max-height: 300px;
  overflow-y: auto;
}

.notification-item {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.05);
  cursor: pointer;
  transition: background 0.2s;
}

.notification-item:hover {
  background: rgba(0, 188, 212, 0.05);
}

.notification-item.unread {
  background: rgba(0, 188, 212, 0.1);
  border-left: 3px solid #00bcd4;
}

.notification-title {
  font-weight: 600;
  font-size: 0.8125rem;
  color: #e2e8f0;
  margin-bottom: 0.25rem;
}

.notification-message {
  font-size: 0.75rem;
  color: #94a3b8;
  line-height: 1.4;
}

.notification-time {
  font-size: 0.6875rem;
  color: #64748b;
  margin-top: 0.375rem;
}

.no-notifications {
  padding: 2rem;
  text-align: center;
  color: #64748b;
  font-size: 0.875rem;
}

.notifications-footer {
  display: block;
  padding: 0.75rem;
  text-align: center;
  color: #00bcd4;
  text-decoration: none;
  font-size: 0.8125rem;
  font-weight: 600;
  border-top: 1px solid rgba(148, 163, 184, 0.1);
}

.notifications-footer:hover {
  background: rgba(0, 188, 212, 0.05);
}
</style>
