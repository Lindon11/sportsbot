<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">Highlights Queue</h1>
        <p class="text-slate-400 text-sm mt-1">Daily results fetched from TheSportsDB — sent every 30 minutes to your highlights topic.</p>
      </div>
      <div class="flex gap-2">
        <button @click="load" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Loading...' : 'Refresh' }}
        </button>
        <button @click="sendAll" :disabled="sending || !total" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60">
          {{ sending ? 'Sending...' : 'Send to Topic' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Sendable Results</p>
        <p class="text-3xl font-bold text-white mt-2">{{ total }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">With Video</p>
        <p class="text-3xl font-bold text-emerald-400 mt-2">{{ withVideo }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Filtered Out</p>
        <p class="text-3xl font-bold text-amber-400 mt-2">{{ filteredOut }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Schedule</p>
        <p class="text-xl font-bold text-white mt-2">Daily 09:00</p>
      </div>
    </div>

    <div v-if="providerTotal || matchedTotal || alreadySent" class="rounded-2xl bg-slate-800/40 border border-slate-700/50 p-4 text-sm text-slate-300">
      <span class="text-white font-semibold">{{ matchedTotal }}</span> matched posted fixture cards,
      <span class="text-amber-300 font-semibold">{{ filteredOut }}</span> skipped,
      <span class="text-slate-400">{{ alreadySent }}</span> already sent.
    </div>

    <div v-if="groups.length" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Results by League</h2>
      <div v-for="group in groups" :key="group.league" class="rounded-xl bg-slate-900/60 border border-slate-700/50 overflow-hidden">
        <div class="flex items-center gap-3 px-4 py-3 bg-slate-800/40 border-b border-slate-700/50">
          <p class="text-white font-semibold">{{ group.league }}</p>
          <span class="text-xs text-slate-400">({{ group.items.length }})</span>
          <span v-if="group.hasVideo" class="text-xs text-emerald-400 ml-auto">{{ group.videoCount }} with video</span>
        </div>
        <div class="divide-y divide-slate-800">
          <div v-for="item in group.items" :key="item.event_id" class="flex items-center gap-3 px-4 py-2.5 text-sm">
            <span class="flex-1 text-slate-200">{{ item.event_name }}</span>
            <span v-if="item.score" class="text-white font-semibold">{{ item.score }}</span>
            <span v-if="item.video_url" class="text-emerald-400 text-xs">▶ Video</span>
            <span v-else class="text-slate-500 text-xs">No video</span>
          </div>
        </div>
      </div>
    </div>
    <div v-else-if="!loading" class="text-sm text-slate-400 text-center py-8">No recent results found.</div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const sending = ref(false)
const summary = ref({})

const total = computed(() => summary.value.total ?? 0)
const providerTotal = computed(() => summary.value.provider_total ?? 0)
const matchedTotal = computed(() => summary.value.matched_total ?? 0)
const filteredOut = computed(() => summary.value.filtered_out_total ?? 0)
const alreadySent = computed(() => summary.value.already_sent_total ?? 0)
const withVideo = computed(() => {
  let c = 0
  for (const h of summary.value.highlights ?? []) {
    if (h.video_url) c++
  }
  return c
})

const groups = computed(() => {
  const h = summary.value.highlights ?? []
  const map = {}
  for (const item of h) {
    const league = item.league || 'Other'
    if (!map[league]) map[league] = { league, items: [], hasVideo: false, videoCount: 0 }
    map[league].items.push(item)
    if (item.video_url) {
      map[league].hasVideo = true
      map[league].videoCount++
    }
  }
  return Object.values(map)
})

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/highlights')
    summary.value = data.summary || {}
  } catch (error) {
    toast.error('Failed to load highlights')
  } finally {
    loading.value = false
  }
}

async function sendAll() {
  sending.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/highlights/send', { limit: 10 })
    if (data.no_eligible_highlights) {
      toast.info('No eligible highlights to send')
    } else {
      toast.success(`Sent ${data.highlight_count ?? data.total ?? 0} highlights`)
    }
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send')
  } finally {
    sending.value = false
  }
}

onMounted(load)
</script>
