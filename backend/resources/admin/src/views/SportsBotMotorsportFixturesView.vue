<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Motorsport Fixtures TV</h1>
        <p class="text-slate-400 text-sm mt-1">Preview today's F1, MotoGP, NASCAR, IndyCar, WRC and race listings for their assigned Telegram topics.</p>
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
        <p class="text-slate-400 text-sm">Resolved Route</p>
        <p class="text-lg font-semibold text-white mt-3">{{ routeStatus.resolved_route_key || 'default' }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Race Events</p>
        <p class="text-3xl font-bold text-white mt-2">{{ summary.fixtures_total ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Fallback</p>
        <p class="text-3xl font-bold mt-2" :class="routeStatus.fallback ? 'text-amber-400' : 'text-emerald-400'">
          {{ routeStatus.fallback ? 'true' : 'false' }}
        </p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-semibold text-white">Motorsport Topic Preview</h2>
          <p class="text-xs text-slate-400">Routes: {{ displayRoutes }} · one card per event · no inline buttons</p>
        </div>
        <div class="flex items-center gap-2">
          <select v-model="cardVersion" class="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white" @change="loadPreview">
            <option value="v1">V1 cards</option>
            <option value="v2">V2 race cards</option>
            <option value="v3">V3 polished cards</option>
          </select>
          <label class="inline-flex items-center gap-2 text-sm text-slate-300 px-3 py-2 rounded-xl bg-slate-900 border border-slate-700">
            <input v-model="captionsEnabled" type="checkbox" class="rounded bg-slate-900 border-slate-700" />
            Include captions
          </label>
          <button @click="testRoute" :disabled="testingRoute" class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60">
            {{ testingRoute ? 'Testing...' : 'Test Routes' }}
          </button>
          <button @click="sendMotorsportFixtures" :disabled="sending" class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60">
            {{ sending ? 'Sending...' : 'Send to Assigned Topics' }}
          </button>
        </div>
      </div>

      <div v-if="routeStatus.fallback" class="rounded-xl border border-amber-600/40 bg-amber-500/10 p-3 text-sm text-amber-200">
        One or more motorsport routes are falling back to the default Telegram target. Assign the series routes in Telegram Routes before sending.
      </div>

      <pre class="whitespace-pre-wrap text-sm text-slate-100 bg-slate-900/80 border border-slate-700 rounded-xl p-4 min-h-[260px]">{{ previewMessage || 'No preview loaded yet.' }}</pre>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Card Preview</h2>
      <div v-if="cardPreviews.length === 0" class="text-sm text-slate-400">No card previews generated yet.</div>
      <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div v-for="card in cardPreviews" :key="card.event_id || card.title" class="rounded-2xl bg-slate-900 border border-slate-700 overflow-hidden">
          <img :src="card.data_url" :alt="card.title" class="w-full block" />
          <div class="p-3">
            <p class="text-white font-semibold text-sm">{{ card.title }}</p>
            <p class="text-slate-400 text-xs mt-1">{{ card.league }} · {{ card.time || 'Time TBC' }}</p>
            <p class="text-slate-300 text-xs mt-1">UK TV: {{ card.tv_channel || 'Not listed' }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Recent Motorsport Sends</h2>
      <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No recent motorsport sends.</div>
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
              <td class="py-2"><span :class="statusClass(row.status)" class="px-2 py-1 rounded text-xs font-medium">{{ row.status }}</span></td>
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
const testingRoute = ref(false)
const sending = ref(false)
const routeStatus = ref({})
const summary = ref({})
const previewMessage = ref('')
const cardPreviews = ref([])
const recentMessages = ref([])
const captionsEnabled = ref(false)
const cardVersion = ref('v3')
const routeKeysForActions = ref(['FORMULA_1'])

const displayRoutes = computed(() => routeKeysForActions.value.join(', '))

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'sending') return 'bg-amber-500/20 text-amber-400'
  return 'bg-slate-700 text-slate-300'
}

async function loadRecentMessages() {
  try {
    const responses = await Promise.all(routeKeysForActions.value.map((routeKey) => (
      api.get('/admin/sportsbot/telegram/messages', {
        params: { route_key: routeKey, limit: 20 },
      })
    )))
    recentMessages.value = responses
      .flatMap((response) => response.data?.messages || [])
      .sort((a, b) => Number(b.id || 0) - Number(a.id || 0))
      .slice(0, 20)
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load recent motorsport sends')
  }
}

async function loadPreview() {
  loadingPreview.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/motorsport-fixtures/preview', {
      card_version: cardVersion.value,
    })
    previewMessage.value = data.message || ''
    routeStatus.value = data.route_status || {}
    summary.value = data.summary || {}
    cardPreviews.value = data.card_previews || []
    routeKeysForActions.value = uniqueRouteKeys([
      data.route_key,
      ...(data.card_previews || []).map((card) => card.route_key),
    ])
    captionsEnabled.value = Boolean(data.captions_enabled)
    cardVersion.value = data.card_version || cardVersion.value
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load motorsport preview')
  } finally {
    loadingPreview.value = false
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const responses = await Promise.all(routeKeysForActions.value.map((routeKey) => (
      api.post('/admin/sportsbot/test-route', {
        route_key: routeKey,
        send: true,
      })
    )))
    const sentCount = responses.reduce((carry, response) => carry + (response.data?.results || []).length, 0)
    routeStatus.value = aggregateRouteStatuses(responses.map((response) => response.data?.resolved || {}))
    toast.success(`Motorsport route tests sent (${sentCount} target(s))`)
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Motorsport route test failed')
  } finally {
    testingRoute.value = false
  }
}

async function sendMotorsportFixtures() {
  sending.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/motorsport-fixtures/send', {
      captions_enabled: captionsEnabled.value,
      card_version: cardVersion.value,
    })
    previewMessage.value = data.message || previewMessage.value
    routeStatus.value = data.route_status || routeStatus.value
    summary.value = data.summary || summary.value
    toast.success(`Motorsport events sent (${(data.results || []).length} photo post(s))`)
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send motorsport events')
  } finally {
    sending.value = false
  }
}

onMounted(loadPreview)

function uniqueRouteKeys(items) {
  const keys = [...new Set(items.map(item => String(item || '').trim()).filter(Boolean))]

  return keys.length > 0 ? keys : ['FORMULA_1']
}

function aggregateRouteStatuses(statuses) {
  const filtered = statuses.filter(Boolean)

  return {
    resolved_route_key: uniqueRouteKeys(filtered.map(status => status.resolved_route_key || status.route_key)).join(', '),
    fallback: filtered.some(status => Boolean(status.fallback)),
    target_count: filtered.reduce((total, status) => total + Number(status.target_count || 0), 0),
  }
}
</script>
