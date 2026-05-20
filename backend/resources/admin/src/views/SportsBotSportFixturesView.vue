<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">{{ label }}</h1>
        <p class="text-slate-400 text-sm mt-1">{{ description }}</p>
      </div>
      <button @click="loadPreview" :disabled="loadingPreview" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
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
        <p class="text-slate-400 text-sm">Events</p>
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
          <h2 class="text-lg font-semibold text-white">{{ emoji }} {{ label }} Preview</h2>
          <p class="text-xs text-slate-400">Routes: {{ displayRoutes }} · one card per event</p>
        </div>
        <div class="flex items-center gap-2">
          <select v-model="cardVersion" class="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white" @change="loadPreview">
            <option value="v1">V1 cards</option>
            <option value="v2">V2 cards</option>
            <option value="v3">V3 polished cards</option>
          </select>
          <label class="inline-flex items-center gap-2 text-sm text-slate-300 px-3 py-2 rounded-xl bg-slate-900 border border-slate-700">
            <input v-model="captionsEnabled" type="checkbox" class="rounded bg-slate-900 border-slate-700" />
            Include captions
          </label>
          <button @click="testRoute" :disabled="testingRoute" class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60">
            {{ testingRoute ? 'Testing...' : 'Test Routes' }}
          </button>
          <button @click="sendFixtures" :disabled="sending" class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60">
            {{ sending ? 'Sending...' : 'Send to Assigned Topics' }}
          </button>
        </div>
      </div>

      <div v-if="routeStatus.fallback" class="rounded-xl border border-amber-600/40 bg-amber-500/10 p-3 text-sm text-amber-200">
        One or more selected routes are falling back to the default Telegram target. Assign each sport route in Telegram Routes before sending.
      </div>

      <pre class="whitespace-pre-wrap text-sm text-slate-100 bg-slate-900/80 border border-slate-700 rounded-xl p-4 min-h-[260px]">{{ previewMessage || 'No preview loaded yet.' }}</pre>
    </div>

    <div v-if="leagueCardPreview" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div>
        <h2 class="text-lg font-semibold text-white">League Header Card</h2>
        <p class="text-xs text-slate-400">Sent before each league's fixture cards.</p>
        <p class="text-xs text-slate-500 mt-2">{{ leagueCardPreview.leagues.length }} leagues will get headers:</p>
        <div class="flex flex-wrap gap-1 mt-2">
          <span v-for="league in leagueCardPreview.leagues" :key="league" class="px-2 py-0.5 rounded text-xs bg-slate-700/60 text-slate-300">{{ league }}</span>
        </div>
      </div>
      <div v-if="leagueCardPreview.card" class="rounded-2xl bg-slate-900 border border-slate-700 overflow-hidden max-w-lg mx-auto">
        <img :src="leagueCardPreview.card.data_url" :alt="leagueCardPreview.card.name" class="w-full block" />
        <div class="p-3">
          <p class="text-white font-semibold text-sm">{{ leagueCardPreview.card.name }}</p>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Card Preview</h2>
      <div v-if="cardPreviews.length === 0" class="text-sm text-slate-400">No card previews generated yet.</div>
      <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div v-for="card in cardPreviews" :key="`${card.sport || 'sport'}-${card.event_id || card.title}`" class="rounded-2xl bg-slate-900 border border-slate-700 overflow-hidden">
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
      <h2 class="text-lg font-semibold text-white mb-4">Recent Assigned Route Sends</h2>
      <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No recent sends.</div>
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

const props = defineProps({
  sport: { type: String, default: '' },
  sports: { type: Array, default: () => [] },
  label: { type: String, default: 'Sport Fixtures' },
  routeKey: { type: String, required: true },
  emoji: { type: String, default: '🏅' },
  description: { type: String, default: '' },
})

const toast = useToast()
const loadingPreview = ref(false)
const testingRoute = ref(false)
const sending = ref(false)
const routeStatus = ref({})
const summary = ref({})
const previewMessage = ref('')
const cardPreviews = ref([])
const leagueCardPreview = ref(null)
const recentMessages = ref([])
const captionsEnabled = ref(false)
const cardVersion = ref('v3')
const routeKeysForActions = ref([])

const sportEntries = computed(() => {
  if (props.sports.length > 0) {
    return props.sports
  }

  return [{
    sport: props.sport,
    label: props.label,
    routeKey: props.routeKey,
  }]
})

const displayRoutes = computed(() => {
  const keys = routeKeysForActions.value.length > 0 ? routeKeysForActions.value : fallbackRouteKeys()

  return keys.join(', ')
})

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'sending') return 'bg-amber-500/20 text-amber-400'
  return 'bg-slate-700 text-slate-300'
}

async function loadRecentMessages() {
  try {
    const responses = await Promise.all(fallbackRouteKeys().map((routeKey) => (
      api.get('/admin/sportsbot/telegram/messages', {
        params: { route_key: routeKey, limit: 20 },
      })
    )))
    recentMessages.value = responses
      .flatMap((response) => response.data?.messages || [])
      .sort((a, b) => Number(b.id || 0) - Number(a.id || 0))
      .slice(0, 20)
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load recent sends')
  }
}

async function loadPreview() {
  loadingPreview.value = true
  try {
    const responses = await Promise.all(sportEntries.value.map((entry) => (
      api.post(`/admin/sportsbot/fixtures/${entry.sport}/preview`, {
        card_version: cardVersion.value,
      })
    )))
    const previews = responses.map((response, index) => ({
      entry: sportEntries.value[index],
      data: response.data || {},
    }))
    routeKeysForActions.value = uniqueRouteKeys(previews.map(({ entry, data }) => (
      data.route_key || entry.routeKey || routeKeyForSport(entry.sport) || props.routeKey
    )))

    previewMessage.value = previews
      .map(({ entry, data }) => [`${entry.label || entry.sport}`, data.message || 'No fixtures found.'].join('\n'))
      .join('\n\n')
    routeStatus.value = aggregateRouteStatuses(previews.map(({ data }) => data.route_status || {}))
    summary.value = previews.reduce((carry, { data }) => {
      carry.fixtures_total += Number(data.summary?.fixtures_total || 0)
      return carry
    }, { fixtures_total: 0 })
    cardPreviews.value = previews.flatMap(({ entry, data }) => (
      (data.card_previews || []).map((card) => ({ ...card, sport: entry.sport }))
    ))
    leagueCardPreview.value = previews.reduce((found, { data }) => found || data.league_card_preview || null, null)
    captionsEnabled.value = previews.some(({ data }) => Boolean(data.captions_enabled))
    cardVersion.value = previews[0]?.data?.card_version || cardVersion.value
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load preview')
  } finally {
    loadingPreview.value = false
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const responses = await Promise.all(fallbackRouteKeys().map((routeKey) => (
      api.post('/admin/sportsbot/test-route', {
        route_key: routeKey,
        send: true,
      })
    )))
    const sentCount = responses.reduce((carry, response) => carry + (response.data?.results || []).length, 0)
    routeStatus.value = aggregateRouteStatuses(responses.map((response) => response.data?.resolved || {}))
    toast.success(`Route tests sent (${sentCount} target(s))`)
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Route test failed')
  } finally {
    testingRoute.value = false
  }
}

async function sendFixtures() {
  sending.value = true
  try {
    const responses = []
    for (const entry of sportEntries.value) {
      const response = await api.post(`/admin/sportsbot/fixtures/${entry.sport}/send`, {
        captions_enabled: captionsEnabled.value,
        card_version: cardVersion.value,
      })
      responses.push({ entry, data: response.data || {} })
    }

    previewMessage.value = responses
      .map(({ entry, data }) => [`${entry.label || entry.sport}`, data.message || 'Sent.'].join('\n'))
      .join('\n\n')
    routeStatus.value = responses[0]?.data?.route_status || routeStatus.value
    routeStatus.value = aggregateRouteStatuses(responses.map(({ data }) => data.route_status || {}))
    summary.value = responses.reduce((carry, { data }) => {
      carry.fixtures_total += Number(data.summary?.fixtures_total || 0)
      return carry
    }, { fixtures_total: 0 })
    const sentCount = responses.reduce((carry, { data }) => carry + (data.results || []).length, 0)
    toast.success(`Events sent (${sentCount} photo post(s))`)
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send events')
  } finally {
    sending.value = false
  }
}

onMounted(loadPreview)

function fallbackRouteKeys() {
  if (routeKeysForActions.value.length > 0) {
    return routeKeysForActions.value
  }

  return uniqueRouteKeys(sportEntries.value.map((entry) => entry.routeKey || routeKeyForSport(entry.sport) || props.routeKey))
}

function routeKeyForSport(sport) {
  const key = String(sport || '')
  const map = {
    basketball: 'BASKETBALL',
    baseball: 'BASEBALL',
    american_football: 'AMERICAN_FOOTBALL',
    ice_hockey: 'ICE_HOCKEY',
    tennis: 'TENNIS',
    cricket: 'CRICKET',
    golf: 'GOLF',
    formula_1: 'FORMULA_1',
    motorsport: 'MOTORSPORT_OTHER',
    mma: 'MMA',
    boxing: 'BOXING',
  }

  return map[key] || ''
}

function uniqueRouteKeys(items) {
  return [...new Set(items.map(item => String(item || '').trim()).filter(Boolean))]
}

function aggregateRouteStatuses(statuses) {
  const filtered = statuses.filter(Boolean)

  return {
    route_key: displayRoutes.value,
    resolved_route_key: uniqueRouteKeys(filtered.map(status => status.resolved_route_key || status.route_key)).join(', '),
    fallback: filtered.some(status => Boolean(status.fallback)),
    target_count: filtered.reduce((total, status) => total + Number(status.target_count || 0), 0),
    targets: filtered.flatMap(status => status.targets || []),
    source: uniqueRouteKeys(filtered.map(status => status.source)).join(', '),
  }
}
</script>
