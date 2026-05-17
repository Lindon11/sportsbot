<template>
  <div v-if="announcements.length > 0" class="announcements-container">
    <div
      v-for="announcement in announcements"
      :key="announcement.id"
      :class="['announcement-banner', `announcement-${announcement.type}`]"
    >
      <div class="announcement-content">
        <div class="announcement-header">
          <span class="announcement-icon">{{ getIcon(announcement.type) }}</span>
          <div class="announcement-info">
            <div class="announcement-title-row">
              <h3 class="announcement-title">{{ announcement.title }}</h3>
              <span v-if="announcement.is_sticky" class="sticky-badge">📌 Pinned</span>
            </div>
            <p class="announcement-message">{{ announcement.message }}</p>
            <span v-if="announcement.published_at" class="announcement-date">
              {{ formatDate(announcement.published_at) }}
            </span>
          </div>
        </div>
        <button
          @click="dismissAnnouncement(announcement.id)"
          class="dismiss-btn"
          title="Dismiss"
        >
          ×
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import api from '@/services/api'

interface Announcement {
  id: number
  title: string
  message: string
  type: string
  is_sticky?: boolean
  published_at?: string
}

const announcements = ref<Announcement[]>([])
const dismissedIds = ref(new Set<number>())

// Load dismissed announcements from localStorage
const loadDismissed = () => {
  const stored = localStorage.getItem('dismissed_announcements')
  if (stored) {
    try {
      dismissedIds.value = new Set(JSON.parse(stored))
    } catch (e) {
      console.error('Error loading dismissed announcements:', e)
    }
  }
}

// Save dismissed announcements to localStorage
const saveDismissed = () => {
  localStorage.setItem('dismissed_announcements', JSON.stringify([...dismissedIds.value]))
}

const fetchAnnouncements = async () => {
  try {
    const response = await api.get('/announcements')
    // Filter out dismissed announcements
    announcements.value = response.data.filter((a: Announcement) => !dismissedIds.value.has(a.id))
  } catch (error) {
    console.error('Error fetching announcements:', error)
  }
}

const dismissAnnouncement = async (id: number) => {
  dismissedIds.value.add(id)
  saveDismissed()
  announcements.value = announcements.value.filter(a => a.id !== id)

  // Optionally track view on server
  try {
    await api.post(`/announcements/${id}/view`)
  } catch (error) {
    console.error('Error marking announcement as viewed:', error)
  }
}

const getIcon = (type: string) => {
  const icons: Record<string, string> = {
    news: '📢',
    event: '🎉',
    maintenance: '🔧',
    update: '✨',
    alert: '⚠️'
  }
  return icons[type] || '📢'
}

const formatDate = (dateString: string) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  return date.toLocaleDateString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

onMounted(() => {
  loadDismissed()
  fetchAnnouncements()
})
</script>

<style scoped>
.announcements-container {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.announcement-banner {
  border-radius: 0.5rem;
  padding: 1rem;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
  animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.announcement-news {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
  border-left: 4px solid #1e40af;
}

.announcement-event {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border-left: 4px solid #047857;
}

.announcement-maintenance {
  background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
  border-left: 4px solid #b45309;
}

.announcement-update {
  background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
  border-left: 4px solid #6d28d9;
}

.announcement-alert {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  border-left: 4px solid #b91c1c;
}

.announcement-content {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.announcement-header {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  flex: 1;
}

.announcement-icon {
  font-size: 1.5rem;
  flex-shrink: 0;
  margin-top: 0.125rem;
}

.announcement-info {
  flex: 1;
  color: white;
}

.announcement-title-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.announcement-title {
  font-size: 1.125rem;
  font-weight: 700;
  margin: 0;
  color: white;
}

.sticky-badge {
  background: rgba(255, 255, 255, 0.2);
  padding: 0.125rem 0.5rem;
  border-radius: 0.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  white-space: nowrap;
}

.announcement-message {
  font-size: 0.9375rem;
  line-height: 1.5;
  margin: 0 0 0.5rem 0;
  color: rgba(255, 255, 255, 0.95);
}

.announcement-date {
  font-size: 0.75rem;
  opacity: 0.8;
  color: rgba(255, 255, 255, 0.8);
}

.dismiss-btn {
  background: rgba(255, 255, 255, 0.2);
  border: none;
  color: white;
  font-size: 1.5rem;
  line-height: 1;
  width: 2rem;
  height: 2rem;
  border-radius: 0.25rem;
  cursor: pointer;
  transition: background 0.2s;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
}

.dismiss-btn:hover {
  background: rgba(255, 255, 255, 0.3);
}

@media (max-width: 640px) {
  .announcement-banner {
    padding: 0.875rem;
  }

  .announcement-icon {
    font-size: 1.25rem;
  }

  .announcement-title {
    font-size: 1rem;
  }

  .announcement-message {
    font-size: 0.875rem;
  }

  .announcement-title-row {
    flex-direction: column;
    align-items: flex-start;
    gap: 0.25rem;
  }
}
</style>
