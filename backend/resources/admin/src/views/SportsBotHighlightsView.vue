<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">Match Highlights</h1>
        <p class="text-slate-400 text-sm mt-1">Recent match highlights with video links from TheSportsDB.</p>
      </div>
      <div class="flex gap-2">
        <button @click="loadPreview" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Loading...' : 'Refresh' }}
        </button>
        <button @click="previewCards" :disabled="loadingCards || !summary.total" class="px-4 py-2 rounded-xl bg-purple-700 text-white hover:bg-purple-600 disabled:opacity-60">
          {{ loadingCards ? 'Rendering...' : 'Preview Cards' }}
        </button>
        <select v-model="sendLimit" class="px-3 py-2 rounded-xl bg-slate-900 border border-slate-700 text-white text-sm">
          <option :value="1">1 card</option>
          <option :value="3">3 cards</option>
          <option :value="5">5 cards</option>
          <option :value="10">10 cards</option>
        </select>
        <button @click="sendHighlights" :disabled="sending || !summary.total" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60">
          {{ sending ? 'Sending...' : 'Send to Topic' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Total Highlights</p>
        <p class="text-3xl font-bold text-white mt-2">{{ summary.total ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Sports Covered</p>
        <p class="text-3xl font-bold text-white mt-2">{{ sportCount }}</p>
      </div>
    </div>

    <div v-if="highlights.length > 0" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Highlights ({{ highlights.length }})</h2>
      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <div v-for="card in highlights" :key="card.event_name" class="rounded-2xl bg-slate-900 border border-slate-700 overflow-hidden">
          <img :src="card.data_url" :alt="card.event_name" class="w-full block" />
          <div class="p-4 space-y-2">
            <p class="text-white font-semibold">{{ card.event_name }}</p>
            <a :href="card.video_url" target="_blank" class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs hover:bg-red-500">
              ▶ Watch on YouTube
            </a>
          </div>
        </div>
      </div>
      <p v-if="highlights.length === 0" class="text-sm text-slate-500 text-center py-4">No highlights with available thumbnails to preview.</p>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const loadingCards = ref(false)
const sending = ref(false)
const summary = ref({})
const highlights = ref([])
const cardVersion = ref('v3')
const sendLimit = ref(1)

const sportCount = computed(() => {
  if (!summary.value.highlights) return 0
  const sports = new Set()
  summary.value.highlights.forEach(h => sports.add(h.sport))
  return sports.size
})

async function loadPreview() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/highlights', {
      params: { card_version: cardVersion.value, render_cards: false }
    })
    summary.value = data.summary || {}
    highlights.value = []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load highlights')
  } finally {
    loading.value = false
  }
}

async function previewCards() {
  loadingCards.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/highlights', {
      params: { card_version: cardVersion.value, render_cards: true }
    })
    highlights.value = data.card_previews || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to render cards')
  } finally {
    loadingCards.value = false
  }
}

async function sendHighlights() {
  sending.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/highlights/send', {
      card_version: cardVersion.value,
      limit: sendLimit.value
    })
    toast.success(`Sent ${data.total} highlight(s)`)
    await loadPreview()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send highlights')
  } finally {
    sending.value = false
  }
}

onMounted(loadPreview)
</script>
