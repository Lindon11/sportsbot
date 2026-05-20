<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Coverage</h1>
        <p class="text-slate-400 text-sm mt-1">Sports, leagues, cards, cache and delivery settings.</p>
      </div>
      <div class="flex gap-2">
        <button @click="load" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
        <button @click="save" :disabled="saving" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 xl:col-span-2">
        <h2 class="text-lg font-semibold text-white mb-4">Enabled Sports</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label v-for="(sport, key) in sports" :key="key" class="flex items-center gap-3 rounded-xl border border-slate-700 bg-slate-900/50 px-4 py-3">
            <input v-model="form.enabled_sports" :value="sport.sport" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            <span class="text-xl">{{ sport.icon }}</span>
            <span>
              <span class="block text-white font-medium">{{ sport.label }}</span>
              <span class="block text-xs text-slate-400">{{ sport.route_key }}</span>
            </span>
          </label>
        </div>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Card Status</h2>
        <div class="space-y-3 text-sm">
          <div class="flex justify-between"><span class="text-slate-400">GD loaded</span><span class="text-white">{{ card.gd_loaded ? 'yes' : 'no' }}</span></div>
          <div class="flex justify-between"><span class="text-slate-400">Recent cards</span><span class="text-white">{{ card.recent_cards ?? 0 }}</span></div>
          <div class="flex justify-between"><span class="text-slate-400">Last card</span><span class="text-white">{{ formatDate(card.last_card_at) }}</span></div>
        </div>
        <div class="mt-5 space-y-3">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.cards_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Generate PNG cards
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.rich_cards_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Send rich media cards
          </label>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <div>
          <h2 class="text-lg font-semibold text-white">Discord Webhooks</h2>
          <p class="text-xs mt-1" :class="discordDiagnostics.configured ? 'text-emerald-300' : 'text-slate-500'">
            {{ discordDiagnostics.configured ? 'Webhook delivery configured' : 'Webhook delivery not configured' }}
          </p>
        </div>
        <button @click="sendDiscordDiagnostic" :disabled="sendingDiscord || !form.discord_enabled" class="px-4 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-60">
          {{ sendingDiscord ? 'Sending...' : 'Send Discord Test Card' }}
        </button>
      </div>
      <div class="space-y-4">
        <label class="flex items-center gap-2 text-sm text-slate-300">
          <input v-model="form.discord_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
          Send SportsBot posts to Discord webhooks
        </label>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <label class="block">
            <span class="block text-sm font-medium text-slate-300 mb-2">Default webhook URL</span>
            <input v-model="form.discord_default_webhook_url" type="password" autocomplete="off" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="https://discord.com/api/webhooks/...">
          </label>
          <label class="block">
            <span class="block text-sm font-medium text-slate-300 mb-2">Bot name</span>
            <input v-model="form.discord_username" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="SportsBot">
          </label>
        </div>

        <label class="block">
          <span class="block text-sm font-medium text-slate-300 mb-2">Avatar URL</span>
          <input v-model="form.discord_avatar_url" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="Optional image URL">
        </label>

        <div>
          <label class="block text-sm font-medium text-slate-300 mb-2">Route webhooks</label>
          <textarea v-model="discordRoutesText" rows="6" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="FOOTBALL=https://discord.com/api/webhooks/...\nFORMULA_1=https://discord.com/api/webhooks/..." />
          <p class="text-xs text-slate-500 mt-2">One route per line. If a route is missing, the default webhook is used.</p>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <div>
          <h2 class="text-lg font-semibold text-white">League Coverage</h2>
          <p class="text-xs text-slate-400 mt-1">{{ featuredCount }} extra enabled / {{ totalLeagueCount }} total across {{ sportWithLeagues }} sports — default leagues from config are always included</p>
        </div>
        <div class="flex items-center gap-2">
          <label class="flex items-center gap-1.5 text-xs text-slate-400">
            <input v-model="showFeaturedOnly" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Enabled only
          </label>
          <input v-model="searchQuery" type="text" class="w-48 rounded-xl bg-slate-900 border border-slate-700 text-white p-2 text-xs" placeholder="Search leagues..." />
        </div>
      </div>

      <div class="flex flex-wrap gap-2 mb-4 p-3 rounded-xl bg-slate-900/60 border border-slate-700/50">
        <input v-model="newLeagueId" type="text" class="w-28 rounded-lg bg-slate-800 border border-slate-600 text-white p-2 text-xs" placeholder="League ID" />
        <button @click="lookupNewLeague" :disabled="!newLeagueId.trim() || lookingUp" class="px-3 py-1.5 rounded-lg bg-sky-700 text-white text-xs hover:bg-sky-600 disabled:opacity-50">{{ lookingUp ? '...' : 'Find' }}</button>
        <span v-if="newLeagueResult" class="text-xs flex items-center gap-2">
          <img v-if="newLeagueResult.badge" :src="newLeagueResult.badge" class="w-5 h-5 rounded object-contain" />
          <span :class="newLeagueResult.found ? 'text-emerald-300' : 'text-red-300'">{{ newLeagueResult.name }}</span>
          <button v-if="newLeagueResult.found && !newLeagueResult.alreadyFeatured" @click="addLeagueToFeatured(newLeagueResult.id)" class="px-2 py-0.5 rounded bg-emerald-700 text-emerald-200 text-xs hover:bg-emerald-600">+ Add</button>
          <span v-if="newLeagueResult.alreadyFeatured" class="text-slate-400">already enabled ✓</span>
        </span>
      </div>

      <div v-for="(sport, key) in leaguesBySport" :key="key" class="mb-3 last:mb-0">
        <button @click="toggleSport(key)" class="w-full flex items-center justify-between gap-3 px-4 py-2.5 rounded-xl bg-slate-900/60 border border-slate-700/50 hover:border-slate-600 transition-colors">
          <span class="flex items-center gap-2">
            <span class="text-lg">{{ sport.icon }}</span>
            <span class="text-white font-medium text-sm">{{ sport.label }}</span>
            <span class="text-xs text-slate-500">{{ featuredSportCount(key) }} / {{ sport.leagues.length }}</span>
          </span>
          <span class="text-slate-500 text-xs">{{ expandedSports[key] ? '▲' : '▼' }}</span>
        </button>
        <div v-if="expandedSports[key]" class="mt-2 flex flex-wrap gap-1.5 px-2">
          <button v-for="league in filteredLeagues(key)" :key="league.id" @click="toggleLeague(league.id)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs transition-colors border" :class="league.featured ? 'bg-emerald-700/40 border-emerald-600/50 text-emerald-200 hover:bg-emerald-700/60' : 'bg-slate-800/60 border-slate-700/50 text-slate-400 hover:bg-slate-700/60 hover:text-slate-200'">
            <span v-if="league.featured" class="text-emerald-300 font-bold">✓</span>
            <img v-if="league.badge && !league.featured" :src="league.badge" class="w-4 h-4 rounded object-contain" />
            <span>{{ league.name }}</span>
          </button>
          <span v-if="filteredLeagues(key).length === 0" class="text-xs text-slate-500 italic px-2 py-1">no matches</span>
        </div>
      </div>
      <div v-if="totalLeagueCount === 0" class="text-sm text-slate-500 text-center py-4">Loading leagues...</div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">TV Channels</h2>
        <textarea v-model="channelText" rows="8" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="One channel per line" />
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <h2 class="text-lg font-semibold text-white">Telegram Diagnostics</h2>
        <button @click="sendDiagnostic" :disabled="sending" class="px-4 py-2 rounded-xl bg-sky-600 text-white hover:bg-sky-500 disabled:opacity-60">
          {{ sending ? 'Sending...' : 'Send Rich Test Card' }}
        </button>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
        <div v-for="(status, key) in routeStatuses" :key="key" class="rounded-xl border border-slate-700 bg-slate-900/40 p-3">
          <p class="text-white font-medium">{{ key }}</p>
          <p class="text-slate-400 mt-1">Targets: {{ status.target_count ?? 0 }}</p>
          <p class="text-xs mt-1" :class="status.fallback ? 'text-amber-300' : 'text-emerald-300'">{{ status.fallback ? 'fallback' : 'assigned' }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const sending = ref(false)
const sendingDiscord = ref(false)
const sports = ref({})
const card = ref({})
const routeStatuses = ref({})
const discordDiagnostics = ref({})
const leaguesBySport = ref({})
const expandedSports = ref({})
const searchQuery = ref('')
const showFeaturedOnly = ref(false)
const newLeagueId = ref('')
const newLeagueResult = ref(null)
const lookingUp = ref(false)

const form = reactive({
  enabled_sports: [],
  featured_league_ids: [],
  tv_channels: [],
  cards_enabled: true,
  rich_cards_enabled: true,
  discord_enabled: false,
  discord_default_webhook_url: '',
  discord_username: 'SportsBot',
  discord_avatar_url: '',
  discord_route_webhooks: {}
})

const featuredCount = computed(() => form.featured_league_ids.length)

const totalLeagueCount = computed(() => {
  let count = 0
  for (const key in leaguesBySport.value) {
    count += leaguesBySport.value[key].leagues.length
  }
  return count
})

const sportWithLeagues = computed(() => {
  return Object.keys(leaguesBySport.value).filter(k => leaguesBySport.value[k].leagues.length > 0).length
})

const channelText = computed({
  get: () => form.tv_channels.join('\n'),
  set: value => { form.tv_channels = splitLines(value) }
})

const discordRoutesText = computed({
  get: () => Object.entries(form.discord_route_webhooks || {}).map(([key, value]) => `${key}=${value}`).join('\n'),
  set: value => {
    const routes = {}
    for (const line of String(value || '').split(/\r?\n/)) {
      const trimmed = line.trim()
      if (!trimmed || !trimmed.includes('=')) continue
      const [key, ...rest] = trimmed.split('=')
      const webhook = rest.join('=').trim()
      if (key.trim() && webhook) routes[key.trim()] = webhook
    }
    form.discord_route_webhooks = routes
  }
})

function splitLines(value) {
  return String(value || '').split(/\n|,/).map(item => item.trim()).filter(Boolean)
}

function formatDate(value) {
  return value ? new Date(value).toLocaleString() : '-'
}

function toggleSport(key) {
  expandedSports.value[key] = !expandedSports.value[key]
}

function toggleLeague(id) {
  const idx = form.featured_league_ids.indexOf(id)
  if (idx === -1) {
    form.featured_league_ids.push(id)
  } else {
    form.featured_league_ids.splice(idx, 1)
  }
}

function featuredSportCount(key) {
  const sport = leaguesBySport.value[key]
  if (!sport) return 0
  return sport.leagues.filter(l => l.featured).length
}

function filteredLeagues(key) {
  const sport = leaguesBySport.value[key]
  if (!sport) return []
  let list = sport.leagues
  if (showFeaturedOnly.value) {
    list = list.filter(l => l.featured)
  }
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(l => l.name.toLowerCase().includes(q) || l.id.includes(q))
  }
  return list
}

async function fetchLeagues() {
  try {
    const { data } = await api.get('/admin/sportsbot/leagues')
    leaguesBySport.value = data.sports || {}
    const featured = data.featured_ids || []
    form.featured_league_ids = featured
    for (const key in data.sports) {
      expandedSports.value[key] = false
    }
  } catch (error) {
    console.error('Failed to load leagues', error)
  }
}

async function lookupNewLeague() {
  const id = newLeagueId.value.trim()
  if (!id) return
  lookingUp.value = true
  newLeagueResult.value = null
  try {
    const { data } = await api.post('/admin/sportsbot/leagues/lookup', { id })
    newLeagueResult.value = {
      ...data,
      alreadyFeatured: form.featured_league_ids.includes(id)
    }
  } catch (error) {
    newLeagueResult.value = { found: false, id, name: 'League not found' }
  } finally {
    lookingUp.value = false
  }
}

function addLeagueToFeatured(id) {
  if (!form.featured_league_ids.includes(id)) {
    form.featured_league_ids.push(id)
  }
  newLeagueResult.value = null
  newLeagueId.value = ''
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/coverage')
    sports.value = data.sports || {}
    card.value = data.card_generation || {}
    routeStatuses.value = data.route_statuses || {}
    discordDiagnostics.value = data.discord_send_diagnostics || {}
    await fetchLeagues()
    Object.assign(form, {
      enabled_sports: data.settings?.enabled_sports || [],
      tv_channels: data.settings?.tv_channels || [],
      cards_enabled: data.settings?.cards_enabled ?? true,
      rich_cards_enabled: data.settings?.rich_cards_enabled ?? true,
      discord_enabled: data.settings?.discord_enabled ?? false,
      discord_default_webhook_url: data.settings?.discord_default_webhook_url || '',
      discord_username: data.settings?.discord_username || 'SportsBot',
      discord_avatar_url: data.settings?.discord_avatar_url || '',
      discord_route_webhooks: data.settings?.discord_route_webhooks || {}
    })
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load SportsBot coverage')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    await api.post('/admin/sportsbot/coverage', form)
    toast.success('SportsBot coverage settings saved')
    await load()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to save coverage settings')
  } finally {
    saving.value = false
  }
}

async function sendDiagnostic() {
  sending.value = true
  try {
    await api.post('/admin/sportsbot/telegram/send-diagnostics', { route_key: 'default', media: true })
    toast.success('Diagnostic card sent')
    await load()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send diagnostic card')
  } finally {
    sending.value = false
  }
}

async function sendDiscordDiagnostic() {
  sendingDiscord.value = true
  try {
    await api.post('/admin/sportsbot/discord/send-diagnostics', { route_key: 'default', media: true })
    toast.success('Discord diagnostic card sent')
    await load()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send Discord diagnostic card')
  } finally {
    sendingDiscord.value = false
  }
}

onMounted(load)
</script>
