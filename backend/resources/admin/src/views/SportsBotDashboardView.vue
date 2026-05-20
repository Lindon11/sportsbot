<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Daily Ops</h1>
        <p class="mt-1 text-sm text-slate-400">Operator health, queue triage, delivery issues, and route coverage.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <router-link to="/sportsbot/autopilot" class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:bg-slate-700">
          Autopilot details
        </router-link>
        <button
          @click="loadStatus"
          :disabled="loading"
          class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-60"
        >
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-sm text-slate-400">Bot Health</p>
        <p class="mt-2 text-2xl font-bold" :class="health.ok ? 'text-emerald-400' : 'text-red-400'">
          {{ health.ok ? 'OK' : 'Check' }}
        </p>
        <p class="mt-1 text-xs text-slate-500">{{ latestRunLabel }}</p>
      </div>
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-sm text-slate-400">Queue Autopilot</p>
        <p class="mt-2 text-2xl font-bold" :class="scheduler.fixture_queue?.enabled ? 'text-emerald-400' : 'text-amber-400'">
          {{ scheduler.fixture_queue?.enabled ? 'On' : 'Off' }}
        </p>
        <p class="mt-1 text-xs text-slate-500">Prefetch {{ scheduler.fixture_queue?.prefetch_enabled ? scheduler.fixture_queue?.prefetch_time : 'off' }}</p>
      </div>
      <router-link :to="queueLink('today')" class="rounded-2xl border border-sky-500/25 bg-sky-500/10 p-4 transition hover:border-sky-400/60">
        <p class="text-sm text-sky-200">Publish Today</p>
        <p class="mt-2 text-3xl font-bold text-white">{{ queue.today?.ready ?? 0 }}</p>
        <p class="mt-1 text-xs text-sky-100/70">Open today's ready queue</p>
      </router-link>
      <router-link to="/sportsbot/autopilot" class="rounded-2xl border bg-slate-800/50 p-4 transition hover:border-red-400/60" :class="deliveryFailures > 0 ? 'border-red-500/40' : 'border-slate-700/50'">
        <p class="text-sm text-slate-400">Delivery Failures</p>
        <p class="mt-2 text-3xl font-bold" :class="deliveryFailures > 0 ? 'text-red-400' : 'text-emerald-400'">{{ deliveryFailures }}</p>
        <p class="mt-1 text-xs text-slate-500">Last 24 hours</p>
      </router-link>
    </div>

    <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-white">Needs Attention</h2>
          <p class="mt-1 text-sm text-slate-400">Click a tile to open the queue already filtered to that issue.</p>
        </div>
        <router-link to="/sportsbot/fixture-queue" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-2 text-sm text-slate-300 hover:text-white">
          Open full queue
        </router-link>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
        <router-link
          v-for="tile in attentionTiles"
          :key="tile.key"
          :to="tile.to"
          class="rounded-xl border p-4 transition hover:border-slate-400/70"
          :class="tile.count > 0 ? tile.activeClass : 'border-slate-700 bg-slate-900/60'"
        >
          <div class="flex items-center justify-between gap-3">
            <p class="text-sm font-medium text-white">{{ tile.label }}</p>
            <span class="rounded-lg px-2 py-1 text-xs font-bold" :class="tile.count > 0 ? tile.badgeClass : 'bg-slate-700 text-slate-300'">
              {{ tile.count }}
            </span>
          </div>
          <p class="mt-2 text-xs text-slate-400">{{ tile.description }}</p>
        </router-link>
      </div>
    </div>

    <div v-if="routeWarnings.length" class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-5">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 class="text-lg font-semibold text-amber-100">Route Warnings</h2>
          <p class="mt-1 text-sm text-amber-100/75">Some routes are falling back or have no resolved targets.</p>
        </div>
        <router-link to="/sportsbot/routing" class="rounded-xl bg-amber-300 px-3 py-2 text-sm font-semibold text-amber-950 hover:bg-amber-200">
          Fix routing
        </router-link>
      </div>
      <div class="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-3">
        <div v-for="warning in routeWarnings" :key="warning.key" class="rounded-xl border border-amber-400/25 bg-slate-950/40 p-3">
          <div class="flex items-center justify-between gap-2">
            <p class="truncate text-sm font-semibold text-white">{{ warning.key }}</p>
            <span class="rounded bg-amber-300 px-2 py-1 text-[11px] font-bold uppercase tracking-wide text-amber-950">
              {{ warning.status.fallback ? 'fallback' : 'no targets' }}
            </span>
          </div>
          <p class="mt-2 truncate text-xs text-amber-100/75">{{ targetText(warning.status.targets || []) }}</p>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-5">
        <h2 class="mb-4 text-lg font-semibold text-white">Recent Runs</h2>
        <div v-if="recentRuns.length === 0" class="text-sm text-slate-400">No runs yet.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-slate-700 text-slate-400">
              <tr>
                <th class="py-2 text-left">ID</th>
                <th class="py-2 text-left">Status</th>
                <th class="py-2 text-left">Alerts</th>
                <th class="py-2 text-left">Finished</th>
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

      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-5">
        <h2 class="mb-4 text-lg font-semibold text-white">Recent Telegram Messages</h2>
        <div v-if="recentMessages.length === 0" class="text-sm text-slate-400">No recent messages.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="border-b border-slate-700 text-slate-400">
              <tr>
                <th class="py-2 text-left">Status</th>
                <th class="py-2 text-left">Route</th>
                <th class="py-2 text-left">Target</th>
                <th class="py-2 text-left">Sent</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="message in recentMessages" :key="message.id" class="border-b border-slate-800">
                <td class="py-2">
                  <span :class="statusClass(message.status)" class="rounded px-2 py-1 text-xs font-medium">{{ message.status }}</span>
                </td>
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
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const health = ref({})
const latestRun = ref(null)
const routeStatuses = ref({})
const recentRuns = ref([])
const recentMessages = ref([])
const scheduler = ref({})
const queue = ref({})
const deliveries = ref({ recent: [], last_24h: [] })

const needsAttention = computed(() => queue.value.needs_attention || {})
const deliveryFailures = computed(() => {
  return (deliveries.value.last_24h || [])
    .filter(row => row.status === 'failed')
    .reduce((total, row) => total + Number(row.total || 0), 0)
})
const failedToday = computed(() => Number(queue.value.today?.failed || 0))
const routeWarnings = computed(() => {
  return Object.entries(routeStatuses.value || {})
    .filter(([, status]) => Boolean(status?.fallback) || Number(status?.target_count || 0) === 0)
    .map(([key, status]) => ({ key, status }))
})
const latestRunLabel = computed(() => {
  if (!latestRun.value) return 'No run recorded yet'
  return `Latest run ${latestRun.value.status || 'recorded'} ${formatDate(latestRun.value.finished_at || latestRun.value.created_at)}`
})
const attentionTiles = computed(() => [
  {
    key: 'missing-card',
    label: 'Missing Cards',
    count: Number(needsAttention.value.missing_card || 0),
    description: 'Upcoming items without rendered artwork.',
    to: queueLink('missing_card'),
    activeClass: 'border-amber-500/30 bg-amber-500/10',
    badgeClass: 'bg-amber-300 text-amber-950',
  },
  {
    key: 'missing-tv',
    label: 'Missing TV',
    count: Number(needsAttention.value.missing_tv || 0),
    description: 'Upcoming fixtures without TV channel data.',
    to: queueLink('missing_tv'),
    activeClass: 'border-amber-500/30 bg-amber-500/10',
    badgeClass: 'bg-amber-300 text-amber-950',
  },
  {
    key: 'gd-fallback',
    label: 'GD Fallback',
    count: Number(needsAttention.value.gd_fallback || 0),
    description: 'Browser v3 did not render; retry these cards.',
    to: queueLink('gd_fallback'),
    activeClass: 'border-amber-500/30 bg-amber-500/10',
    badgeClass: 'bg-amber-300 text-amber-950',
  },
  {
    key: 'blocked-publish',
    label: 'Blocked Publish',
    count: Number(needsAttention.value.blocked_publish || 0),
    description: 'Items autopilot will not send yet.',
    to: queueLink('blocked_publish'),
    activeClass: 'border-red-500/30 bg-red-500/10',
    badgeClass: 'bg-red-300 text-red-950',
  },
  {
    key: 'enrichment-due',
    label: 'Enrichment Due',
    count: Number(needsAttention.value.enrichment_due || 0),
    description: 'Rows ready for scraper enrichment.',
    to: queueLink('enrichment_due'),
    activeClass: 'border-violet-500/30 bg-violet-500/10',
    badgeClass: 'bg-violet-300 text-violet-950',
  },
  {
    key: 'scraper-found',
    label: 'Scraper Found',
    count: Number(needsAttention.value.scraper_found || 0),
    description: 'Scraped fields waiting for review.',
    to: queueLink('scraper_found'),
    activeClass: 'border-violet-500/30 bg-violet-500/10',
    badgeClass: 'bg-violet-300 text-violet-950',
  },
  {
    key: 'scraper-error',
    label: 'Scraper Error',
    count: Number(needsAttention.value.scraper_error || 0),
    description: 'Scraper checks that need attention.',
    to: queueLink('enrichment_due'),
    activeClass: 'border-red-500/30 bg-red-500/10',
    badgeClass: 'bg-red-300 text-red-950',
  },
  {
    key: 'failed',
    label: 'Failed Today',
    count: failedToday.value,
    description: 'Failed queue items that need a retry or fix.',
    to: queueLink('failed'),
    activeClass: 'border-red-500/30 bg-red-500/10',
    badgeClass: 'bg-red-300 text-red-950',
  },
])

function queueLink(quick) {
  return { path: '/sportsbot/fixture-queue', query: { quick } }
}

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
    const [statusResult, autopilotResult] = await Promise.allSettled([
      api.get('/admin/sportsbot/status'),
      api.get('/admin/sportsbot/autopilot'),
    ])

    if (statusResult.status === 'fulfilled') {
      const data = statusResult.value.data || {}
      health.value = data.health || {}
      latestRun.value = data.latest_run || null
      routeStatuses.value = data.route_statuses || {}
      recentRuns.value = data.recent_runs || []
      recentMessages.value = data.recent_telegram_messages || []
    } else {
      toast.error(statusResult.reason?.response?.data?.message || 'Failed to load SportsBot status')
    }

    if (autopilotResult.status === 'fulfilled') {
      const data = autopilotResult.value.data || {}
      scheduler.value = data.scheduler || {}
      queue.value = data.queue || {}
      deliveries.value = data.deliveries || { recent: [], last_24h: [] }
      if (!health.value.ok && data.health) health.value = data.health
    } else {
      toast.error(autopilotResult.reason?.response?.data?.message || 'Failed to load SportsBot autopilot status')
    }
  } finally {
    loading.value = false
  }
}

onMounted(loadStatus)
</script>
