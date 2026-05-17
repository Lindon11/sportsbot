<template>
  <div class="notifications-container">
    <div class="header">
      <div class="header-content">
        <router-link to="/dashboard" class="back-link">← Back</router-link>
      </div>
    </div>

    <div class="content-wrapper">
      <div class="notifications-banner">
        <div class="banner-content">
          <div>
            <h1 class="banner-title">🔔 Notifications</h1>
            <p class="banner-subtitle">Stay up to date with your activities</p>
          </div>
          <div class="unread-count" v-if="unreadCount > 0">
            {{ unreadCount }} unread
          </div>
        </div>
      </div>

      <div class="notifications-actions">
        <div class="filter-tabs">
          <button @click="filter = 'all'" :class="['tab', { active: filter === 'all' }]">
            All
          </button>
          <button @click="filter = 'unread'" :class="['tab', { active: filter === 'unread' }]">
            Unread
          </button>
        </div>
        <div class="action-buttons">
          <button v-if="unreadCount > 0" @click="markAllAsRead" class="action-btn">
            ✓ Mark all read
          </button>
          <button v-if="readNotifications.length > 0" @click="clearRead" class="action-btn danger">
            🗑️ Clear read
          </button>
        </div>
      </div>

      <div v-if="loading" class="loading-state">
        <div class="spinner"></div>
      </div>

      <div v-else-if="filteredNotifications.length === 0" class="empty-state">
        <div class="empty-icon">📭</div>
        <h3>No notifications</h3>
        <p>You're all caught up!</p>
      </div>

      <div v-else class="notifications-list">
        <div v-for="notification in filteredNotifications"
             :key="notification.id"
             :class="['notification-card', { unread: !notification.read_at }]"
             @click="handleClick(notification)">
          <div class="notification-icon">
            {{ getIcon(notification.type) }}
          </div>
          <div class="notification-content">
            <div class="notification-header">
              <h3 class="notification-title">{{ notification.title }}</h3>
              <span class="notification-time">{{ formatTime(notification.created_at) }}</span>
            </div>
            <p class="notification-message">{{ notification.message }}</p>
            <div v-if="notification.data?.action" class="notification-action">
              <router-link :to="notification.data.action" class="action-link">
                View Details →
              </router-link>
            </div>
          </div>
          <button v-if="!notification.read_at" @click.stop="markAsRead(notification.id)" class="mark-read-btn" title="Mark as read">
            ○
          </button>
          <button @click.stop="deleteNotification(notification.id)" class="delete-btn" title="Delete">
            ×
          </button>
        </div>
      </div>

      <div v-if="!loading && filteredNotifications.length > 0" class="load-more">
        <button v-if="hasMore" @click="loadMore" :disabled="loadingMore" class="load-more-btn">
          {{ loadingMore ? 'Loading...' : 'Load More' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useNotificationsStore } from '@/stores/notifications'
import type { Notification } from '@/types/notification'

const store = useNotificationsStore()

const loading = ref(true)
const loadingMore = ref(false)
const filter = ref('all')
const hasMore = ref(false)

const unreadCount = computed(() => store.unreadCount)
const readNotifications = computed(() => store.readNotifications)

const filteredNotifications = computed(() => {
  if (filter.value === 'unread') {
    return store.unreadNotifications
  }
  return store.notifications
})

const getIcon = (type: string): string => {
  const icons: Record<string, string> = {
    message: '✉️',
    warning: '⚠️',
    system: 'ℹ️',
    security: '🔐',
    update: '🔄',
    default: '📢',
  }
  return icons[type] ?? icons.default
}

const formatTime = (dateString: string): string => {
  const date = new Date(dateString)
  const now = new Date()
  const diff = now.getTime() - date.getTime()

  const minutes = Math.floor(diff / 60000)
  const hours = Math.floor(diff / 3600000)
  const days = Math.floor(diff / 86400000)

  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes}m ago`
  if (hours < 24) return `${hours}h ago`
  if (days < 7) return `${days}d ago`

  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
}

const handleClick = (notification: Notification): void => {
  if (!notification.read_at) {
    store.markAsRead(notification.id)
  }
  if (notification.data && notification.data.action) {
    // Let router-link handle navigation
  }
}

const markAsRead = (id: number): void => {
  store.markAsRead(id)
}

const markAllAsRead = (): void => {
  store.markAllAsRead()
}

const deleteNotification = (id: number): void => {
  store.deleteNotification(id)
}

const clearRead = () => {
  store.clearRead()
}

const loadMore = async () => {
  loadingMore.value = true
  // TODO: Implement pagination
  loadingMore.value = false
}

onMounted(async () => {
  try {
    await store.fetchNotifications()
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.notifications-container {
  min-height: 100vh;
  background: linear-gradient(to bottom right, #111827, #1f2937, #111827);
}

.header {
  background-color: rgba(31, 41, 55, 0.5);
  padding: 1rem 1.5rem;
}

.header-content {
  max-width: 800px;
  margin: 0 auto;
}

.back-link {
  color: #9ca3af;
  text-decoration: none;
  font-size: 0.875rem;
  transition: color 0.2s;
}

.back-link:hover {
  color: #00bcd4;
}

.content-wrapper {
  max-width: 800px;
  margin: 0 auto;
  padding: 2rem 1rem;
}

.notifications-banner {
  background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
  border-radius: 1rem;
  padding: 2rem;
  margin-bottom: 1.5rem;
  border: 1px solid rgba(75, 85, 99, 0.3);
}

.banner-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.banner-title {
  font-size: 2rem;
  font-weight: 700;
  color: #f9fafb;
  margin: 0 0 0.5rem;
}

.banner-subtitle {
  color: #9ca3af;
  margin: 0;
}

.unread-count {
  background: #ef4444;
  color: white;
  padding: 0.5rem 1rem;
  border-radius: 9999px;
  font-weight: 600;
  font-size: 0.875rem;
}

.notifications-actions {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
  gap: 1rem;
}

.filter-tabs {
  display: flex;
  gap: 0.5rem;
}

.tab {
  padding: 0.5rem 1rem;
  border: none;
  background: rgba(55, 65, 81, 0.5);
  color: #9ca3af;
  border-radius: 0.5rem;
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 500;
  transition: all 0.2s;
}

.tab:hover {
  background: rgba(55, 65, 81, 0.8);
  color: #f9fafb;
}

.tab.active {
  background: #00bcd4;
  color: white;
}

.action-buttons {
  display: flex;
  gap: 0.5rem;
}

.action-btn {
  padding: 0.5rem 1rem;
  border: none;
  background: rgba(55, 65, 81, 0.5);
  color: #d1d5db;
  border-radius: 0.5rem;
  cursor: pointer;
  font-size: 0.75rem;
  font-weight: 500;
  transition: all 0.2s;
}

.action-btn:hover {
  background: rgba(55, 65, 81, 0.8);
}

.action-btn.danger:hover {
  background: rgba(239, 68, 68, 0.2);
  color: #ef4444;
}

.loading-state {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 300px;
}

.spinner {
  width: 3rem;
  height: 3rem;
  border: 3px solid rgba(0, 188, 212, 0.2);
  border-top-color: #00bcd4;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.empty-state {
  text-align: center;
  padding: 4rem 2rem;
  color: #9ca3af;
}

.empty-icon {
  font-size: 4rem;
  margin-bottom: 1rem;
  opacity: 0.5;
}

.empty-state h3 {
  font-size: 1.25rem;
  color: #f9fafb;
  margin: 0 0 0.5rem;
}

.empty-state p {
  margin: 0;
}

.notifications-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.notification-card {
  display: flex;
  gap: 1rem;
  padding: 1rem;
  background: rgba(31, 41, 55, 0.5);
  border-radius: 0.75rem;
  border: 1px solid rgba(75, 85, 99, 0.3);
  cursor: pointer;
  transition: all 0.2s;
  position: relative;
}

.notification-card:hover {
  background: rgba(31, 41, 55, 0.8);
  border-color: rgba(0, 188, 212, 0.3);
}

.notification-card.unread {
  background: rgba(0, 188, 212, 0.1);
  border-left: 3px solid #00bcd4;
}

.notification-icon {
  font-size: 1.5rem;
  width: 2.5rem;
  height: 2.5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(17, 24, 39, 0.5);
  border-radius: 0.5rem;
  flex-shrink: 0;
}

.notification-content {
  flex: 1;
  min-width: 0;
}

.notification-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.25rem;
}

.notification-title {
  font-size: 0.9375rem;
  font-weight: 600;
  color: #f9fafb;
  margin: 0;
}

.notification-time {
  font-size: 0.75rem;
  color: #6b7280;
  white-space: nowrap;
}

.notification-message {
  font-size: 0.875rem;
  color: #9ca3af;
  margin: 0;
  line-height: 1.5;
}

.notification-action {
  margin-top: 0.5rem;
}

.action-link {
  font-size: 0.8125rem;
  color: #00bcd4;
  text-decoration: none;
  font-weight: 500;
}

.action-link:hover {
  text-decoration: underline;
}

.mark-read-btn,
.delete-btn {
  background: none;
  border: none;
  color: #6b7280;
  font-size: 1.25rem;
  cursor: pointer;
  padding: 0.25rem;
  opacity: 0;
  transition: all 0.2s;
  flex-shrink: 0;
}

.notification-card:hover .mark-read-btn,
.notification-card:hover .delete-btn {
  opacity: 1;
}

.mark-read-btn:hover {
  color: #00bcd4;
}

.delete-btn:hover {
  color: #ef4444;
}

.load-more {
  margin-top: 2rem;
  text-align: center;
}

.load-more-btn {
  padding: 0.75rem 2rem;
  background: rgba(55, 65, 81, 0.5);
  border: 1px solid rgba(75, 85, 99, 0.5);
  color: #d1d5db;
  border-radius: 0.5rem;
  cursor: pointer;
  font-weight: 500;
  transition: all 0.2s;
}

.load-more-btn:hover:not(:disabled) {
  background: rgba(55, 65, 81, 0.8);
  border-color: #00bcd4;
}

.load-more-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 640px) {
  .notifications-actions {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-tabs {
    justify-content: center;
  }

  .action-buttons {
    justify-content: center;
  }

  .notification-header {
    flex-direction: column;
    gap: 0.25rem;
  }
}
</style>
