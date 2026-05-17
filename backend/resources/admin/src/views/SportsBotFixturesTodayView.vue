<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Fixtures Today</h1>
        <p class="text-slate-400 text-sm mt-1">Preview and publish the Fixtures Today message to Telegram.</p>
      </div>
      <div class="flex items-center gap-3">
        <button
          @click="refreshStatus"
          :disabled="loading"
          class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
        >
          Refresh
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Route Targets</p>
        <p class="text-3xl font-bold text-white mt-2">{{ routeStatus.target_count ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Fallback</p>
        <p class="text-3xl font-bold mt-2" :class="routeStatus.fallback ? 'text-amber-400' : 'text-emerald-400'">
          {{ routeStatus.fallback ? 'true' : 'false' }}
        </p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Latest Run</p>
        <p class="text-sm text-white mt-2">{{ latestRunText }}</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-semibold text-white">Fixtures Preview</h2>
          <p class="text-xs text-slate-400">Route: FIXTURES_TODAY (resolved: {{ routeStatus.resolved_route_key || 'default' }})</p>
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="loadPreview"
            :disabled="loadingPreview"
            class="px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 disabled:opacity-60"
          >
            {{ loadingPreview ? 'Loading...' : 'Refresh Preview' }}
          </button>
          <button
            @click="testRoute"
            :disabled="testingRoute"
            class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
          >
            {{ testingRoute ? 'Testing...' : 'Test Route' }}
          </button>
          <button
            @click="syncTopics"
            :disabled="syncingTopics"
            class="px-4 py-2 rounded-xl bg-amber-700 text-white hover:bg-amber-600 disabled:opacity-60"
          >
            {{ syncingTopics ? 'Syncing...' : 'Sync Topics' }}
          </button>
          <button
            @click="sendFixtures"
            :disabled="sendingFixtures"
            class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
          >
            {{ sendingFixtures ? 'Sending...' : 'Send Fixtures Today' }}
          </button>
        </div>
      </div>

      <pre class="whitespace-pre-wrap text-sm text-slate-100 bg-slate-900/80 border border-slate-700 rounded-xl p-4 min-h-[260px]">{{ previewMessage || 'No preview loaded yet.' }}</pre>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between mb-4">
        <div>
          <h2 class="text-lg font-semibold text-white">Discovered Telegram Topics</h2>
          <p class="text-xs text-slate-400">Learnt from Telegram getUpdates, including /topic and /settopic labels.</p>
        </div>
      </div>

      <div v-if="telegramTopics.length === 0" class="text-sm text-slate-400">No Telegram topics discovered yet.</div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-slate-400 border-b border-slate-700">
            <tr>
              <th class="text-left py-2">Topic</th>
              <th class="text-left py-2">Target</th>
              <th class="text-left py-2">Source</th>
              <th class="text-left py-2">Last Seen</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="topic in telegramTopics" :key="`${topic.chat_id}:${topic.message_thread_id}`" class="border-b border-slate-800">
              <td class="py-2 text-slate-300">{{ topic.title || 'Untitled topic' }}</td>
              <td class="py-2 text-slate-300">{{ topic.chat_id }}:{{ topic.message_thread_id ?? '-' }}</td>
              <td class="py-2 text-slate-300">{{ topic.source || '-' }}</td>
              <td class="py-2 text-slate-300">{{ topic.last_seen_at || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-white">Recent Sends / Errors</h2>
      </div>

      <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No recent telegram messages.</div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-slate-400 border-b border-slate-700">
            <tr>
              <th class="text-left py-2">ID</th>
              <th class="text-left py-2">Status</th>
              <th class="text-left py-2">Target</th>
              <th class="text-left py-2">Type</th>
              <th class="text-left py-2">Sent At</th>
              <th class="text-left py-2">Error</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in recentMessages" :key="row.id" class="border-b border-slate-800">
              <td class="py-2 text-slate-300">{{ row.id }}</td>
              <td class="py-2">
                <span :class="statusClass(row.status)" class="px-2 py-1 rounded text-xs font-medium">{{ row.status }}</span>
              </td>
              <td class="py-2 text-slate-300">{{ row.chat_id }}:{{ row.message_thread_id ?? '-' }}</td>
              <td class="py-2 text-slate-300">{{ row.type }}</td>
              <td class="py-2 text-slate-300">{{ row.sent_at || '-' }}</td>
              <td class="py-2 text-red-300 max-w-sm truncate" :title="row.error || ''">{{ row.error || '-' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const loadingPreview = ref(false)
const testingRoute = ref(false)
const sendingFixtures = ref(false)
const syncingTopics = ref(false)

const routeStatus = ref({})
const previewMessage = ref('')
const latestRun = ref(null)
const recentMessages = ref([])
const telegramTopics = ref([])

const latestRunText = computed(() => {
  if (!latestRun.value) return 'No runs yet'
  return `${latestRun.value.status || 'unknown'} (${latestRun.value.finished_at || latestRun.value.created_at || 'n/a'})`
})

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'sending') return 'bg-amber-500/20 text-amber-400'
  return 'bg-slate-700 text-slate-300'
}

async function refreshStatus() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/status')
    routeStatus.value = data.route_status || {}
    latestRun.value = data.latest_run || null
    recentMessages.value = data.recent_telegram_messages || []
    telegramTopics.value = data.recent_telegram_topics || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load SportsBot status')
  } finally {
    loading.value = false
  }
}

async function loadPreview() {
  loadingPreview.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixtures-today/preview')
    previewMessage.value = data.message || ''
    routeStatus.value = data.route_status || routeStatus.value
    toast.success('Preview loaded')
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load fixtures preview')
  } finally {
    loadingPreview.value = false
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/test-route', {
      route_key: 'FIXTURES_TODAY',
      send: true,
    })
    routeStatus.value = data.resolved || routeStatus.value
    toast.success(`Route test sent (${(data.results || []).length} target(s))`)
    await refreshStatus()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Route test failed')
  } finally {
    testingRoute.value = false
  }
}

async function syncTopics() {
  syncingTopics.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/telegram/topics/sync', {
      limit: 100,
      timeout: 0,
    })
    telegramTopics.value = data.topics || telegramTopics.value
    const summary = data.summary || {}
    toast.success(`Topic sync complete (${summary.topics_saved || 0} topic update(s))`)
    await refreshStatus()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to sync Telegram topics')
  } finally {
    syncingTopics.value = false
  }
}

async function sendFixtures() {
  sendingFixtures.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixtures-today/send')
    previewMessage.value = data.message || previewMessage.value
    routeStatus.value = data.route_status || routeStatus.value
    toast.success(`Fixtures sent (${(data.results || []).length} target(s))`)
    await refreshStatus()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send Fixtures Today')
  } finally {
    sendingFixtures.value = false
  }
}

onMounted(async () => {
  await refreshStatus()
  await loadPreview()
})
</script>
