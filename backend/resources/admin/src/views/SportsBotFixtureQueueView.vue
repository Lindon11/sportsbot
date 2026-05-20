<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-white">Fixture Queue</h1>
        <p class="mt-1 text-sm text-slate-400">Prefetch, triage, render, and publish scheduled fixture cards.</p>
      </div>
      <div class="flex gap-2">
        <label class="relative">
          <input v-model="searchQuery" placeholder="Search teams, leagues, events..." class="w-64 rounded-xl border border-slate-700 bg-slate-800 py-2 pl-10 pr-4 text-sm text-white placeholder:text-slate-500">
          <svg class="absolute left-3 top-2.5 h-4 w-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </label>
        <button @click="loadQueue" :disabled="loading" class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Total Queued</p>
        <p class="mt-1.5 text-3xl font-bold text-white">{{ counts.total ?? 0 }}</p>
      </div>
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Draft</p>
        <p class="mt-1.5 text-3xl font-bold text-amber-400">{{ counts.draft ?? 0 }}</p>
      </div>
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Ready</p>
        <p class="mt-1.5 text-3xl font-bold text-emerald-400">{{ counts.ready ?? 0 }}</p>
      </div>
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Publish Today</p>
        <p class="mt-1.5 text-3xl font-bold text-sky-400">{{ counts.publish_today ?? 0 }}</p>
      </div>
      <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-4">
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Sent / Failed</p>
        <p class="mt-1.5 text-3xl font-bold"><span class="text-sky-400">{{ counts.sent ?? 0 }}</span><span class="mx-1 text-slate-600">/</span><span class="text-red-400">{{ counts.failed ?? 0 }}</span></p>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-5">
      <div class="flex flex-wrap items-center gap-3">
        <button @click="runPrefetch" :disabled="busy.prefetch" class="flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-indigo-500 disabled:opacity-60">
          <span v-if="busy.prefetch" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
          <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
          {{ busy.prefetch ? 'Prefetching...' : 'Prefetch All' }}
        </button>
        <button @click="runEnrich" :disabled="busy.enrich" class="flex items-center gap-2 rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-violet-500 disabled:opacity-60">
          <span v-if="busy.enrich" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
          <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v3m0 12v3m9-9h-3M6 12H3m15.364-6.364l-2.121 2.121M7.757 16.243l-2.121 2.121m12.728 0l-2.121-2.121M7.757 7.757L5.636 5.636" /></svg>
          {{ busy.enrich ? 'Enriching...' : 'Enrich Due' }}
        </button>
        <button @click="runRender" :disabled="busy.render" class="flex items-center gap-2 rounded-xl bg-amber-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-amber-500 disabled:opacity-60">
          <span v-if="busy.render" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
          <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
          {{ busy.render ? 'Rendering...' : 'Render All' }}
        </button>
        <button @click="runPublish" :disabled="busy.publish" class="flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-medium text-white transition-colors hover:bg-emerald-500 disabled:opacity-60">
          <span v-if="busy.publish" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
          <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
          {{ busy.publish ? 'Publishing...' : 'Publish Today' }}
        </button>
        <button @click="runPublishDryRun" :disabled="busy.dryRun" class="flex items-center gap-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 px-5 py-2.5 text-sm font-medium text-emerald-200 transition-colors hover:bg-emerald-500/20 disabled:opacity-60">
          <span v-if="busy.dryRun" class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-emerald-200/30 border-t-emerald-200"></span>
          Dry Run
        </button>
        <span v-if="flashText" class="rounded-lg px-3 py-1.5 text-sm" :class="flashText.isError ? 'bg-red-500/10 text-red-400' : 'bg-emerald-500/10 text-emerald-400'">{{ flashText.text }}</span>
      </div>
      <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-400">
        <span class="rounded-lg bg-slate-900/70 px-2 py-1">primary {{ renderer.primary || 'browser_v3' }}</span>
        <span class="rounded-lg bg-slate-900/70 px-2 py-1">GD fallback {{ renderer.gd_fallback_enabled ? 'enabled' : 'disabled' }}</span>
        <span class="rounded-lg bg-slate-900/70 px-2 py-1">fallback publish {{ renderer.allow_gd_fallback_publish ? 'allowed' : 'blocked' }}</span>
        <span class="rounded-lg bg-slate-900/70 px-2 py-1">timeout {{ renderer.browser_timeout || 15 }}s</span>
        <span class="rounded-lg bg-slate-900/70 px-2 py-1">assets {{ assetCache.files || 0 }} files</span>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-700/50 bg-slate-800/40 p-4">
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-for="filter in quickFilters"
          :key="filter.key"
          @click="setQuickFilter(filter.key)"
          class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-medium transition-colors"
          :class="activeQuickFilter === filter.key ? 'border-sky-400 bg-sky-500/20 text-sky-100' : 'border-slate-700 bg-slate-900/70 text-slate-300 hover:text-white'"
        >
          <span>{{ filter.label }}</span>
          <span class="rounded-md px-1.5 py-0.5 text-[11px]" :class="activeQuickFilter === filter.key ? 'bg-sky-200 text-sky-950' : 'bg-slate-700 text-slate-300'">
            {{ quickFilterCounts[filter.key] || 0 }}
          </span>
        </button>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-3">
      <select v-model="filters.sport" class="cursor-pointer appearance-none rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-white">
        <option value="">All sports</option>
        <option v-for="(sport, key) in sports" :key="key" :value="key">{{ sport.emoji }} {{ sport.label ?? key }}</option>
      </select>
      <select v-model="filters.status" class="cursor-pointer appearance-none rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-white">
        <option value="">All statuses</option>
        <option value="draft">Draft</option>
        <option value="ready">Ready</option>
        <option value="sent">Sent</option>
        <option value="failed">Failed</option>
        <option value="skipped">Skipped</option>
      </select>
      <input v-model="filters.dateFrom" type="date" class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-white">
      <span class="text-sm text-slate-500">to</span>
      <input v-model="filters.dateTo" type="date" class="rounded-xl border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-white">
      <button @click="clearFilters" class="rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-300 transition-colors hover:text-white">Clear</button>
      <button
        @click="toggleSelectFiltered"
        :disabled="filteredItems.length === 0"
        class="rounded-xl border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-300 transition-colors hover:text-white disabled:opacity-50"
      >
        {{ allFilteredSelected ? 'Unselect visible' : selectVisibleLabel }}
      </button>
      <span class="ml-auto text-sm text-slate-500">{{ filteredItems.length }} / {{ allItems.length }} items</span>
    </div>

    <div v-if="bulkResult" class="rounded-2xl border p-4" :class="bulkResult.failed > 0 ? 'border-amber-500/30 bg-amber-500/10' : 'border-emerald-500/30 bg-emerald-500/10'">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p class="text-sm font-semibold text-white">{{ bulkResult.label }} complete</p>
          <p class="mt-1 text-xs text-slate-300">
            {{ bulkResult.succeeded }} succeeded, {{ bulkResult.failed }} failed, {{ bulkResult.skipped }} skipped from {{ bulkResult.total }} selected.
          </p>
        </div>
        <button @click="bulkResult = null" class="rounded-lg bg-slate-950/40 px-3 py-1.5 text-xs text-slate-300 hover:text-white">Dismiss</button>
      </div>
    </div>

    <div v-if="selectedCount" class="sticky top-3 z-20 rounded-2xl border border-sky-500/30 bg-slate-950/95 p-4 shadow-2xl backdrop-blur">
      <div class="flex flex-wrap items-center gap-3">
        <span class="text-sm font-semibold text-white">{{ selectedCount }} selected</span>
        <span v-if="selectedCount > bulkLimit" class="rounded-lg bg-amber-500/10 px-2 py-1 text-xs text-amber-300">Bulk actions use the first {{ bulkLimit }} selected items.</span>
        <button @click="bulkAction('re-render')" :disabled="busy.bulk" class="rounded-xl bg-slate-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-slate-600 disabled:opacity-60">Re-render selected</button>
        <button @click="bulkAction('republish')" :disabled="busy.bulk" class="rounded-xl bg-sky-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-sky-600 disabled:opacity-60">Republish selected</button>
        <button @click="bulkAction('regenerate-assets')" :disabled="busy.bulk" class="rounded-xl bg-violet-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-violet-600 disabled:opacity-60">Regenerate assets</button>
        <button @click="clearSelection" class="ml-auto rounded-xl border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:text-white">Clear selection</button>
      </div>
    </div>

    <div v-if="filteredItems.length === 0" class="rounded-2xl border border-slate-700/50 bg-slate-800/50 p-16 text-center">
      <p class="text-lg font-medium text-slate-400">No queue items match the current filters</p>
      <p class="mt-2 text-sm text-slate-500">Try adjusting filters or run a prefetch to populate the queue.</p>
    </div>

    <div v-else class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-3">
      <QueueItemCard
        v-for="item in filteredItems"
        :key="item.id"
        :item="item"
        :sport-config="sports[item.sport_key] || {}"
        :busy="pendingActions.size > 0"
        :busy-id="item.id"
        :busy-action="pendingActions.get(item.id) || ''"
        :selected="selectedIds.has(item.id)"
        @preview="previewItem = $event"
        @render="reRender"
        @send="publishNow"
        @find-poster="findPoster"
        @find-tv-info="findTvInfo"
        @refresh-scraped-data="refreshScrapedData"
        @accept-scraped-data="acceptScrapedData"
        @reject-scraped-data="rejectScrapedData"
        @skip="skipItem"
        @delete="deleteItem"
        @toggle-select="toggleSelection"
      />
    </div>

    <div v-if="!loading && allItems.length > 50" class="py-4 text-center text-sm text-slate-500">
      Showing {{ filteredItems.length }} of {{ allItems.length }} loaded items
    </div>
  </div>

  <QueuePreviewModal
    :item="previewItem"
    :sport-configs="sports"
    :templates="templates"
    @close="previewItem = null"
    @render="reRender"
    @send="publishNow"
    @find-poster="findPoster"
    @find-tv-info="findTvInfo"
    @refresh-scraped-data="refreshScrapedData"
    @accept-scraped-data="acceptScrapedData"
    @reject-scraped-data="rejectScrapedData"
    @save-render-options="saveRenderOptions"
  />
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import QueueItemCard from './components/QueueItemCard.vue'
import QueuePreviewModal from './components/QueuePreviewModal.vue'

const toast = useToast()
const confirm = useConfirm()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const sports = ref({})
const counts = ref({})
const renderer = ref({})
const assetCache = ref({})
const templates = ref({})
const allItems = ref([])
const previewItem = ref(null)
const pendingActions = ref(new Map())
const flashText = ref(null)
const activeQuickFilter = ref(typeof route.query.quick === 'string' ? route.query.quick : '')
const selectedIds = ref(new Set())
const bulkResult = ref(null)
const bulkLimit = 100

const filters = reactive({ sport: '', status: '', dateFrom: '', dateTo: '' })
const searchQuery = ref('')
const busy = reactive({ prefetch: false, enrich: false, render: false, publish: false, dryRun: false, bulk: false })
const quickFilters = [
  { key: 'today', label: 'Today' },
  { key: 'missing_card', label: 'Missing Cards' },
  { key: 'missing_tv', label: 'Missing TV' },
  { key: 'failed', label: 'Failed' },
  { key: 'ready', label: 'Ready to Publish' },
  { key: 'gd_fallback', label: 'GD Fallback' },
  { key: 'blocked_publish', label: 'Blocked' },
  { key: 'enrichment_due', label: 'Enrichment Due' },
  { key: 'scraper_found', label: 'Scraper Found' },
  { key: 'sent', label: 'Sent' },
]
const bulkLabels = {
  're-render': { label: 'Re-render', confirm: 'Re-render selected', type: 'info' },
  republish: { label: 'Republish', confirm: 'Republish selected', type: 'warning' },
  'regenerate-assets': { label: 'Regenerate assets', confirm: 'Regenerate assets', type: 'warning' },
}

const quickFilterCounts = computed(() => {
  return quickFilters.reduce((result, filter) => {
    result[filter.key] = allItems.value.filter(item => matchesQuickFilter(item, filter.key)).length
    return result
  }, {})
})
const filteredItems = computed(() => {
  let items = allItems.value
  if (activeQuickFilter.value) items = items.filter(item => matchesQuickFilter(item, activeQuickFilter.value))
  if (filters.sport) items = items.filter(item => item.sport_key === filters.sport)
  if (filters.status) items = items.filter(item => item.status === filters.status)
  if (filters.dateFrom) items = items.filter(item => item.publish_date >= filters.dateFrom)
  if (filters.dateTo) items = items.filter(item => item.publish_date <= filters.dateTo)
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase().trim()
    items = items.filter(item => {
      const data = item.fixture_data || {}
      return (data.home_team || '').toLowerCase().includes(q)
        || (data.away_team || '').toLowerCase().includes(q)
        || (data.league || '').toLowerCase().includes(q)
        || (data.event_name || '').toLowerCase().includes(q)
        || String(item.event_id || '').toLowerCase().includes(q)
        || String(item.sport_key || '').toLowerCase().includes(q)
    })
  }
  return items
})
const selectedCount = computed(() => selectedIds.value.size)
const selectedItemIds = computed(() => Array.from(selectedIds.value))
const selectedBulkIds = computed(() => selectedItemIds.value.slice(0, bulkLimit))
const selectableFilteredItems = computed(() => filteredItems.value.slice(0, bulkLimit))
const allFilteredSelected = computed(() => {
  return selectableFilteredItems.value.length > 0 && selectableFilteredItems.value.every(item => selectedIds.value.has(item.id))
})
const selectVisibleLabel = computed(() => {
  return filteredItems.value.length > bulkLimit ? `Select first ${bulkLimit}` : 'Select visible'
})

watch(() => route.query.quick, value => {
  activeQuickFilter.value = typeof value === 'string' ? value : ''
})

function todayString() {
  return new Date().toISOString().slice(0, 10)
}

function itemTitle(item) {
  const data = item?.fixture_data || {}
  const home = data.home_team || ''
  const away = data.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return data.event_name || data.strEvent || `Event ${item?.event_id || item?.id}`
}

function hasTv(item) {
  if (item.needs_attention?.missing_tv !== undefined) return !item.needs_attention.missing_tv
  const data = item.fixture_data || {}
  const placeholders = ['', 'not listed', 'not shown', 'not available', 'no tv', 'none', 'unknown', 'tbc', 'tv tbc', 'channel tbc', 'channels tbc', 'n/a', 'na', '-']
  const usable = value => {
    const normalized = String(value || '').trim().toLowerCase().replace(/\s+/g, ' ').replace(/[.:-]+$/g, '')
    return normalized && !placeholders.includes(normalized)
  }
  return Boolean(usable(data.tv_channel) || usable(data.strChannel) || (Array.isArray(data.tv_channels) && data.tv_channels.some(usable)))
}

function hasCard(item) {
  return Boolean(item.card_path)
}

function hasScraperFound(item) {
  return item.payload?.scraper?.status === 'found'
}

function matchesQuickFilter(item, key) {
  if (key === 'today') return item.publish_date === todayString()
  if (key === 'missing_card') return !hasCard(item) && item.status !== 'sent'
  if (key === 'missing_tv') return !hasTv(item)
  if (key === 'failed') return item.status === 'failed'
  if (key === 'ready') return item.status === 'ready'
  if (key === 'gd_fallback') return Boolean(item.needs_attention?.gd_fallback || item.render_proof?.fallback_active || item.renderer_used === 'gd_v3')
  if (key === 'blocked_publish') return Boolean(item.needs_attention?.blocked_publish || item.publish_preflight?.blocked)
  if (key === 'enrichment_due') return Boolean(item.needs_attention?.enrichment_due)
  if (key === 'scraper_found') return hasScraperFound(item)
  if (key === 'sent') return item.status === 'sent'
  return true
}

function setQuickFilter(key) {
  const next = activeQuickFilter.value === key ? '' : key
  activeQuickFilter.value = next
  router.replace({ query: { ...route.query, quick: next || undefined } })
}

function clearFilters() {
  filters.sport = ''
  filters.status = ''
  filters.dateFrom = ''
  filters.dateTo = ''
  searchQuery.value = ''
  setQuickFilter('')
}

function toggleSelection(id) {
  const next = new Set(selectedIds.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  selectedIds.value = next
}

function toggleSelectFiltered() {
  const next = new Set(selectedIds.value)
  if (allFilteredSelected.value) {
    selectableFilteredItems.value.forEach(item => next.delete(item.id))
  } else {
    selectableFilteredItems.value.forEach(item => next.add(item.id))
  }
  selectedIds.value = next
}

function clearSelection() {
  selectedIds.value = new Set()
}

function pruneSelection() {
  const validIds = new Set(allItems.value.map(item => item.id))
  selectedIds.value = new Set(selectedItemIds.value.filter(id => validIds.has(id)))
}

function showFlash(text, isError = false) {
  flashText.value = { text, isError }
  setTimeout(() => { flashText.value = null }, 4000)
}

function sumResult(data, key) {
  if (!data || typeof data !== 'object') return 0
  if (typeof data[key] === 'number') return data[key]
  return Object.values(data).reduce((total, row) => total + (Number(row?.[key] ?? 0) || 0), 0)
}

async function loadQueue() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/fixture-queue')
    sports.value = data.sports || {}
    counts.value = data.queue_counts || {}
    counts.value.publish_today = data.publish_today ?? 0
    renderer.value = data.renderer || {}
    assetCache.value = data.asset_cache || {}
    templates.value = data.templates || {}
    allItems.value = (data.recent_items || []).sort(
      (a, b) => new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at)
    )
    if (previewItem.value) {
      previewItem.value = allItems.value.find(item => item.id === previewItem.value.id) || previewItem.value
    }
    pruneSelection()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load fixture queue')
  } finally {
    loading.value = false
  }
}

async function runPrefetch() {
  busy.prefetch = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/prefetch')
    const created = sumResult(data, 'created')
    const updated = sumResult(data, 'updated')
    const skipped = sumResult(data, 'skipped')
    showFlash(`Prefetched ${created + updated} items (${created} new, ${updated} updated, ${skipped} unchanged)`)
    toast.success('Prefetch complete')
    await loadQueue()
  } catch (error) {
    showFlash(error?.response?.data?.message || 'Prefetch failed', true)
    toast.error('Prefetch failed')
  } finally {
    busy.prefetch = false
  }
}

async function runRender() {
  busy.render = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/render')
    const rendered = sumResult(data, 'rendered')
    const skipped = sumResult(data, 'skipped')
    const failed = sumResult(data, 'failed')
    showFlash(`Rendered ${rendered} cards (${skipped} current, ${failed} failed)`, failed > 0)
    failed > 0 ? toast.error('Render finished with failures') : toast.success('Render complete')
    await loadQueue()
  } catch (error) {
    showFlash(error?.response?.data?.message || 'Render failed', true)
    toast.error('Render failed')
  } finally {
    busy.render = false
  }
}

async function runEnrich() {
  busy.enrich = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/enrich', { days: 2, limit: 50 })
    showFlash(`Enriched ${data.checked || 0} rows (${data.found || 0} found, ${data.skipped || 0} skipped, ${data.failed || 0} failed)`, (data.failed || 0) > 0)
    data.failed ? toast.error('Enrich finished with failures') : toast.success('Enrich complete')
    await loadQueue()
  } catch (error) {
    showFlash(error?.response?.data?.message || 'Enrich failed', true)
    toast.error('Enrich failed')
  } finally {
    busy.enrich = false
  }
}

async function runPublish() {
  return runPublishStage(false)
}

async function runPublishDryRun() {
  return runPublishStage(true)
}

async function runPublishStage(dryRun = false) {
  const busyKey = dryRun ? 'dryRun' : 'publish'
  busy[busyKey] = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/publish', { dry_run: dryRun })
    const sent = sumResult(data, 'sent')
    const rendered = sumResult(data, 'rendered')
    const wouldSend = sumResult(data, 'would_send')
    const wouldRender = sumResult(data, 'would_render')
    const skipped = sumResult(data, 'skipped')
    const blocked = sumResult(data, 'blocked')
    const failed = sumResult(data, 'failed')
    const label = dryRun ? `Dry run: ${wouldSend} would send, ${wouldRender} would render` : `Published ${sent} cards`
    showFlash(`${label} (${rendered} rendered, ${skipped} skipped, ${blocked} blocked, ${failed} failed)`, failed > 0 || blocked > 0)
    failed > 0 ? toast.error('Publish finished with failures') : toast.success(dryRun ? 'Dry run complete' : 'Publish complete')
    await loadQueue()
  } catch (error) {
    showFlash(error?.response?.data?.message || (dryRun ? 'Dry run failed' : 'Publish failed'), true)
    toast.error(dryRun ? 'Dry run failed' : 'Publish failed')
  } finally {
    busy[busyKey] = false
  }
}

async function bulkAction(action) {
  const ids = selectedBulkIds.value
  if (!ids.length) return

  const meta = bulkLabels[action] || { label: action, confirm: action, type: 'warning' }
  const confirmed = await confirm.show({
    title: `${meta.label} queue items`,
    message: `${meta.confirm} for ${ids.length} selected queue item${ids.length === 1 ? '' : 's'}?`,
    confirmText: meta.confirm,
    type: meta.type,
  })
  if (!confirmed) return

  busy.bulk = true
  bulkResult.value = null
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/bulk/${action}`, { ids })
    const summary = summarizeBulkResults(data, ids)
    bulkResult.value = { ...summary, label: meta.label }
    showFlash(`${meta.label} complete: ${summary.succeeded} succeeded, ${summary.failed} failed, ${summary.skipped} skipped`, summary.failed > 0)
    summary.failed ? toast.error('Bulk action finished with failures') : toast.success('Bulk action complete')
    clearSelection()
    await loadQueue()
  } catch (error) {
    showFlash(error?.response?.data?.message || 'Bulk action failed', true)
    toast.error('Bulk action failed')
  } finally {
    busy.bulk = false
  }
}

function summarizeBulkResults(data, ids) {
  const results = Object.values(data.results || {})
  const total = Number(data.count || ids.length)
  const failed = results.filter(isFailedResult).length
  const skipped = results.filter(isSkippedResult).length
  return {
    total,
    failed,
    skipped,
    succeeded: Math.max(0, total - failed - skipped),
  }
}

function isFailedResult(result) {
  if (!result || typeof result !== 'object') return false
  if (result.error) return true
  if (result.published === false && !result.already_sent) return true
  if (result.re_rendered === false) return true
  return false
}

function isSkippedResult(result) {
  if (!result || typeof result !== 'object') return false
  return Boolean(result.skipped || result.already_sent)
}

async function reRender(id) {
  pendingActions.value.set(id, 'render')
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/${id}/re-render`)
    if (data.re_rendered) toast.success('Card re-rendered')
    else toast.error(data.error || 'Re-render failed')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Re-render failed')
  } finally {
    pendingActions.value.delete(id)
  }
}

async function publishNow(id, force = false) {
  if (force) {
    const confirmed = await confirm.warning('Resend this fixture card even though it is already marked sent?', 'Resend Fixture Card')
    if (!confirmed) return
  }

  pendingActions.value.set(id, 'send')
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/${id}/publish`, { force })
    if (data.published) {
      toast.success(data.resent ? 'Resent!' : 'Published!')
    } else if (data.already_sent) {
      toast.error('Already sent. Use Resend to send it again.')
    } else {
      toast.error(data.error || 'Publish failed')
    }
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Publish failed')
  } finally {
    pendingActions.value.delete(id)
  }
}

async function scraperAction(id, action, url, successMessage) {
  pendingActions.value.set(id, action)
  try {
    const { data } = await api.post(url)
    if (data.error) {
      toast.error(data.error)
    } else {
      const fields = data.normalized?.fields_found || Object.keys(data.fields || {})
      toast.success(fields.length ? `${successMessage}: ${fields.join(', ')}` : successMessage)
    }
    if (data.item && previewItem.value?.id === id) previewItem.value = data.item
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || `${successMessage} failed`)
  } finally {
    pendingActions.value.delete(id)
  }
}

function findPoster(id) {
  return scraperAction(id, 'find-poster', `/admin/sportsbot/fixture-queue/${id}/find-poster`, 'Poster search complete')
}

function findTvInfo(id) {
  return scraperAction(id, 'find-tv-info', `/admin/sportsbot/fixture-queue/${id}/find-tv-info`, 'TV info search complete')
}

function refreshScrapedData(id) {
  return scraperAction(id, 'refresh-scraped-data', `/admin/sportsbot/fixture-queue/${id}/refresh-scraped-data`, 'Scraped data refreshed')
}

function acceptScrapedData(id) {
  return scraperAction(id, 'accept-scraped-data', `/admin/sportsbot/fixture-queue/${id}/accept-scraped-data`, 'Scraped data accepted')
}

function rejectScrapedData(id) {
  return scraperAction(id, 'reject-scraped-data', `/admin/sportsbot/fixture-queue/${id}/reject-scraped-data`, 'Scraped data rejected')
}

async function saveRenderOptions(id, options) {
  pendingActions.value.set(id, 'render-options')
  try {
    await api.post(`/admin/sportsbot/fixture-queue/${id}/render-options`, { ...options, rerender: true })
    toast.success('Render options saved')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to save render options')
  } finally {
    pendingActions.value.delete(id)
  }
}

async function skipItem(id) {
  pendingActions.value.set(id, 'skip')
  try {
    await api.post(`/admin/sportsbot/fixture-queue/${id}/skip`)
    toast.success('Item skipped')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to skip')
  } finally {
    pendingActions.value.delete(id)
  }
}

async function deleteItem(id) {
  const item = allItems.value.find(row => row.id === id)
  const confirmed = await confirm.confirm(`Delete "${itemTitle(item)}" from the fixture queue? This cannot be undone.`, 'Delete Queue Item')
  if (!confirmed) return

  pendingActions.value.set(id, 'delete')
  try {
    await api.delete(`/admin/sportsbot/fixture-queue/${id}`)
    selectedIds.value.delete(id)
    toast.success('Item deleted')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to delete')
  } finally {
    pendingActions.value.delete(id)
  }
}

onMounted(loadQueue)
</script>
