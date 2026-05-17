<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Dashboard</h1>
        <p class="text-slate-400 text-sm mt-1">Native bot health, routes, and recent activity.</p>
      </div>
      <button
        @click="loadStatus"
        :disabled="loading"
        class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
      >
        {{ loading ? 'Refreshing...' : 'Refresh' }}
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Health</p>
        <p class="text-2xl font-bold mt-2" :class="health.ok ? 'text-emerald-400' : 'text-red-400'">
          {{ health.ok ? 'OK' : 'Check' }}
        </p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Runs</p>
        <p class="text-3xl font-bold text-white mt-2">{{ counts.runs ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Tracked Matches</p>
        <p class="text-3xl font-bold text-white mt-2">{{ counts.tracked_matches ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Sent Alerts</p>
        <p class="text-3xl font-bold text-white mt-2">{{ counts.sent_alerts ?? 0 }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div
        v-for="(status, key) in routeStatuses"
        :key="key"
        class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4"
      >
        <div class="flex items-center justify-between gap-2">
          <p class="text-white font-semibold">{{ key }}</p>
          <span
            class="px-2 py-1 rounded text-xs font-medium"
            :class="status.fallback ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-500/20 text-emerald-300'"
          >
            {{ status.fallback ? 'fallback' : 'assigned' }}
          </span>
        </div>
        <p class="text-slate-400 text-xs mt-2">Targets: {{ status.target_count ?? 0 }}</p>
        <p class="text-slate-500 text-xs mt-1 truncate">{{ targetText(status.targets || []) }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Recent Runs</h2>
        <div v-if="recentRuns.length === 0" class="text-sm text-slate-400">No runs yet.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-slate-400 border-b border-slate-700">
              <tr>
                <th class="text-left py-2">ID</th>
                <th class="text-left py-2">Status</th>
                <th class="text-left py-2">Alerts</th>
                <th class="text-left py-2">Finished</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="run in recentRuns" :key="run.id" class="border-b border-slate-800">
                <td class="py-2 text-slate-300">{{ run.id }}</td>
                <td class="py-2 text-slate-300">{{ run.status || '-' }}</td>
                <td class="py-2 text-slate-300">{{ run.sent_alerts ?? run.generated_alerts ?? 0 }}</td>
                <td class="py-2 text-slate-300">{{ formatDate(run.finished_at || run.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Recent Telegram Messages</h2>
        <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No recent messages.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-slate-400 border-b border-slate-700">
              <tr>
                <th class="text-left py-2">Status</th>
                <th class="text-left py-2">Route</th>
                <th class="text-left py-2">Target</th>
                <th class="text-left py-2">Sent</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="message in recentMessages" :key="message.id" class="border-b border-slate-800">
                <td class="py-2"><span :class="statusClass(message.status)" class="px-2 py-1 rounded text-xs font-medium">{{ message.status }}</span></td>
                <td class="py-2 text-slate-300">{{ message.route_key || '-' }}</td>
                <td class="py-2 text-slate-300">{{ message.chat_id }}:{{ message.message_thread_id ?? '-' }}</td>
                <td class="py-2 text-slate-300">{{ formatDate(message.sent_at || message.created_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const health = ref({})
const counts = ref({})
const routeStatuses = ref({})
const recentRuns = ref([])
const recentMessages = ref([])

function targetText(targets) {
  if (!targets.length) return 'No targets resolved'
  return targets.map(target => `${target.chat_id}:${target.message_thread_id ?? '-'}`).join(', ')
}

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'sending') return 'bg-amber-500/20 text-amber-400'
  return 'bg-slate-700 text-slate-300'
}

async function loadStatus() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/status')
    health.value = data.health || {}
    counts.value = data.counts || {}
    routeStatuses.value = data.route_statuses || {}
    recentRuns.value = data.recent_runs || []
    recentMessages.value = data.recent_telegram_messages || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load SportsBot dashboard')
  } finally {
    loading.value = false
  }
}

onMounted(loadStatus)
</script>
