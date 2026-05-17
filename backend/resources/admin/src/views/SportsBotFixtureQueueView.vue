<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">Fixture Queue</h1>
        <p class="text-slate-400 text-sm mt-1">Prefetch, render, and publish scheduled fixture cards.</p>
      </div>
      <div class="flex gap-2">
        <label class="relative">
          <input v-model="searchQuery" placeholder="Search teams, leagues, events..." class="w-64 rounded-xl bg-slate-800 border border-slate-700 text-white pl-10 pr-4 py-2 text-sm placeholder:text-slate-500">
          <svg class="absolute left-3 top-2.5 w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
        </label>
        <button @click="loadQueue" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Total Queued</p>
        <p class="text-3xl font-bold text-white mt-2">{{ counts.total ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Draft</p>
        <p class="text-3xl font-bold text-amber-400 mt-2">{{ counts.draft ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Ready</p>
        <p class="text-3xl font-bold text-emerald-400 mt-2">{{ counts.ready ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Today to Publish</p>
        <p class="text-3xl font-bold text-sky-400 mt-2">{{ counts.publish_today ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Sent / Failed</p>
        <p class="text-3xl font-bold mt-2"><span class="text-sky-400">{{ counts.sent ?? 0 }}</span><span class="text-slate-600 mx-1">/</span><span class="text-red-400">{{ counts.failed ?? 0 }}</span></p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex flex-wrap items-center gap-3">
        <button @click="runPrefetch" :disabled="busy.prefetch" class="px-5 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-60 flex items-center gap-2">
          <span v-if="busy.prefetch" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          {{ busy.prefetch ? 'Prefetching...' : 'Prefetch All' }}
        </button>
        <button @click="runRender" :disabled="busy.render" class="px-5 py-2 rounded-xl bg-amber-600 text-white hover:bg-amber-500 disabled:opacity-60 flex items-center gap-2">
          <span v-if="busy.render" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          {{ busy.render ? 'Rendering...' : 'Render All' }}
        </button>
        <button @click="runPublish" :disabled="busy.publish" class="px-5 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60 flex items-center gap-2">
          <span v-if="busy.publish" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          {{ busy.publish ? 'Publishing...' : 'Publish Today' }}
        </button>
        <span v-if="lastActionResult" class="text-xs" :class="lastActionResult.isError ? 'text-red-400' : 'text-emerald-400'">{{ lastActionResult.text }}</span>
      </div>
    </div>

    <div class="flex flex-wrap gap-3 items-center">
      <select v-model="filters.sport" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm">
        <option value="">All Sports</option>
        <option v-for="(sport, key) in sports" :key="key" :value="key">{{ sport.emoji }} {{ sport.label ?? key }}</option>
      </select>
      <select v-model="filters.status" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm">
        <option value="">All Statuses</option>
        <option value="draft">Draft</option>
        <option value="ready">Ready</option>
        <option value="sent">Sent</option>
        <option value="failed">Failed</option>
        <option value="skipped">Skipped</option>
      </select>
      <input v-model="filters.dateFrom" type="date" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm">
      <span class="text-slate-500 text-sm">to</span>
      <input v-model="filters.dateTo" type="date" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm">
      <button @click="clearFilters" class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-slate-300 hover:text-white text-sm">Clear</button>
      <span class="text-slate-500 text-sm ml-auto">{{ filteredItems.length }} / {{ allItems.length }} items</span>
    </div>

    <div v-if="filteredItems.length === 0" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-12 text-center">
      <p class="text-slate-400 text-lg">No queue items match the current filters.</p>
      <p class="text-slate-500 text-sm mt-2">Try changing the filter criteria or run a prefetch.</p>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
      <div v-for="item in filteredItems" :key="item.id" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 overflow-hidden hover:border-slate-600 transition-colors group">
        <div class="relative bg-slate-900/80 aspect-video flex items-center justify-center overflow-hidden">
          <template v-if="item.card_path && item.status === 'ready'">
            <img :src="`/admin/sportsbot/fixture-queue/${item.id}/card`" :alt="itemTitle(item)" class="w-full h-full object-cover cursor-pointer" @click="previewItem = item" loading="lazy">
            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/30 transition-colors flex items-center justify-center">
              <button @click="previewItem = item" class="opacity-0 group-hover:opacity-100 transition-opacity px-4 py-2 rounded-xl bg-white/20 text-white backdrop-blur-sm text-sm font-medium">Preview</button>
            </div>
          </template>
          <template v-else>
            <div class="text-center p-6">
              <svg class="w-12 h-12 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
              <p class="text-slate-500 text-sm mt-2">Card not rendered</p>
              <p v-if="item.status === 'failed' && item.error" class="text-red-400 text-xs mt-1 truncate max-w-[200px]">{{ item.error }}</p>
            </div>
          </template>
          <span :class="statusBadgeClass(item.status)" class="absolute top-3 right-3 px-2.5 py-1 rounded-lg text-xs font-semibold backdrop-blur-sm">{{ item.status }}</span>
          <span class="absolute top-3 left-3 text-lg">{{ sportEmoji(item.sport_key) }}</span>
          <span v-if="item.asset_status === 'cached'" class="absolute bottom-3 left-3 text-xs text-emerald-400/70 bg-black/40 px-2 py-0.5 rounded">assets cached</span>
        </div>

        <div class="p-4 space-y-2">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-white font-semibold truncate">{{ itemTitle(item) }}</p>
              <p class="text-slate-400 text-xs truncate">{{ item.fixture_data?.league || 'League TBC' }}</p>
            </div>
          </div>

          <div class="flex items-center gap-3 text-xs text-slate-400">
            <span class="flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
              {{ item.publish_date }}
            </span>
            <span class="flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
              {{ item.fixture_data?.tv_channel || 'No TV' }}
            </span>
          </div>

          <div class="flex items-center gap-2 text-xs">
            <span class="flex items-center gap-1 text-slate-500">
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
              {{ item.route_key || '—' }}
            </span>
            <span v-if="item.updated_at" class="text-slate-500">{{ relativeTime(item.updated_at) }}</span>
          </div>

          <div class="flex flex-wrap gap-1.5 pt-2 border-t border-slate-700/50">
            <button @click="previewItem = item" class="px-3 py-1.5 rounded-lg bg-slate-700/50 text-slate-300 hover:bg-slate-700 text-xs font-medium transition-colors">Preview</button>
            <button @click="reRender(item.id)" :disabled="pendingActions.has(item.id)" class="px-3 py-1.5 rounded-lg bg-amber-600/20 text-amber-300 hover:bg-amber-600/30 text-xs font-medium transition-colors disabled:opacity-40">
              {{ pendingActions.has(item.id) ? '...' : 'Render' }}
            </button>
            <button v-if="item.status === 'ready'" @click="publishNow(item.id)" :disabled="pendingActions.has(item.id)" class="px-3 py-1.5 rounded-lg bg-emerald-600/20 text-emerald-300 hover:bg-emerald-600/30 text-xs font-medium transition-colors disabled:opacity-40">
              {{ pendingActions.has(item.id) ? '...' : 'Send' }}
            </button>
            <button @click="skipItem(item.id)" :disabled="pendingActions.has(item.id)" class="px-3 py-1.5 rounded-lg bg-slate-700/50 text-slate-400 hover:text-slate-300 text-xs font-medium transition-colors disabled:opacity-40">Skip</button>
            <button @click="deleteItem(item.id)" :disabled="pendingActions.has(item.id)" class="px-3 py-1.5 rounded-lg bg-red-600/20 text-red-300 hover:bg-red-600/30 text-xs font-medium transition-colors disabled:opacity-40 ml-auto">Delete</button>
          </div>
        </div>
      </div>
    </div>

    <div v-if="!loading && allItems.length > 50 && filteredItems.length > 0" class="text-center text-sm text-slate-500 py-4">
      Showing {{ filteredItems.length }} of {{ allItems.length }} items
    </div>

    <teleport to="body">
      <div v-if="previewItem" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4" @click.self="previewItem = null">
        <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
          <div class="flex items-center justify-between p-5 border-b border-slate-700">
            <div class="flex items-center gap-3">
              <span class="text-2xl">{{ sportEmoji(previewItem.sport_key) }}</span>
              <div>
                <h2 class="text-lg font-bold text-white">{{ itemTitle(previewItem) }}</h2>
                <p class="text-sm text-slate-400">{{ previewItem.fixture_data?.league || 'League TBC' }}</p>
              </div>
            </div>
            <button @click="previewItem = null" class="p-2 rounded-xl hover:bg-slate-700 text-slate-400 hover:text-white transition-colors">
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
            </button>
          </div>

          <div class="p-5 space-y-5">
            <div v-if="previewItem.card_path && previewItem.status === 'ready'" class="rounded-xl overflow-hidden bg-slate-800">
              <img :src="`/admin/sportsbot/fixture-queue/${previewItem.id}/card`" alt="Fixture card" class="w-full">
            </div>
            <div v-else class="rounded-xl bg-slate-800 border border-slate-700 p-8 text-center">
              <svg class="w-16 h-16 mx-auto text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
              <p class="text-slate-400 mt-3">Card image not available</p>
              <p v-if="previewItem.error" class="text-red-400 text-sm mt-2">{{ previewItem.error }}</p>
            </div>

            <div class="rounded-xl bg-slate-800/50 border border-slate-700/50 p-4 space-y-2">
              <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <p class="text-slate-500 text-xs">Event ID</p>
                  <p class="text-white font-mono text-xs">{{ previewItem.event_id }}</p>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Status</p>
                  <span :class="statusBadgeClass(previewItem.status)" class="px-2 py-0.5 rounded text-xs font-semibold inline-block mt-0.5">{{ previewItem.status }}</span>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Sport</p>
                  <p class="text-white">{{ previewItem.sport_key }}</p>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Publish Date</p>
                  <p class="text-white">{{ previewItem.publish_date }}</p>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Route / Topic</p>
                  <p class="text-white font-mono text-xs">{{ previewItem.route_key || '—' }}</p>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Asset Status</p>
                  <p class="text-white">{{ previewItem.asset_status || 'pending' }}</p>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Card Path</p>
                  <p class="text-white font-mono text-xs truncate">{{ previewItem.card_path || 'not rendered' }}</p>
                </div>
                <div>
                  <p class="text-slate-500 text-xs">Last Refreshed</p>
                  <p class="text-white">{{ formatDate(previewItem.last_refreshed_at) }}</p>
                </div>
                <div v-if="previewItem.telegram_message_id" class="col-span-2">
                  <p class="text-slate-500 text-xs">Telegram Message ID</p>
                  <p class="text-white font-mono text-xs">{{ previewItem.telegram_message_id }}</p>
                </div>
              </div>
            </div>

            <div v-if="previewItem.caption" class="rounded-xl bg-slate-800/50 border border-slate-700/50 p-4">
              <p class="text-slate-500 text-xs mb-2">Caption Preview</p>
              <div class="text-white text-sm whitespace-pre-wrap font-mono text-xs bg-slate-900 rounded-lg p-3">{{ previewItem.caption }}</div>
            </div>

            <div v-if="previewItem.payload_hash" class="rounded-xl bg-slate-800/50 border border-slate-700/50 p-4">
              <p class="text-slate-500 text-xs mb-1">Payload Hash</p>
              <p class="text-white font-mono text-xs break-all">{{ previewItem.payload_hash }}</p>
            </div>

            <div v-if="previewItem.error" class="rounded-xl bg-red-900/20 border border-red-700/50 p-4">
              <p class="text-red-400 text-xs font-semibold mb-1">Error</p>
              <p class="text-red-300 text-sm">{{ previewItem.error }}</p>
            </div>
          </div>

          <div class="flex items-center gap-2 p-5 border-t border-slate-700">
            <button @click="reRender(previewItem.id); previewItem = null" class="px-4 py-2 rounded-xl bg-amber-600 text-white hover:bg-amber-500 text-sm font-medium">Re-Render</button>
            <button v-if="previewItem.status === 'ready'" @click="publishNow(previewItem.id); previewItem = null" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 text-sm font-medium">Publish Now</button>
            <button @click="previewItem = null" class="px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 text-sm font-medium ml-auto">Close</button>
          </div>
        </div>
      </div>
    </teleport>
  </div>
</template>

<script setup>
import { onMounted, ref, reactive, computed } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const sports = ref({})
const counts = ref({})
const allItems = ref([])
const previewItem = ref(null)
const pendingActions = ref(new Set())
const lastActionResult = ref(null)

const filters = reactive({
  sport: '',
  status: '',
  dateFrom: '',
  dateTo: '',
})

const searchQuery = ref('')
const busy = reactive({ prefetch: false, render: false, publish: false })

const sportEmojiMap = computed(() => {
  const map = {}
  for (const [key, val] of Object.entries(sports.value)) {
    map[key] = val.emoji || '🏅'
  }
  return map
})

function sportEmoji(sportKey) {
  return sportEmojiMap.value[sportKey] || '🏅'
}

function itemTitle(item) {
  const d = item.fixture_data || {}
  const home = d.home_team || ''
  const away = d.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return d.event_name || d.strEvent || `Event ${item.event_id}`
}

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

function relativeTime(dateStr) {
  if (!dateStr) return ''
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  const days = Math.floor(hrs / 24)
  return `${days}d ago`
}

function statusBadgeClass(status) {
  if (status === 'ready') return 'bg-emerald-500/80 text-white'
  if (status === 'draft') return 'bg-amber-500/80 text-white'
  if (status === 'sent') return 'bg-sky-500/80 text-white'
  if (status === 'failed') return 'bg-red-500/80 text-white'
  if (status === 'skipped') return 'bg-slate-600/80 text-slate-200'
  return 'bg-slate-700/80 text-slate-300'
}

const filteredItems = computed(() => {
  let items = allItems.value

  if (filters.sport) {
    items = items.filter(i => i.sport_key === filters.sport)
  }
  if (filters.status) {
    items = items.filter(i => i.status === filters.status)
  }
  if (filters.dateFrom) {
    items = items.filter(i => i.publish_date >= filters.dateFrom)
  }
  if (filters.dateTo) {
    items = items.filter(i => i.publish_date <= filters.dateTo)
  }
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase().trim()
    items = items.filter(i => {
      const d = i.fixture_data || {}
      return (d.home_team || '').toLowerCase().includes(q)
        || (d.away_team || '').toLowerCase().includes(q)
        || (d.league || '').toLowerCase().includes(q)
        || (d.event_name || '').toLowerCase().includes(q)
        || i.event_id.toLowerCase().includes(q)
        || i.sport_key.toLowerCase().includes(q)
    })
  }
  return items
})

function clearFilters() {
  filters.sport = ''
  filters.status = ''
  filters.dateFrom = ''
  filters.dateTo = ''
  searchQuery.value = ''
}

function setActionBusy(id, busy) {
  const s = new Set(pendingActions.value)
  if (busy) s.add(id); else s.delete(id)
  pendingActions.value = s
}

function flashAction(text, isError = false) {
  lastActionResult.value = { text, isError }
  setTimeout(() => { lastActionResult.value = null }, 4000)
}

async function loadQueue() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/fixture-queue')
    sports.value = data.sports || {}
    counts.value = data.queue_counts || {}
    counts.value.publish_today = data.publish_today ?? 0
    allItems.value = (data.recent_items || []).sort((a, b) => new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at))
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
    flashAction(`Prefetched — created ${data.created ?? 0}, updated ${data.updated ?? 0}`)
    toast.success('Prefetch complete')
    await loadQueue()
  } catch (error) {
    flashAction(error?.response?.data?.message || 'Prefetch failed', true)
    toast.error('Prefetch failed')
  } finally {
    busy.prefetch = false
  }
}

async function runRender() {
  busy.render = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/render')
    let total = 0
    for (const k of Object.keys(data)) { total += data[k]?.rendered ?? 0 }
    flashAction(`Rendered ${total} cards`)
    toast.success('Render complete')
    await loadQueue()
  } catch (error) {
    flashAction(error?.response?.data?.message || 'Render failed', true)
    toast.error('Render failed')
  } finally {
    busy.render = false
  }
}

async function runPublish() {
  busy.publish = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/publish')
    let total = 0
    for (const k of Object.keys(data)) { total += data[k]?.sent ?? 0 }
    flashAction(`Published ${total} items`)
    toast.success('Publish complete')
    await loadQueue()
  } catch (error) {
    flashAction(error?.response?.data?.message || 'Publish failed', true)
    toast.error('Publish failed')
  } finally {
    busy.publish = false
  }
}

async function reRender(id) {
  setActionBusy(id, true)
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/${id}/re-render`)
    if (data.re_rendered) {
      toast.success('Card re-rendered')
      flashAction(`Re-rendered item ${id}`)
    } else {
      toast.error(data.error || 'Re-render failed')
    }
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Re-render failed')
  } finally {
    setActionBusy(id, false)
  }
}

async function publishNow(id) {
  setActionBusy(id, true)
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/${id}/publish`)
    if (data.published) {
      toast.success('Published!')
      flashAction(`Published item ${id}`)
    } else {
      toast.error(data.error || 'Publish failed')
    }
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Publish failed')
  } finally {
    setActionBusy(id, false)
  }
}

async function skipItem(id) {
  setActionBusy(id, true)
  try {
    await api.post(`/admin/sportsbot/fixture-queue/${id}/skip`)
    toast.success('Item skipped')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to skip')
  } finally {
    setActionBusy(id, false)
  }
}

async function deleteItem(id) {
  if (!confirm(`Delete queue item ${id}?`)) return
  setActionBusy(id, true)
  try {
    await api.delete(`/admin/sportsbot/fixture-queue/${id}`)
    toast.success('Item deleted')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to delete')
  } finally {
    setActionBusy(id, false)
  }
}

onMounted(loadQueue)
</script>
