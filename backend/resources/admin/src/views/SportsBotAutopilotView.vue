<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Autopilot</h1>
        <p class="text-slate-400 text-sm mt-1">Automatic queue posting, scraper health, and delivery logs.</p>
      </div>
      <button
        @click="load"
        :disabled="loading"
        class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
      >
        {{ loading ? 'Refreshing...' : 'Refresh' }}
      </button>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
      <div class="flex flex-wrap items-center gap-2">
        <button v-for="action in stageActions" :key="action.key" @click="runStage(action)" :disabled="stageBusy === action.key" class="rounded-xl border px-3 py-2 text-sm font-medium transition disabled:opacity-60" :class="action.danger ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-200 hover:bg-emerald-500/20' : 'border-slate-700 bg-slate-900 text-slate-200 hover:bg-slate-800'">
          {{ stageBusy === action.key ? 'Running...' : action.label }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Health</p>
        <p class="text-2xl font-bold mt-2" :class="health.ok ? 'text-emerald-400' : 'text-red-400'">{{ health.ok ? 'OK' : 'Check' }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Queue Autopilot</p>
        <p class="text-2xl font-bold mt-2" :class="scheduler.fixture_queue?.enabled ? 'text-emerald-400' : 'text-amber-400'">{{ scheduler.fixture_queue?.enabled ? 'On' : 'Off' }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Missing TV</p>
        <p class="text-3xl font-bold text-white mt-2">{{ queue.needs_attention?.missing_tv ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Delivery Failures</p>
        <p class="text-3xl font-bold mt-2" :class="deliveryFailures > 0 ? 'text-red-400' : 'text-emerald-400'">{{ deliveryFailures }}</p>
      </div>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3">
      <router-link v-for="tile in attentionTiles" :key="tile.key" :to="tile.to" class="rounded-xl border p-3 transition hover:border-sky-400/60" :class="tile.count > 0 ? 'border-amber-500/30 bg-amber-500/10' : 'border-slate-700/50 bg-slate-800/40'">
        <p class="text-xs uppercase tracking-wide text-slate-400">{{ tile.label }}</p>
        <p class="mt-1 text-2xl font-bold" :class="tile.count > 0 ? 'text-amber-200' : 'text-slate-300'">{{ tile.count }}</p>
      </router-link>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Queue State</h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <div v-for="key in ['draft', 'ready', 'sent', 'failed']" :key="key" class="rounded-xl bg-slate-900 border border-slate-700 p-3">
            <p class="text-slate-400 text-xs uppercase">{{ key }}</p>
            <p class="text-2xl font-bold text-white mt-1">{{ queue.today?.[key] ?? 0 }}</p>
          </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4 text-sm">
          <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
            <p class="text-slate-400">Window Rows</p>
            <p class="text-white font-semibold mt-1">{{ queue.needs_attention?.window_rows ?? 0 }}</p>
          </div>
          <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
            <p class="text-slate-400">Missing Cards</p>
            <p class="text-white font-semibold mt-1">{{ queue.needs_attention?.missing_card ?? 0 }}</p>
          </div>
          <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
            <p class="text-slate-400">Scraper Found</p>
            <p class="text-white font-semibold mt-1">{{ queue.needs_attention?.scraper_found ?? 0 }}</p>
          </div>
        </div>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Schedule</h2>
        <div class="space-y-3 text-sm">
          <div class="flex items-center justify-between gap-3">
            <span class="text-slate-400">Live alerts</span>
            <span :class="scheduler.live_alerts_enabled ? 'text-emerald-300' : 'text-slate-500'">{{ scheduler.live_alerts_enabled ? scheduler.live_alerts_frequency : 'off' }}</span>
          </div>
          <div v-for="item in scheduleRows" :key="item.label" class="flex items-center justify-between gap-3">
            <span class="text-slate-400">{{ item.label }}</span>
            <span :class="item.enabled ? 'text-emerald-300' : 'text-slate-500'">{{ item.value }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Autopilot Timeline</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <div v-for="stage in pipelineStages" :key="stage" class="rounded-xl bg-slate-900 border border-slate-700 p-4">
          <div class="flex items-center justify-between gap-3">
            <p class="font-semibold text-white capitalize">{{ stage }}</p>
            <span class="rounded-lg px-2 py-1 text-xs font-medium" :class="pipelineStatusClass(latestPipeline[stage]?.status)">
              {{ latestPipeline[stage]?.status || 'not run' }}
            </span>
          </div>
          <p class="mt-2 text-xs text-slate-500">{{ formatDate(latestPipeline[stage]?.finished_at || latestPipeline[stage]?.started_at) }}</p>
          <p class="mt-2 text-xs text-slate-300">{{ countsSummary(latestPipeline[stage]?.counts) }}</p>
          <div class="mt-3 grid grid-cols-3 gap-1 text-[11px] text-slate-500">
            <span class="truncate">OK {{ shortDate(lastStatus(stage, 'success')) }}</span>
            <span class="truncate">Warn {{ shortDate(lastStatus(stage, 'warning')) }}</span>
            <span class="truncate">Fail {{ shortDate(lastStatus(stage, 'failed')) }}</span>
          </div>
          <p v-if="latestPipeline[stage]?.error_summary" class="mt-2 text-xs text-red-300 line-clamp-2">{{ latestPipeline[stage].error_summary }}</p>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Scheduler Logs</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <div v-for="log in scheduler.logs || []" :key="log.file" class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-white font-medium">{{ log.label }}</p>
          <p class="text-xs text-slate-500 mt-1">{{ log.file }}</p>
          <p class="text-xs mt-2" :class="log.exists ? 'text-emerald-300' : 'text-slate-500'">{{ log.exists ? formatDate(log.last_modified_at) : 'not written yet' }}</p>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Delivery Logs</h2>
      <div v-if="deliveries.recent.length === 0" class="text-sm text-slate-400">No delivery logs yet. Run migrations, then send a Telegram or Discord test.</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-slate-400 border-b border-slate-700">
            <tr>
              <th class="text-left py-2">Status</th>
              <th class="text-left py-2">Platform</th>
              <th class="text-left py-2">Route</th>
              <th class="text-left py-2">Type</th>
              <th class="text-left py-2">Target</th>
              <th class="text-left py-2">Message</th>
              <th class="text-left py-2">Time</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="delivery in deliveries.recent" :key="delivery.id" class="border-b border-slate-800">
              <td class="py-2"><span :class="statusClass(delivery.status)" class="px-2 py-1 rounded text-xs font-medium">{{ delivery.status }}</span></td>
              <td class="py-2 text-slate-300">{{ delivery.platform }}</td>
              <td class="py-2 text-slate-300">{{ delivery.route_key || '-' }}</td>
              <td class="py-2 text-slate-300">{{ delivery.type || '-' }}</td>
              <td class="py-2 text-slate-300">{{ delivery.target || '-' }}</td>
              <td class="py-2 text-slate-300">{{ delivery.message_id || delivery.error || '-' }}</td>
              <td class="py-2 text-slate-300">{{ formatDate(delivery.sent_at || delivery.created_at) }}</td>
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
const health = ref({})
const scheduler = ref({})
const queue = ref({})
const deliveries = ref({ recent: [], last_24h: [] })
const pipeline = ref({ recent: [], latest_by_stage: {}, last_status_by_stage: {} })
const stageBusy = ref('')
const pipelineStages = ['prefetch', 'enrich', 'render', 'publish']
const stageActions = [
  { key: 'prefetch', label: 'Run Prefetch', endpoint: '/admin/sportsbot/fixture-queue/prefetch' },
  { key: 'enrich', label: 'Run Enrich', endpoint: '/admin/sportsbot/fixture-queue/enrich', payload: { days: 2, limit: 50 } },
  { key: 'render', label: 'Run Render', endpoint: '/admin/sportsbot/fixture-queue/render' },
  { key: 'publish-dry-run', label: 'Publish Dry Run', endpoint: '/admin/sportsbot/fixture-queue/publish', payload: { dry_run: true } },
  { key: 'publish', label: 'Publish Now', endpoint: '/admin/sportsbot/fixture-queue/publish', danger: true },
]

const deliveryFailures = computed(() => {
  return (deliveries.value.last_24h || [])
    .filter(row => row.status === 'failed')
    .reduce((total, row) => total + Number(row.total || 0), 0)
})

const scheduleRows = computed(() => {
  const fixture = scheduler.value.fixture_queue || {}
  return [
    { label: 'Prefetch', enabled: fixture.enabled && fixture.prefetch_enabled, value: fixture.prefetch_enabled ? fixture.prefetch_time : 'off' },
    { label: 'Enrich', enabled: fixture.enabled && fixture.enrich_enabled, value: fixture.enrich_enabled ? `${fixture.enrich_frequency} · ${fixture.enrich_limit}/run` : 'off' },
    { label: 'Render', enabled: fixture.enabled && fixture.render_enabled, value: fixture.render_enabled ? fixture.render_frequency : 'off' },
    { label: 'Publish', enabled: fixture.enabled && fixture.publish_enabled, value: fixture.publish_enabled ? fixture.publish_frequency : 'off' },
  ]
})
const latestPipeline = computed(() => pipeline.value.latest_by_stage || {})
const lastStatusPipeline = computed(() => pipeline.value.last_status_by_stage || {})
const attentionTiles = computed(() => {
  const needs = queue.value.needs_attention || {}
  return [
    { key: 'missing_card', label: 'Missing Cards', count: needs.missing_card || 0, to: '/sportsbot/fixture-queue?quick=missing_card' },
    { key: 'missing_tv', label: 'Missing TV', count: needs.missing_tv || 0, to: '/sportsbot/fixture-queue?quick=missing_tv' },
    { key: 'gd_fallback', label: 'GD Fallback', count: needs.gd_fallback || 0, to: '/sportsbot/fixture-queue?quick=gd_fallback' },
    { key: 'blocked_publish', label: 'Blocked', count: needs.blocked_publish || 0, to: '/sportsbot/fixture-queue?quick=blocked_publish' },
    { key: 'enrichment_due', label: 'Enrichment Due', count: needs.enrichment_due || 0, to: '/sportsbot/fixture-queue?quick=enrichment_due' },
    { key: 'scraper_error', label: 'Scraper Error', count: needs.scraper_error || 0, to: '/sportsbot/fixture-queue?quick=enrichment_due' },
    { key: 'route_fallback', label: 'Route Fallback', count: needs.route_fallback || 0, to: '/sportsbot/routing' },
    { key: 'failed_delivery', label: 'Delivery Failed', count: needs.failed_delivery || 0, to: '/sportsbot/autopilot' },
  ]
})

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

function shortDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })
}

function lastStatus(stage, status) {
  const run = lastStatusPipeline.value?.[stage]?.[status]
  return run?.finished_at || run?.started_at || ''
}

function statusClass(status) {
  if (status === 'sent') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  return 'bg-slate-700 text-slate-300'
}

function pipelineStatusClass(status) {
  if (status === 'success') return 'bg-emerald-500/20 text-emerald-300'
  if (status === 'warning' || status === 'running') return 'bg-amber-500/20 text-amber-300'
  if (status === 'failed') return 'bg-red-500/20 text-red-300'
  return 'bg-slate-700 text-slate-300'
}

function countsSummary(counts = {}) {
  const entries = Object.entries(counts || {}).filter(([, value]) => Number(value) > 0)
  if (!entries.length) return 'No count data yet'
  return entries.slice(0, 5).map(([key, value]) => `${key.replaceAll('_', ' ')} ${value}`).join(' · ')
}

async function runStage(action) {
  stageBusy.value = action.key
  try {
    await api.post(action.endpoint, action.payload || {})
    toast.success(`${action.label} complete`)
    await load()
  } catch (error) {
    toast.error(error?.response?.data?.message || `${action.label} failed`)
  } finally {
    stageBusy.value = ''
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/autopilot')
    health.value = data.health || {}
    scheduler.value = data.scheduler || {}
    queue.value = data.queue || {}
    pipeline.value = data.pipeline || { recent: [], latest_by_stage: {}, last_status_by_stage: {} }
    deliveries.value = data.deliveries || { recent: [], last_24h: [] }
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load SportsBot autopilot')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>
