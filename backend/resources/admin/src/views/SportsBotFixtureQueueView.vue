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
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Total Queued</p>
        <p class="text-3xl font-bold text-white mt-1.5">{{ counts.total ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Draft</p>
        <p class="text-3xl font-bold text-amber-400 mt-1.5">{{ counts.draft ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Ready</p>
        <p class="text-3xl font-bold text-emerald-400 mt-1.5">{{ counts.ready ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Publish Today</p>
        <p class="text-3xl font-bold text-sky-400 mt-1.5">{{ counts.publish_today ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-xs font-medium uppercase tracking-wider">Sent / Failed</p>
        <p class="text-3xl font-bold mt-1.5"><span class="text-sky-400">{{ counts.sent ?? 0 }}</span><span class="text-slate-600 mx-1">/</span><span class="text-red-400">{{ counts.failed ?? 0 }}</span></p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex flex-wrap items-center gap-3">
        <button @click="runPrefetch" :disabled="busy.prefetch" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-60 flex items-center gap-2 text-sm font-medium transition-colors">
          <span v-if="busy.prefetch" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
          {{ busy.prefetch ? 'Prefetching...' : 'Prefetch All' }}
        </button>
        <button @click="runRender" :disabled="busy.render" class="px-5 py-2.5 rounded-xl bg-amber-600 text-white hover:bg-amber-500 disabled:opacity-60 flex items-center gap-2 text-sm font-medium transition-colors">
          <span v-if="busy.render" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
          {{ busy.render ? 'Rendering...' : 'Render All' }}
        </button>
        <button @click="runPublish" :disabled="busy.publish" class="px-5 py-2.5 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60 flex items-center gap-2 text-sm font-medium transition-colors">
          <span v-if="busy.publish" class="inline-block w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" /></svg>
          {{ busy.publish ? 'Publishing...' : 'Publish Today' }}
        </button>
        <span v-if="flashText" class="text-sm px-3 py-1.5 rounded-lg" :class="flashText.isError ? 'bg-red-500/10 text-red-400' : 'bg-emerald-500/10 text-emerald-400'">{{ flashText.text }}</span>
      </div>
    </div>

    <div class="flex flex-wrap gap-3 items-center">
      <select v-model="filters.sport" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm appearance-none cursor-pointer">
        <option value="">All sports</option>
        <option v-for="(sport, key) in sports" :key="key" :value="key">{{ sport.emoji }} {{ sport.label ?? key }}</option>
      </select>
      <select v-model="filters.status" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm appearance-none cursor-pointer">
        <option value="">All statuses</option>
        <option value="draft">● Draft</option>
        <option value="ready">● Ready</option>
        <option value="sent">● Sent</option>
        <option value="failed">● Failed</option>
        <option value="skipped">● Skipped</option>
      </select>
      <input v-model="filters.dateFrom" type="date" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm">
      <span class="text-slate-500 text-sm">→</span>
      <input v-model="filters.dateTo" type="date" class="rounded-xl bg-slate-800 border border-slate-700 text-white px-4 py-2 text-sm">
      <button @click="clearFilters" class="px-3 py-2 rounded-xl bg-slate-800 border border-slate-700 text-slate-300 hover:text-white text-sm transition-colors">Clear</button>
      <span class="text-slate-500 text-sm ml-auto">{{ filteredItems.length }} / {{ allItems.length }} items</span>
    </div>

    <div v-if="filteredItems.length === 0" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-16 text-center">
      <span class="text-5xl block mb-4">📋</span>
      <p class="text-slate-400 text-lg font-medium">No queue items match the current filters</p>
      <p class="text-slate-500 text-sm mt-2">Try adjusting filters or run a prefetch to populate the queue.</p>
    </div>

    <div v-else class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
      <QueueItemCard
        v-for="item in filteredItems"
        :key="item.id"
        :item="item"
        :sport-config="sports[item.sport_key] || {}"
        :busy="pendingActions.size > 0"
        :busy-id="item.id"
        :busy-action="pendingActions.get(item.id) || ''"
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
      />
    </div>

    <div v-if="!loading && allItems.length > 50" class="text-center text-sm text-slate-500 py-4">
      Showing {{ filteredItems.length }} of {{ allItems.length }} items
    </div>
  </div>

  <QueuePreviewModal
    :item="previewItem"
    :sport-configs="sports"
    @close="previewItem = null"
    @render="reRender"
    @send="publishNow"
    @find-poster="findPoster"
    @find-tv-info="findTvInfo"
    @refresh-scraped-data="refreshScrapedData"
    @accept-scraped-data="acceptScrapedData"
    @reject-scraped-data="rejectScrapedData"
  />
</template>

<script setup>
import { onMounted, ref, reactive, computed } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import QueueItemCard from './components/QueueItemCard.vue'
import QueuePreviewModal from './components/QueuePreviewModal.vue'

const toast = useToast()
const loading = ref(false)
const sports = ref({})
const counts = ref({})
const allItems = ref([])
const previewItem = ref(null)
const pendingActions = ref(new Map())
const flashText = ref(null)

const filters = reactive({ sport: '', status: '', dateFrom: '', dateTo: '' })
const searchQuery = ref('')
const busy = reactive({ prefetch: false, render: false, publish: false })

function itemTitle(item) {
  const d = item.fixture_data || {}
  const home = d.home_team || ''
  const away = d.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return d.event_name || d.strEvent || `Event ${item.event_id}`
}

const filteredItems = computed(() => {
  let items = allItems.value
  if (filters.sport) items = items.filter(i => i.sport_key === filters.sport)
  if (filters.status) items = items.filter(i => i.status === filters.status)
  if (filters.dateFrom) items = items.filter(i => i.publish_date >= filters.dateFrom)
  if (filters.dateTo) items = items.filter(i => i.publish_date <= filters.dateTo)
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
  filters.sport = ''; filters.status = ''
  filters.dateFrom = ''; filters.dateTo = ''
  searchQuery.value = ''
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
    allItems.value = (data.recent_items || []).sort(
      (a, b) => new Date(b.updated_at || b.created_at) - new Date(a.updated_at || a.created_at)
    )
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

async function runPublish() {
  busy.publish = true
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/publish')
    const sent = sumResult(data, 'sent')
    const rendered = sumResult(data, 'rendered')
    const skipped = sumResult(data, 'skipped')
    const failed = sumResult(data, 'failed')
    showFlash(`Published ${sent} cards (${rendered} rendered first, ${skipped} skipped, ${failed} failed)`, failed > 0)
    failed > 0 ? toast.error('Publish finished with failures') : toast.success('Publish complete')
    await loadQueue()
  } catch (error) {
    showFlash(error?.response?.data?.message || 'Publish failed', true)
    toast.error('Publish failed')
  } finally {
    busy.publish = false
  }
}

async function reRender(id) {
  pendingActions.value.set(id, 'render')
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/${id}/re-render`)
    if (data.re_rendered) { toast.success('Card re-rendered') } else { toast.error(data.error || 'Re-render failed') }
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Re-render failed')
  } finally {
    pendingActions.value.delete(id)
  }
}

async function publishNow(id) {
  pendingActions.value.set(id, 'send')
  try {
    const { data } = await api.post(`/admin/sportsbot/fixture-queue/${id}/publish`)
    if (data.published) {
      toast.success('Published!')
    } else if (data.already_sent) {
      toast.error('Already sent')
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
  if (!confirm(`Delete queue item ${id}?`)) return
  pendingActions.value.set(id, 'delete')
  try {
    await api.delete(`/admin/sportsbot/fixture-queue/${id}`)
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
