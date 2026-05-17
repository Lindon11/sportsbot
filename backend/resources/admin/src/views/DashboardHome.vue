<template>
  <div class="space-y-6">
    <!-- Actions -->
    <div class="flex items-center justify-end gap-3">
      <select
        v-model="dateRange"
        @change="loadStats"
        class="px-4 py-2 bg-slate-800 border border-slate-700 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
      >
        <option value="7">Last 7 days</option>
        <option value="30">Last 30 days</option>
        <option value="90">Last 90 days</option>
      </select>
      <button
        @click="loadStats"
        class="p-2 bg-slate-800 border border-slate-700 rounded-xl text-white hover:bg-slate-700 transition-colors"
      >
        <ArrowPathIcon :class="['w-5 h-5', loading && 'animate-spin']" />
      </button>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6">
      <div v-for="stat in stats" :key="stat.label"
        class="relative overflow-hidden rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6 group hover:border-slate-600/50 transition-all"
      >
        <div class="flex items-start justify-between">
          <div>
            <p class="text-sm font-medium text-slate-400">{{ stat.label }}</p>
            <p class="mt-2 text-3xl font-bold text-white">{{ stat.value }}</p>
            <div class="mt-2 flex items-center gap-1.5">
              <span :class="[
                'text-sm font-medium',
                stat.trend === 'up' ? 'text-emerald-400' : stat.trend === 'down' ? 'text-red-400' : 'text-slate-400'
              ]">
                {{ stat.change }}
              </span>
              <span class="text-xs text-slate-500">{{ stat.period }}</span>
            </div>
          </div>
          <div :class="['p-3 rounded-xl', stat.color]">
            <component :is="stat.icon" class="w-6 h-6 text-white" />
          </div>
        </div>
        <div class="absolute inset-0 bg-gradient-to-r opacity-0 group-hover:opacity-10 transition-opacity" :class="stat.gradient" />
      </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- User Activity Chart -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-lg font-semibold text-white">User Activity</h2>
          <span class="px-3 py-1 rounded-full bg-cyan-500/20 text-cyan-400 text-xs font-medium">{{ dateRange }} days</span>
        </div>
        <LineChart :labels="activityChart.labels" :datasets="activityChart.datasets" />
      </div>

      <!-- User Distribution -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <div class="flex items-center justify-between mb-6">
          <h2 class="text-lg font-semibold text-white">User Distribution</h2>
          <span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-400 text-xs font-medium">By Role</span>
        </div>
        <DoughnutChart :labels="userDistribution.labels" :data="userDistribution.data" />
      </div>
    </div>

    <!-- Analytics Row -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- User Retention -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <h2 class="text-lg font-semibold text-white mb-4">User Retention</h2>
        <div class="space-y-3">
          <div v-for="cohort in retention" :key="cohort.week" class="p-3 rounded-xl bg-slate-700/30">
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm text-slate-300">{{ cohort.week }}</span>
              <span class="text-xs text-slate-500">{{ cohort.users }} users</span>
            </div>
            <div class="flex gap-2">
              <div class="flex-1">
                <div class="text-xs text-slate-500 mb-1">Day 1</div>
                <div class="h-2 bg-slate-600 rounded-full overflow-hidden">
                  <div class="h-full bg-emerald-500 rounded-full" :style="{ width: cohort.day1 + '%' }"></div>
                </div>
                <div class="text-xs text-emerald-400 mt-1">{{ cohort.day1 }}%</div>
              </div>
              <div class="flex-1">
                <div class="text-xs text-slate-500 mb-1">Day 7</div>
                <div class="h-2 bg-slate-600 rounded-full overflow-hidden">
                  <div class="h-full bg-cyan-500 rounded-full" :style="{ width: cohort.day7 + '%' }"></div>
                </div>
                <div class="text-xs text-cyan-400 mt-1">{{ cohort.day7 }}%</div>
              </div>
            </div>
          </div>
          <div v-if="retention.length === 0" class="text-center py-4 text-slate-500 text-sm">
            No retention data available yet
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <h2 class="text-lg font-semibold text-white mb-4">Recent Activity</h2>
        <div class="space-y-3">
          <div v-for="activity in recentActivity" :key="activity.id"
            class="flex items-center gap-3 p-3 rounded-xl bg-slate-700/30"
          >
            <div :class="['p-2 rounded-lg', activity.color]">
              <component :is="activity.icon" class="w-4 h-4 text-white" />
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm text-white truncate">{{ activity.message }}</p>
              <p class="text-xs text-slate-500">{{ activity.time }}</p>
            </div>
          </div>
          <div v-if="recentActivity.length === 0" class="text-center py-4 text-slate-500 text-sm">
            No recent activity
          </div>
        </div>
      </div>

      <!-- Hourly Activity Heatmap -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <h2 class="text-lg font-semibold text-white mb-4">Activity by Hour</h2>
        <div class="grid grid-cols-12 gap-1">
          <div v-for="hour in hourlyActivity" :key="hour.hour" class="text-center">
            <div
              class="w-full aspect-square rounded-sm"
              :class="getHeatmapColor(hour.value)"
              :title="`${hour.hour}:00 - ${hour.value}% activity`"
            ></div>
            <div v-if="hour.hour % 4 === 0" class="text-xs text-slate-500 mt-1">{{ hour.hour }}</div>
          </div>
        </div>
        <div class="flex justify-between mt-4 text-xs text-slate-500">
          <span>Low</span>
          <div class="flex gap-1">
            <div class="w-4 h-4 rounded-sm bg-slate-700"></div>
            <div class="w-4 h-4 rounded-sm bg-emerald-900"></div>
            <div class="w-4 h-4 rounded-sm bg-emerald-700"></div>
            <div class="w-4 h-4 rounded-sm bg-emerald-500"></div>
            <div class="w-4 h-4 rounded-sm bg-emerald-400"></div>
          </div>
          <span>High</span>
        </div>
      </div>
    </div>

    <!-- Bottom Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- System Health -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <h2 class="text-lg font-semibold text-white mb-4">System Health</h2>
        <div class="space-y-4">
          <div v-for="item in systemHealth" :key="item.label"
            class="flex items-center justify-between p-3 rounded-xl bg-slate-700/30"
          >
            <div class="flex items-center gap-3">
              <div :class="[
                'w-2.5 h-2.5 rounded-full',
                item.status === 'online' ? 'bg-emerald-500 animate-pulse' : 'bg-red-500'
              ]" />
              <span class="text-sm text-slate-300">{{ item.label }}</span>
            </div>
            <span :class="[
              'text-xs font-medium px-2 py-1 rounded-lg',
              item.status === 'online' ? 'bg-emerald-500/20 text-emerald-400' : 'bg-red-500/20 text-red-400'
            ]">
              {{ item.status === 'online' ? 'Online' : 'Offline' }}
            </span>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6">
        <h2 class="text-lg font-semibold text-white mb-4">Quick Actions</h2>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
          <button
            v-for="action in quickActions"
            :key="action.label"
            @click="action.handler"
            class="flex flex-col items-center gap-2 p-4 rounded-xl bg-slate-700/30 hover:bg-slate-700/50 border border-slate-600/30 hover:border-slate-500/50 transition-all group"
          >
            <component :is="action.icon" class="w-6 h-6 text-slate-400 group-hover:text-amber-400 transition-colors" />
            <span class="text-xs font-medium text-slate-300 group-hover:text-white transition-colors">{{ action.label }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import LineChart from '@/components/charts/LineChart.vue'
import DoughnutChart from '@/components/charts/DoughnutChart.vue'
import {
  UsersIcon,
  BoltIcon,
  TicketIcon,
  EnvelopeIcon,
  TrashIcon,
  MegaphoneIcon,
  ServerIcon,
  DocumentTextIcon,
  ArrowPathIcon,
  UserPlusIcon,
  Cog6ToothIcon,
  KeyIcon,
  LifebuoyIcon
} from '@heroicons/vue/24/outline'

const router = useRouter()
const toast = useToast()
const loading = ref(false)
const dateRange = ref(7)

const stats = ref([
  {
    label: 'Total Users',
    value: '0',
    change: '+0',
    period: 'this period',
    trend: 'neutral',
    icon: UsersIcon,
    color: 'bg-blue-500',
    gradient: 'from-blue-500 to-cyan-500'
  },
  {
    label: 'Active Users',
    value: '0',
    change: '0%',
    period: 'of total',
    trend: 'neutral',
    icon: BoltIcon,
    color: 'bg-emerald-500',
    gradient: 'from-emerald-500 to-teal-500'
  },
  {
    label: 'Open Tickets',
    value: '0',
    change: '0',
    period: 'pending',
    trend: 'neutral',
    icon: TicketIcon,
    color: 'bg-orange-500',
    gradient: 'from-orange-500 to-amber-500'
  },
  {
    label: 'Emails Sent',
    value: '0',
    change: '',
    period: 'this period',
    trend: 'neutral',
    icon: EnvelopeIcon,
    color: 'bg-violet-500',
    gradient: 'from-violet-500 to-purple-500'
  }
])

const systemHealth = ref([
  { label: 'API Server', status: 'online' },
  { label: 'Database', status: 'online' },
  { label: 'Queue Worker', status: 'online' },
  { label: 'Cache (Redis)', status: 'online' }
])

const quickActions = ref([
  { label: 'Clear Cache', icon: TrashIcon, handler: () => clearCache() },
  { label: 'Announcement', icon: MegaphoneIcon, handler: () => router.push('/announcements') },
  { label: 'Backup DB', icon: ServerIcon, handler: () => router.push('/backups') },
  { label: 'View Logs', icon: DocumentTextIcon, handler: () => router.push('/error-logs') },
  { label: 'Add User', icon: UserPlusIcon, handler: () => router.push('/users?action=add') },
  { label: 'Settings', icon: Cog6ToothIcon, handler: () => router.push('/settings') },
  { label: 'API Keys', icon: KeyIcon, handler: () => router.push('/api-keys') },
  { label: 'Support', icon: LifebuoyIcon, handler: () => router.push('/tickets') }
])

const activityChart = ref({
  labels: [],
  datasets: []
})

const userDistribution = ref({
  labels: ['Admins', 'Moderators', 'Users'],
  data: [0, 0, 0]
})

const retention = ref([])
const hourlyActivity = ref([])
const recentActivity = ref([])

const formatMoney = (value) => {
  if (!value) return '0'
  if (value >= 1000000000) return (value / 1000000000).toFixed(1) + 'B'
  if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M'
  if (value >= 1000) return (value / 1000).toFixed(1) + 'K'
  return value.toLocaleString()
}

const getHeatmapColor = (value) => {
  if (value === 0) return 'bg-slate-700'
  if (value < 25) return 'bg-emerald-900'
  if (value < 50) return 'bg-emerald-700'
  if (value < 75) return 'bg-emerald-500'
  return 'bg-emerald-400'
}

const clearCache = async () => {
  try {
    await api.post('/admin/cache/clear')
    toast.success('Cache cleared successfully!')
  } catch (error) {
    toast.error('Failed to clear cache')
  }
}

const loadStats = async () => {
  loading.value = true
  try {
    const response = await api.get('/admin/stats', {
      params: { days: dateRange.value }
    })

    if (response.data) {
      const data = response.data

      // Update stat cards
      stats.value[0].value = (data.totalUsers || 0).toLocaleString()
      stats.value[0].change = data.newUsers?.count !== undefined
        ? `+${data.newUsers.count}`
        : `+${data.newUsers || 0}`
      stats.value[0].trend = (data.newUsers?.change || 0) >= 0 ? 'up' : 'down'

      stats.value[1].value = (data.activeUsers || 0).toLocaleString()
      stats.value[1].change = `${data.activePercentage || 0}%`
      stats.value[1].trend = (data.activePercentage || 0) > 10 ? 'up' : 'neutral'

      stats.value[2].value = (data.openTickets || 0).toLocaleString()
      stats.value[2].change = data.pendingTickets || '0'
      stats.value[2].trend = 'neutral'

      stats.value[3].value = (data.emailsSent || 0).toLocaleString()

      // Update activity chart
      if (data.activityChart) {
        activityChart.value = {
          labels: data.activityChart.labels || [],
          datasets: data.activityChart.datasets || []
        }
      }

      // Update user distribution
      if (data.userDistribution) {
        userDistribution.value = {
          labels: data.userDistribution.labels || ['Admins', 'Moderators', 'Users'],
          data: data.userDistribution.data || [0, 0, 0]
        }
      }

      // Update retention
      if (data.retention) {
        retention.value = data.retention
      }

      // Update hourly activity
      if (data.hourlyActivity) {
        hourlyActivity.value = data.hourlyActivity
      }

      // Update recent activity
      if (data.recentActivity) {
        recentActivity.value = data.recentActivity
      }
    }
  } catch (error) {
    console.error('Failed to load dashboard stats:', error)
    toast.error('Failed to load dashboard statistics')
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadStats()
})
</script>
