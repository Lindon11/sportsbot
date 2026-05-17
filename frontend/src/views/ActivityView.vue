<template>
  <div class="activity-container">
    <div class="header">
      <div class="header-content">
        <router-link to="/dashboard" class="back-link">← Back to Dashboard</router-link>
      </div>
    </div>

    <div class="content-wrapper">
      <div class="activity-banner">
        <div class="banner-content">
          <div>
            <h1 class="banner-title">📊 Activity</h1>
            <p class="banner-subtitle">Your recent activity and history</p>
          </div>
          <div class="banner-icon">📈</div>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-section">
        <div class="filter-group">
          <label>Filter by type:</label>
          <select v-model="filterType" class="filter-select">
            <option value="all">All Activity</option>
            <option value="auth">Logins & Security</option>
            <option value="profile">Profile Changes</option>
            <option value="settings">Settings Updates</option>
            <option value="plugins">Plugin Activity</option>
          </select>
        </div>
        <div class="filter-group">
          <label>Period:</label>
          <select v-model="filterPeriod" class="filter-select">
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="90">Last 90 days</option>
            <option value="all">All time</option>
          </select>
        </div>
      </div>

      <div v-if="loading" class="loading-state">
        <div class="spinner"></div>
      </div>

      <div v-else-if="activities.length === 0" class="empty-state">
        <div class="empty-icon">📭</div>
        <h3 class="empty-title">No Activity Yet</h3>
        <p class="empty-desc">Your activity will appear here as you use the platform.</p>
      </div>

      <div v-else class="activity-content">
        <div class="activity-timeline">
          <div
            v-for="group in groupedActivities"
            :key="group.date"
            class="activity-group"
          >
            <div class="group-header">
              <span class="group-date">{{ formatDate(group.date) }}</span>
            </div>
            <div class="activity-list">
              <div
                v-for="activity in group.activities"
                :key="activity.id"
                class="activity-item"
              >
                <div class="activity-icon" :class="activity.type">
                  {{ getActivityIcon(activity.type) }}
                </div>
                <div class="activity-content">
                  <div class="activity-title">{{ activity.description }}</div>
                  <div class="activity-meta">
                    <span class="activity-time">{{ formatTime(activity.created_at) }}</span>
                    <span v-if="activity.ip_address" class="activity-ip">
                      IP: {{ activity.ip_address }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Load More -->
        <div v-if="hasMore" class="load-more">
          <button @click="loadMore" :disabled="loadingMore" class="btn btn-secondary">
            {{ loadingMore ? 'Loading...' : 'Load More' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import api from '@/services/api'

interface Activity {
  id: string
  type: string
  description: string
  created_at: string
  ip_address?: string
  metadata?: Record<string, unknown>
}

interface ActivityGroup {
  date: string
  activities: Activity[]
}

interface ActivityResponse {
  activities?: Activity[]
  data?: Activity[]
  has_more?: boolean
  next_page_url?: string | null
}

const loading = ref(true)
const loadingMore = ref(false)
const activities = ref<Activity[]>([])
const filterType = ref('all')
const filterPeriod = ref('30')
const hasMore = ref(false)
const currentPage = ref(1)

const groupedActivities = computed<ActivityGroup[]>(() => {
  const groups: Map<string, Activity[]> = new Map()

  for (const activity of activities.value) {
    const dateStr = new Date(activity.created_at).toDateString()
    if (!groups.has(dateStr)) {
      groups.set(dateStr, [])
    }
    groups.get(dateStr)!.push(activity)
  }

  return Array.from(groups.entries()).map(([date, acts]) => ({
    date: date ?? '',
    activities: acts,
  }))
})

const loadActivities = async (page = 1) => {
  try {
    const params = new URLSearchParams({
      page: page.toString(),
      per_page: '20',
    })

    if (filterType.value !== 'all') {
      params.append('type', filterType.value)
    }

    if (filterPeriod.value !== 'all') {
      params.append('days', filterPeriod.value)
    }

    const response = await api.get<ActivityResponse>(`/api/v1/user/activity?${params}`)
    const data = response.data

    if (page === 1) {
      activities.value = data.activities || data.data || []
    } else {
      activities.value.push(...(data.activities || data.data || []))
    }

    hasMore.value = data.has_more || data.next_page_url !== null
    currentPage.value = page
  } catch (err) {
    console.error('Failed to load activities:', err)
    // Show empty state on error
    activities.value = []
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

const loadMore = () => {
  loadingMore.value = true
  loadActivities(currentPage.value + 1)
}

const formatDate = (dateString: string): string => {
  const date = new Date(dateString)
  const today = new Date()
  const yesterday = new Date(today)
  yesterday.setDate(yesterday.getDate() - 1)

  if (date.toDateString() === today.toDateString()) {
    return 'Today'
  } else if (date.toDateString() === yesterday.toDateString()) {
    return 'Yesterday'
  } else {
    return date.toLocaleDateString('en-US', {
      weekday: 'long',
      month: 'long',
      day: 'numeric',
    })
  }
}

const formatTime = (dateString: string): string => {
  return new Date(dateString).toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit',
  })
}

const getActivityIcon = (type: string): string => {
  const icons: Record<string, string> = {
    auth: '🔐',
    login: '🔐',
    logout: '🚪',
    profile: '👤',
    settings: '⚙️',
    plugins: '🧩',
    default: '📌',
  }
  return icons[type] ?? '📌'
}

// Watch for filter changes
watch([filterType, filterPeriod], () => {
  loading.value = true
  loadActivities(1)
})

onMounted(() => {
  loadActivities()
})
</script>

<style scoped>
.activity-container {
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

.activity-banner {
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

.banner-icon {
  font-size: 4rem;
  opacity: 0.5;
}

.filters-section {
  display: flex;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.filter-group label {
  font-size: 0.875rem;
  color: #9ca3af;
}

.filter-select {
  background: rgba(17, 24, 39, 0.5);
  border: 1px solid rgba(75, 85, 99, 0.5);
  border-radius: 0.5rem;
  padding: 0.5rem 0.75rem;
  color: #f9fafb;
  font-size: 0.875rem;
  cursor: pointer;
}

.filter-select:focus {
  outline: none;
  border-color: #00bcd4;
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
  background: rgba(31, 41, 55, 0.5);
  border: 1px dashed rgba(75, 85, 99, 0.5);
  border-radius: 1rem;
}

.empty-icon {
  font-size: 3rem;
  margin-bottom: 1rem;
}

.empty-title {
  font-size: 1.25rem;
  font-weight: 600;
  color: #f9fafb;
  margin: 0 0 0.5rem;
}

.empty-desc {
  color: #9ca3af;
  margin: 0;
}

.activity-content {
  background: rgba(31, 41, 55, 0.5);
  border-radius: 1rem;
  border: 1px solid rgba(75, 85, 99, 0.3);
  overflow: hidden;
}

.activity-group {
  border-bottom: 1px solid rgba(75, 85, 99, 0.2);
}

.activity-group:last-child {
  border-bottom: none;
}

.group-header {
  padding: 1rem 1.5rem;
  background: rgba(17, 24, 39, 0.3);
  border-bottom: 1px solid rgba(75, 85, 99, 0.2);
}

.group-date {
  font-size: 0.875rem;
  font-weight: 600;
  color: #00bcd4;
}

.activity-list {
  padding: 0.5rem 0;
}

.activity-item {
  display: flex;
  gap: 1rem;
  padding: 1rem 1.5rem;
  transition: background 0.2s;
}

.activity-item:hover {
  background: rgba(17, 24, 39, 0.3);
}

.activity-icon {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  flex-shrink: 0;
}

.activity-icon.auth {
  background: rgba(16, 185, 129, 0.2);
}

.activity-icon.profile {
  background: rgba(59, 130, 246, 0.2);
}

.activity-icon.settings {
  background: rgba(139, 92, 246, 0.2);
}

.activity-icon.plugins {
  background: rgba(0, 188, 212, 0.2);
}

.activity-content {
  flex: 1;
}

.activity-title {
  font-size: 0.9375rem;
  color: #f9fafb;
  margin-bottom: 0.25rem;
}

.activity-meta {
  display: flex;
  gap: 1rem;
  font-size: 0.75rem;
  color: #6b7280;
}

.load-more {
  padding: 1.5rem;
  text-align: center;
  border-top: 1px solid rgba(75, 85, 99, 0.2);
}

.btn {
  padding: 0.75rem 1.5rem;
  border-radius: 0.5rem;
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
  transition: all 0.2s;
  border: none;
}

.btn-secondary {
  background: rgba(55, 65, 81, 0.5);
  color: #d1d5db;
  border: 1px solid rgba(75, 85, 99, 0.5);
}

.btn-secondary:hover:not(:disabled) {
  background: rgba(55, 65, 81, 0.8);
}

.btn-secondary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (max-width: 640px) {
  .banner-icon {
    display: none;
  }

  .filters-section {
    flex-direction: column;
  }

  .activity-item {
    padding: 1rem;
  }
}
</style>
