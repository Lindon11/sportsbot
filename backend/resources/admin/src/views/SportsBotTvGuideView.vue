<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot TV Guide</h1>
        <p class="text-slate-400 text-sm mt-1">Preview and publish TV listings through the TV_GUIDE Telegram route.</p>
      </div>
      <button
        @click="loadPreview"
        :disabled="loadingPreview"
        class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
      >
        {{ loadingPreview ? 'Refreshing...' : 'Refresh Preview' }}
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Route Targets</p>
        <p class="text-3xl font-bold text-white mt-2">{{ routeStatus.target_count ?? 0 }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Fallback</p>
        <p class="text-3xl font-bold mt-2" :class="routeStatus.fallback ? 'text-amber-400' : 'text-emerald-400'">
          {{ routeStatus.fallback ? 'true' : 'false' }}
        </p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">TV Events</p>
        <p class="text-3xl font-bold text-white mt-2">{{ summary.events_total ?? 0 }}</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-semibold text-white">TV Guide Preview</h2>
          <p class="text-xs text-slate-400">Route: TV_GUIDE (resolved: {{ routeStatus.resolved_route_key || 'default' }})</p>
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="testRoute"
            :disabled="testingRoute"
            class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
          >
            {{ testingRoute ? 'Testing...' : 'Test Route' }}
          </button>
          <button
            @click="sendTvGuide"
            :disabled="sending"
            class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
          >
            {{ sending ? 'Sending...' : 'Send TV Guide' }}
          </button>
        </div>
      </div>

      <pre class="whitespace-pre-wrap text-sm text-slate-100 bg-slate-900/80 border border-slate-700 rounded-xl p-4 min-h-[320px]">{{ previewMessage || 'No preview loaded yet.' }}</pre>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Configured Channels</h2>
      <div v-if="channels.length === 0" class="text-sm text-slate-400">No channels configured.</div>
      <div v-else class="flex flex-wrap gap-2">
        <span
          v-for="channel in channels"
          :key="channel.slug"
          class="px-3 py-1 rounded-full bg-slate-900 border border-slate-700 text-slate-200 text-sm"
        >
          {{ channel.label }}
        </span>
      </div>
      <p v-if="errors.length > 0" class="text-xs text-amber-300 mt-4">
        Some channels could not be fetched. Check Laravel logs for details.
      </p>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loadingPreview = ref(false)
const testingRoute = ref(false)
const sending = ref(false)
const routeStatus = ref({})
const summary = ref({})
const previewMessage = ref('')

const channels = ref([])
const errors = ref([])

async function loadPreview() {
  loadingPreview.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/tv-guide/preview')
    previewMessage.value = data.message || ''
    routeStatus.value = data.route_status || {}
    summary.value = data.summary || {}
    channels.value = summary.value.channels || []
    errors.value = summary.value.errors || []
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load TV guide preview')
  } finally {
    loadingPreview.value = false
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/test-route', {
      route_key: 'TV_GUIDE',
      send: true,
    })
    routeStatus.value = data.resolved || routeStatus.value
    toast.success(`Route test sent (${(data.results || []).length} target(s))`)
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Route test failed')
  } finally {
    testingRoute.value = false
  }
}

async function sendTvGuide() {
  sending.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/tv-guide/send')
    previewMessage.value = data.message || previewMessage.value
    routeStatus.value = data.route_status || routeStatus.value
    summary.value = data.summary || summary.value
    toast.success(`TV guide sent (${(data.results || []).length} target(s))`)
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to send TV guide')
  } finally {
    sending.value = false
  }
}

onMounted(loadPreview)
</script>
