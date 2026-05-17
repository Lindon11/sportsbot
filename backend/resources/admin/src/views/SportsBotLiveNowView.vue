<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Live Now</h1>
        <p class="text-slate-400 text-sm mt-1">Preview and publish the current live sports digest through the LIVE_NOW Telegram route.</p>
      </div>
      <button
        @click="loadPreview"
        :disabled="loadingPreview"
        class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
      >
        {{ loadingPreview ? 'Refreshing...' : 'Refresh Preview' }}
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
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
        <p class="text-slate-400 text-sm">Live Matches</p>
        <p class="text-3xl font-bold text-white mt-2">{{ summary.live_total ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Sports Grouped</p>
        <p class="text-sm text-white mt-3">{{ sportsText }}</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-semibold text-white">Live Now Preview</h2>
          <p class="text-xs text-slate-400">Route: LIVE_NOW (resolved: {{ routeStatus.resolved_route_key || 'default' }})</p>
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="testRoute"
            :disabled="testingRoute"
            class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
          >
            {{ testingRoute ? 'Testing...' : 'Test Route' }}
          </button>
          <button
            @click="sendLiveNow"
            :disabled="sending"
            class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
          >
            {{ sending ? 'Sending...' : 'Send Live Now' }}
          </button>
        </div>
      </div>

      <pre class="whitespace-pre-wrap text-sm text-slate-100 bg-slate-900/80 border border-slate-700 rounded-xl p-4 min-h-[320px]">{{ previewMessage || 'No preview loaded yet.' }}</pre>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-white">Recent LIVE_NOW Sends / Errors</h2>
        <button
          @click="loadMessages"
          :disabled="loadingMessages"
          class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white text-sm hover:bg-slate-700 disabled:opacity-60"
        >
          Refresh Sends
        </button>
      </div>

      <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No LIVE_NOW telegram messages yet.</div>

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

const loadingPreview = ref(false)
const loadingMessages = ref(false)
const testingRoute = ref(false)
const sending = ref(false)
const routeStatus = ref({})
const summary = ref({})
const previewMessage = ref('')
const recentMessages = ref([])

const sportsText = computed(() => {
  const sports = summary.value.sports_grouped || []
  return sports.length ? sports.join(', ') : 'None right now'
})

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'sending') return 'bg-amber-500/20 text-amber-400'
  return 'bg-slate-700 text-slate-300'
}

async function loadPreview() {
  loadingPreview.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/live-now/preview')
    previewMessage.value = data.message || ''
    routeStatus.value = data.route_status || {}
    summary.value = data.summary || {}
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load Live Now preview')
  } finally {
    loadingPreview.value = false
  }
}

async function loadMessages() {
  loadingMessages.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/telegram/messages', {
      params: {
        route_key: 'LIVE_NOW',
        limit: 20,
      },
    })
    recentMessages.value = data.messages || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load Live Now sends')
  } finally {
    loadingMessages.value = false
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/test-route', {
      route_key: 'LIVE_NOW',
      send: true,
    })
    routeStatus.value = data.resolved || routeStatus.value
    toast.success(`Route test sent (${(data.results || []).length} target(s))`)
    await loadMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Route test failed')
  } finally {
    testingRoute.value = false
  }
}

async function sendLiveNow() {
  sending.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/live-now/send')
    previewMessage.value = data.message || previewMessage.value
    routeStatus.value = data.route_status || routeStatus.value
    summary.value = data.summary || summary.value
    toast.success(`Live Now sent (${(data.results || []).length} target(s))`)
    await loadMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send Live Now')
  } finally {
    sending.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadPreview(), loadMessages()])
})
</script>
