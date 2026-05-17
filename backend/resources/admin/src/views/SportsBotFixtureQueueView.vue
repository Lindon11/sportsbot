<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">Fixture Queue</h1>
        <p class="text-slate-400 text-sm mt-1">Prefetch, render, and publish scheduled fixture cards.</p>
      </div>
      <div class="flex gap-2">
        <button @click="loadQueue" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
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
        <p class="text-slate-400 text-sm">Sent Today</p>
        <p class="text-3xl font-bold text-sky-400 mt-2">{{ counts.sent ?? 0 }}</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Pipeline Actions</h2>
      <div class="flex flex-wrap gap-3">
        <button @click="runPrefetch" :disabled="busy.prefetch" class="px-5 py-2 rounded-xl bg-indigo-600 text-white hover:bg-indigo-500 disabled:opacity-60">
          <span v-if="busy.prefetch" class="inline-block animate-spin mr-2">&#9696;</span>
          {{ busy.prefetch ? 'Prefetching...' : 'Prefetch All' }}
        </button>
        <button @click="runRender" :disabled="busy.render" class="px-5 py-2 rounded-xl bg-amber-600 text-white hover:bg-amber-500 disabled:opacity-60">
          <span v-if="busy.render" class="inline-block animate-spin mr-2">&#9696;</span>
          {{ busy.render ? 'Rendering...' : 'Render All' }}
        </button>
        <button @click="runPublish" :disabled="busy.publish" class="px-5 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60">
          <span v-if="busy.publish" class="inline-block animate-spin mr-2">&#9696;</span>
          {{ busy.publish ? 'Publishing...' : 'Publish Today' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Sport Windows</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-slate-400 border-b border-slate-700">
              <tr>
                <th class="text-left py-2">Sport</th>
                <th class="text-left py-2">Fetch</th>
                <th class="text-left py-2">Assets</th>
                <th class="text-left py-2">Render</th>
                <th class="text-left py-2">Publish</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(sport, key) in sports" :key="key" class="border-b border-slate-800">
                <td class="py-2 text-white font-medium">{{ sport.emoji }} {{ sport.label ?? key }}</td>
                <td class="py-2 text-slate-300">{{ sport.data_fetch_window ?? 7 }}d</td>
                <td class="py-2 text-slate-300">{{ sport.asset_cache_window ?? 7 }}d</td>
                <td class="py-2 text-slate-300">{{ sport.card_prepare_window ?? 2 }}d</td>
                <td class="py-2">
                  <span :class="sport.publish_window === 0 ? 'bg-emerald-500/20 text-emerald-300' : 'bg-amber-500/20 text-amber-300'" class="px-2 py-1 rounded text-xs font-medium">
                    {{ sport.publish_window === 0 ? 'same day' : sport.publish_window + 'd before' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Recent Queue Items</h2>
        <div v-if="recentItems.length === 0" class="text-sm text-slate-400">No items in queue yet.</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-slate-400 border-b border-slate-700">
              <tr>
                <th class="text-left py-2">Sport</th>
                <th class="text-left py-2">Status</th>
                <th class="text-left py-2">Date</th>
                <th class="text-left py-2">Updated</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in recentItems" :key="item.id" class="border-b border-slate-800">
                <td class="py-2 text-white">{{ item.sport_key }}</td>
                <td class="py-2">
                  <span :class="statusClass(item.status)" class="px-2 py-1 rounded text-xs font-medium">{{ item.status }}</span>
                  <span v-if="item.asset_status === 'cached'" class="ml-1 text-xs text-slate-500">&#10003;</span>
                </td>
                <td class="py-2 text-slate-300">{{ item.publish_date }}</td>
                <td class="py-2 text-slate-300">{{ formatDate(item.updated_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="lastResult" class="rounded-2xl border p-4" :class="lastResult.error ? 'bg-red-900/20 border-red-700/50' : 'bg-slate-800/50 border-slate-700/50'">
      <p class="text-sm font-medium text-white mb-2">Last Action Result</p>
      <pre class="text-xs text-slate-300 overflow-x-auto">{{ JSON.stringify(lastResult, null, 2) }}</pre>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, reactive } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const sports = ref({})
const counts = ref({})
const recentItems = ref([])
const lastResult = ref(null)
const busy = reactive({ prefetch: false, render: false, publish: false })

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

function statusClass(status) {
  if (status === 'ready') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'draft') return 'bg-amber-500/20 text-amber-400'
  if (status === 'sent') return 'bg-sky-500/20 text-sky-400'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  if (status === 'skipped') return 'bg-slate-700 text-slate-300'
  return 'bg-slate-700 text-slate-300'
}

async function loadQueue() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/fixture-queue')
    sports.value = data.sports || {}
    counts.value = data.queue_counts || {}
    recentItems.value = data.recent_items || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load fixture queue')
  } finally {
    loading.value = false
  }
}

async function runPrefetch() {
  busy.prefetch = true
  lastResult.value = null
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/prefetch')
    lastResult.value = data
    toast.success('Prefetch complete')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Prefetch failed')
  } finally {
    busy.prefetch = false
  }
}

async function runRender() {
  busy.render = true
  lastResult.value = null
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/render')
    lastResult.value = data
    toast.success('Render complete')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Render failed')
  } finally {
    busy.render = false
  }
}

async function runPublish() {
  busy.publish = true
  lastResult.value = null
  try {
    const { data } = await api.post('/admin/sportsbot/fixture-queue/publish')
    lastResult.value = data
    toast.success('Publish complete')
    await loadQueue()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Publish failed')
  } finally {
    busy.publish = false
  }
}

onMounted(loadQueue)
</script>
