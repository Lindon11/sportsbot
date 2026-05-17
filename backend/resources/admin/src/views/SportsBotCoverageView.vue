<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Coverage</h1>
        <p class="text-slate-400 text-sm mt-1">Sports, leagues, cards, cache and Telegram send diagnostics.</p>
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
            Send rich Telegram media
          </label>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Featured Leagues</h2>
        <textarea v-model="leagueText" rows="8" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="One league ID per line" />
      </div>

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
const sports = ref({})
const card = ref({})
const routeStatuses = ref({})
const form = reactive({
  enabled_sports: [],
  featured_league_ids: [],
  tv_channels: [],
  cards_enabled: true,
  rich_cards_enabled: true
})

const leagueText = computed({
  get: () => form.featured_league_ids.join('\n'),
  set: value => { form.featured_league_ids = splitLines(value) }
})

const channelText = computed({
  get: () => form.tv_channels.join('\n'),
  set: value => { form.tv_channels = splitLines(value) }
})

function splitLines(value) {
  return String(value || '').split(/\n|,/).map(item => item.trim()).filter(Boolean)
}

function formatDate(value) {
  return value ? new Date(value).toLocaleString() : '-'
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/coverage')
    sports.value = data.sports || {}
    card.value = data.card_generation || {}
    routeStatuses.value = data.route_statuses || {}
    Object.assign(form, {
      enabled_sports: data.settings?.enabled_sports || [],
      featured_league_ids: data.settings?.featured_league_ids || [],
      tv_channels: data.settings?.tv_channels || [],
      cards_enabled: data.settings?.cards_enabled ?? true,
      rich_cards_enabled: data.settings?.rich_cards_enabled ?? true
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

onMounted(load)
</script>
