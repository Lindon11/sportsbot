<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Football Fixtures TV</h1>
        <p class="text-slate-400 text-sm mt-1">Preview cards and publish football TV listings to the FOOTBALL Telegram topic.</p>
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
        <p class="text-slate-400 text-sm">Football Fixtures</p>
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
          <h2 class="text-lg font-semibold text-white">Football Topic Preview</h2>
          <p class="text-xs text-slate-400">Route: FOOTBALL · one card per fixture · no inline buttons</p>
        </div>
        <div class="flex items-center gap-2">
          <label class="block text-sm text-slate-300">
            <span class="sr-only">Card version</span>
            <select
              v-model="cardVersion"
              class="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white"
              @change="loadPreview"
            >
              <option value="v1">V1 cards</option>
              <option value="v2">V2 clean cards</option>
              <option value="v3">V3 polished cards</option>
            </select>
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-300 px-3 py-2 rounded-xl bg-slate-900 border border-slate-700">
            <input v-model="captionsEnabled" type="checkbox" class="rounded bg-slate-900 border-slate-700" />
            Include captions
          </label>
          <button
            @click="testRoute"
            :disabled="testingRoute"
            class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
          >
            {{ testingRoute ? 'Testing...' : 'Test Football Route' }}
          </button>
          <button
            @click="sendFootballFixtures"
            :disabled="sending"
            class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
          >
            {{ sending ? 'Sending...' : 'Send to Football Topic' }}
          </button>
        </div>
      </div>

      <div v-if="routeStatus.fallback" class="rounded-xl border border-amber-600/40 bg-amber-500/10 p-3 text-sm text-amber-200">
        FOOTBALL is falling back to the default Telegram target. Assign the FOOTBALL route in Telegram Routes before sending to the topic.
      </div>

      <pre class="whitespace-pre-wrap text-sm text-slate-100 bg-slate-900/80 border border-slate-700 rounded-xl p-4 min-h-[260px]">{{ previewMessage || 'No preview loaded yet.' }}</pre>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div>
        <h2 class="text-lg font-semibold text-white">League Header Cards</h2>
        <p class="text-xs text-slate-400">Sent before each league's fixture cards. Click a league to generate its header card.</p>
        <p class="text-xs text-slate-500 mt-2">{{ allLeagues.length }} football leagues:</p>
        <div class="flex flex-wrap gap-1 mt-2">
          <button v-for="league in allLeagues" :key="league.league_id" @click="previewLeagueHeader(league.name)" class="px-2 py-0.5 rounded text-xs transition-colors cursor-pointer" :class="league.has_cache ? 'bg-emerald-700/50 text-emerald-300 hover:bg-emerald-700' : (selectedLeague === league.name ? 'bg-purple-700 text-white ring-2 ring-purple-500' : 'bg-slate-700/60 text-slate-300 hover:bg-purple-700 hover:text-white')">{{ league.name }}{{ league.has_cache ? ' ✓' : '' }}</button>
        </div>
      </div>
      <div v-if="selectedLeagueHeader" class="rounded-2xl bg-slate-900 border border-slate-700 overflow-hidden max-w-lg mx-auto">
        <img :src="selectedLeagueHeader.data_url" :alt="selectedLeagueHeader.name" class="w-full block" />
        <div class="p-3">
          <p class="text-white font-semibold text-sm">{{ selectedLeagueHeader.name }}</p>
        </div>
      </div>
      <div v-else-if="generatingLeague" class="rounded-2xl bg-slate-900 border border-slate-700 p-8 max-w-lg mx-auto text-center">
        <p class="text-slate-400 text-sm">Generating {{ generatingLeague }} header card...</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-semibold text-white">Card Preview</h2>
          <p class="text-xs text-slate-400">Showing the first {{ cardPreviews.length }} generated {{ cardVersion.toUpperCase() }} card{{ cardPreviews.length === 1 ? '' : 's' }} from the current fixture list.</p>
        </div>
      </div>

      <div v-if="cardPreviews.length === 0" class="text-sm text-slate-400">No card previews generated yet.</div>
      <div v-else class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div
          v-for="card in cardPreviews"
          :key="card.event_id || card.title"
          class="rounded-2xl bg-slate-900 border border-slate-700 overflow-hidden"
        >
          <img :src="card.data_url" :alt="card.title" class="w-full block" />
          <div class="p-3">
            <p class="text-white font-semibold text-sm">{{ card.title }}</p>
            <p class="text-slate-400 text-xs mt-1">{{ card.league }} · {{ card.time || 'Kickoff TBC' }}</p>
            <p class="text-slate-300 text-xs mt-1">UK TV: {{ card.tv_channel || 'TBC' }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Recent FOOTBALL Sends</h2>
      <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No recent football fixture sends.</div>
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
import { onMounted, ref } from 'vue'
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
const leagueCardPreview = ref(null)
const allLeagues = ref([])
const selectedLeague = ref(null)
const selectedLeagueHeader = ref(null)
const generatingLeague = ref(null)
const recentMessages = ref([])
const captionsEnabled = ref(false)
const cardVersion = ref('v3')

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'sending') return 'bg-amber-500/20 text-amber-400'
  return 'bg-slate-700 text-slate-300'
}

async function loadRecentMessages() {
  try {
    const { data } = await api.get('/admin/sportsbot/telegram/messages', {
      params: {
        route_key: 'FOOTBALL',
        limit: 20,
      },
    })
    recentMessages.value = data.messages || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load recent football sends')
  }
}

async function loadPreview() {
  loadingPreview.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/football-fixtures/preview', {
      card_version: cardVersion.value,
    })
    previewMessage.value = data.message || ''
    routeStatus.value = data.route_status || {}
    summary.value = data.summary || {}
    cardPreviews.value = data.card_previews || []
    leagueCardPreview.value = data.league_card_preview || null
    allLeagues.value = data.all_leagues || []
    captionsEnabled.value = Boolean(data.captions_enabled)
    cardVersion.value = data.card_version || cardVersion.value
    const cached = (data.all_leagues || []).find(l => l.has_cache)
    if (cached) {
      selectedLeague.value = cached.name
      selectedLeagueHeader.value = cached
    } else if (data.league_card_preview?.card) {
      selectedLeague.value = data.league_card_preview.card.name
      selectedLeagueHeader.value = data.league_card_preview.card
    }
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load football fixture preview')
  } finally {
    loadingPreview.value = false
  }
}

async function previewLeagueHeader(leagueName) {
  const existing = allLeagues.value.find(l => l.name === leagueName)
  if (existing?.has_cache && existing?.data_url) {
    selectedLeague.value = leagueName
    selectedLeagueHeader.value = existing
    return
  }
  selectedLeague.value = leagueName
  generatingLeague.value = leagueName
  selectedLeagueHeader.value = null
  try {
    const { data } = await api.post('/admin/sportsbot/football-fixtures/preview', {
      card_version: cardVersion.value,
      preview_league: leagueName,
    })
    allLeagues.value = data.all_leagues || []
    const updated = (data.all_leagues || []).find(l => l.name === leagueName)
    if (updated?.data_url) {
      selectedLeagueHeader.value = updated
    }
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to generate header card')
  } finally {
    generatingLeague.value = null
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/test-route', {
      route_key: 'FOOTBALL',
      send: true,
    })
    routeStatus.value = data.resolved || routeStatus.value
    toast.success(`Football route test sent (${(data.results || []).length} target(s))`)
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Football route test failed')
  } finally {
    testingRoute.value = false
  }
}

async function sendFootballFixtures() {
  sending.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/football-fixtures/send', {
      captions_enabled: captionsEnabled.value,
      card_version: cardVersion.value,
    })
    previewMessage.value = data.message || previewMessage.value
    routeStatus.value = data.route_status || routeStatus.value
    summary.value = data.summary || summary.value
    toast.success(`Football fixtures sent (${(data.results || []).length} photo post(s))`)
    await loadRecentMessages()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send football fixtures')
  } finally {
    sending.value = false
  }
}

onMounted(loadPreview)
</script>
