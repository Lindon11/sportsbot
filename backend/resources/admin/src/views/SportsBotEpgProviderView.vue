<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot EPG Provider</h1>
        <p class="mt-1 text-sm text-slate-400">Source confidence, fixture matches, and guide exports.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button @click="runImport" :disabled="busy === 'import'" class="rounded-lg border border-sky-500/40 bg-sky-500/10 px-3 py-2 text-sm font-medium text-sky-100 hover:bg-sky-500/20 disabled:opacity-60">
          {{ busy === 'import' ? 'Importing...' : 'Import Sources' }}
        </button>
        <button @click="runMatch" :disabled="busy === 'match'" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800 disabled:opacity-60">
          {{ busy === 'match' ? 'Matching...' : 'Match Fixtures' }}
        </button>
        <button @click="runExport" :disabled="busy === 'export'" class="rounded-lg border border-emerald-500/40 bg-emerald-500/10 px-3 py-2 text-sm font-medium text-emerald-100 hover:bg-emerald-500/20 disabled:opacity-60">
          {{ busy === 'export' ? 'Exporting...' : 'Refresh Export' }}
        </button>
      </div>
    </div>

    <div v-if="error" class="rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-200">{{ error }}</div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
      <div v-for="tile in tiles" :key="tile.label" class="rounded-lg border border-slate-700/60 bg-slate-800/50 p-4">
        <p class="text-xs uppercase text-slate-400">{{ tile.label }}</p>
        <p class="mt-2 text-2xl font-bold" :class="tile.tone">{{ tile.value }}</p>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
      <section class="rounded-lg border border-slate-700/60 bg-slate-800/50 p-5 xl:col-span-2">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-lg font-semibold text-white">EPG Source Confidence</h2>
          <button @click="load" :disabled="loading" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800 disabled:opacity-60">
            {{ loading ? 'Refreshing...' : 'Refresh' }}
          </button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-slate-700 text-slate-400">
              <tr>
                <th class="py-2 text-left">Source</th>
                <th class="py-2 text-left">Status</th>
                <th class="py-2 text-left">Region</th>
                <th class="py-2 text-right">Channels</th>
                <th class="py-2 text-right">Programmes</th>
                <th class="py-2 text-left">Checked</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="source in sources" :key="source.id" class="border-b border-slate-800">
                <td class="max-w-[360px] py-3 pr-4">
                  <p class="truncate font-medium text-white">{{ source.name || host(source.url) }}</p>
                  <p class="truncate text-xs text-slate-500">{{ source.url }}</p>
                </td>
                <td class="py-3"><span class="rounded px-2 py-1 text-xs font-medium" :class="statusClass(source.status)">{{ source.status }}</span></td>
                <td class="py-3 text-slate-300">{{ source.region || '-' }}</td>
                <td class="py-3 text-right text-slate-300">{{ source.channel_count || 0 }}</td>
                <td class="py-3 text-right text-slate-300">{{ source.programme_count || 0 }}</td>
                <td class="py-3 text-slate-400">{{ formatDate(source.last_checked_at) }}</td>
              </tr>
              <tr v-if="sources.length === 0">
                <td colspan="6" class="py-6 text-center text-slate-500">No EPG sources found.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="rounded-lg border border-slate-700/60 bg-slate-800/50 p-5">
        <h2 class="text-lg font-semibold text-white">Guide Export Health</h2>
        <div class="mt-4 space-y-3 text-sm">
          <div class="flex items-center justify-between gap-3">
            <span class="text-slate-400">XMLTV</span>
            <span :class="exportHealth.xml_exists ? 'text-emerald-300' : 'text-amber-300'">{{ exportHealth.xml_exists ? 'Ready' : 'Missing' }}</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-slate-400">JSON</span>
            <span :class="exportHealth.json_exists ? 'text-emerald-300' : 'text-amber-300'">{{ exportHealth.json_exists ? 'Ready' : 'Missing' }}</span>
          </div>
          <div class="flex items-center justify-between gap-3">
            <span class="text-slate-400">Token</span>
            <span :class="exportHealth.token_configured ? 'text-emerald-300' : 'text-red-300'">{{ exportHealth.token_configured ? 'Configured' : 'Not set' }}</span>
          </div>
          <div>
            <p class="text-slate-400">Last Export</p>
            <p class="mt-1 text-slate-200">{{ formatDate(exportHealth.last_export_at) }}</p>
          </div>
        </div>
      </section>
    </div>

    <section class="rounded-lg border border-slate-700/60 bg-slate-800/50 p-5">
      <h2 class="mb-4 text-lg font-semibold text-white">EPG Matches Review</h2>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-slate-700 text-slate-400">
            <tr>
              <th class="py-2 text-left">Fixture</th>
              <th class="py-2 text-left">Channel</th>
              <th class="py-2 text-right">Confidence</th>
              <th class="py-2 text-left">Matched Text</th>
              <th class="py-2 text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="match in reviewMatches" :key="match.id" class="border-b border-slate-800">
              <td class="py-3 pr-4 text-slate-300">#{{ match.fixture_queue_id }} <span class="text-slate-500">{{ match.event_id || '' }}</span></td>
              <td class="py-3 text-white">{{ match.channel || '-' }}</td>
              <td class="py-3 text-right" :class="confidenceClass(match.confidence)">{{ percent(match.confidence) }}</td>
              <td class="max-w-[420px] py-3 text-slate-300">
                <p class="truncate">{{ match.evidence?.programme_title || '-' }}</p>
                <p class="truncate text-xs text-slate-500">{{ match.evidence?.programme_start || '' }}</p>
              </td>
              <td class="py-3 text-right">
                <div class="flex justify-end gap-2">
                  <button @click="review(match.id, 'accept')" class="rounded-lg bg-emerald-500/15 px-3 py-1.5 text-xs font-medium text-emerald-200 hover:bg-emerald-500/25">Accept</button>
                  <button @click="review(match.id, 'reject')" class="rounded-lg bg-red-500/15 px-3 py-1.5 text-xs font-medium text-red-200 hover:bg-red-500/25">Reject</button>
                </div>
              </td>
            </tr>
            <tr v-if="reviewMatches.length === 0">
              <td colspan="5" class="py-6 text-center text-slate-500">No uncertain EPG matches waiting for review.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="rounded-lg border border-slate-700/60 bg-slate-800/50 p-5">
      <h2 class="mb-4 text-lg font-semibold text-white">Recent Import Runs</h2>
      <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        <div v-for="run in recentRuns" :key="run.id" class="rounded-lg border border-slate-700 bg-slate-900 p-3">
          <div class="flex items-center justify-between gap-3">
            <span class="rounded px-2 py-1 text-xs font-medium" :class="statusClass(run.status)">{{ run.status }}</span>
            <span class="text-xs text-slate-500">{{ run.duration_ms || 0 }}ms</span>
          </div>
          <p class="mt-2 truncate text-xs text-slate-500">{{ run.source_url }}</p>
          <p class="mt-2 text-sm text-slate-200">{{ run.programme_count || 0 }} programmes · {{ run.channel_count || 0 }} channels</p>
          <p v-if="run.error" class="mt-2 line-clamp-2 text-xs text-red-300">{{ run.error }}</p>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const busy = ref('')
const error = ref('')
const summary = ref({})
const sources = ref([])
const recentRuns = ref([])
const reviewMatches = ref([])
const exportHealth = ref({})

const tiles = computed(() => [
  { label: 'Sources', value: summary.value.source_count || 0, tone: 'text-white' },
  { label: 'Working', value: summary.value.working_sources || 0, tone: 'text-emerald-300' },
  { label: 'Stale', value: summary.value.stale_sources || 0, tone: 'text-amber-300' },
  { label: 'Blocked', value: summary.value.blocked_sources || 0, tone: 'text-red-300' },
  { label: 'Channels', value: summary.value.canonical_channel_count || 0, tone: 'text-sky-300' },
  { label: 'Programmes', value: summary.value.future_programme_count || 0, tone: 'text-white' },
  { label: 'Auto Matches', value: summary.value.auto_match_count || 0, tone: 'text-emerald-300' },
  { label: 'Review', value: summary.value.review_match_count || 0, tone: 'text-amber-300' },
])

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/sportsbot/epg-provider')
    summary.value = data.summary || {}
    sources.value = data.sources || []
    recentRuns.value = data.recent_runs || []
    reviewMatches.value = data.review_matches || []
    exportHealth.value = data.export_health || {}
  } catch (err) {
    error.value = err.response?.data?.error || err.message || 'Failed to load EPG provider state'
  } finally {
    loading.value = false
  }
}

async function runImport() {
  return run('import', '/admin/sportsbot/epg-provider/import', { days: 3, match: true, export: true }, 'EPG sources imported')
}

async function runMatch() {
  return run('match', '/admin/sportsbot/epg-provider/match', { days: 3, limit: 300 }, 'EPG fixture matching complete')
}

async function runExport() {
  return run('export', '/admin/sportsbot/epg-provider/export', { hours: 72 }, 'EPG export refreshed')
}

async function run(key, endpoint, payload, message) {
  busy.value = key
  try {
    await api.post(endpoint, payload)
    toast.success(message)
    await load()
  } catch (err) {
    toast.error(err.response?.data?.error || err.message || 'Action failed')
  } finally {
    busy.value = ''
  }
}

async function review(id, action) {
  try {
    await api.post(`/admin/sportsbot/epg-provider/matches/${id}/${action}`)
    toast.success(action === 'accept' ? 'EPG match accepted' : 'EPG match rejected')
    await load()
  } catch (err) {
    toast.error(err.response?.data?.error || err.message || 'Review failed')
  }
}

function statusClass(status) {
  if (status === 'working' || status === 'auto_applied' || status === 'accepted') return 'bg-emerald-500/15 text-emerald-200'
  if (status === 'stale' || status === 'empty' || status === 'needs_review') return 'bg-amber-500/15 text-amber-200'
  if (status === 'blocked' || status === 'failed' || status === 'rejected') return 'bg-red-500/15 text-red-200'
  return 'bg-slate-700 text-slate-200'
}

function confidenceClass(value) {
  const score = Number(value || 0)
  if (score >= 0.85) return 'text-emerald-300'
  if (score >= 0.55) return 'text-amber-300'
  return 'text-red-300'
}

function percent(value) {
  return `${Math.round(Number(value || 0) * 100)}%`
}

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

function host(url) {
  try {
    return new URL(url).host
  } catch {
    return url || '-'
  }
}

onMounted(load)
</script>
