<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Scraper Settings</h1>
        <p class="text-slate-400 text-sm mt-1">Public-page enrichment for posters, TV channels and F1 session times.</p>
      </div>
      <div class="flex gap-2">
        <button @click="load" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
        <button @click="save" :disabled="saving" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 disabled:opacity-60">
          {{ saving ? 'Saving...' : 'Save' }}
        </button>
      </div>
    </div>

    <div v-if="diagnostics.default_search_warning" class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 text-sm text-amber-100">
      {{ diagnostics.default_search_warning }}
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 xl:col-span-2">
        <h2 class="text-lg font-semibold text-white mb-4">Search Setup</h2>
        <div class="space-y-4">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Enable scraper enrichment actions
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.search_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Search public pages for candidate sources
          </label>

          <div>
            <label class="block text-sm font-medium text-slate-300 mb-2">Search endpoint URLs</label>
            <textarea v-model="searchUrlText" rows="5" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" :placeholder="examples.search_url" />
            <p class="text-xs text-slate-500 mt-2">Use one URL per line. The scraper replaces {query} with the event search phrase.</p>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <label class="block">
              <span class="block text-sm font-medium text-slate-300 mb-2">Max results</span>
              <input v-model.number="form.search_max_results" type="number" min="1" max="20" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm">
            </label>
            <label class="block">
              <span class="block text-sm font-medium text-slate-300 mb-2">Timeout seconds</span>
              <input v-model.number="form.timeout" type="number" min="2" max="30" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm">
            </label>
            <label class="block">
              <span class="block text-sm font-medium text-slate-300 mb-2">Auto-use confidence</span>
              <input v-model.number="form.auto_use_confidence" type="number" min="0" max="1" step="0.05" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm">
            </label>
          </div>
        </div>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Status</h2>
        <div class="space-y-3 text-sm">
          <div class="flex justify-between gap-3">
            <span class="text-slate-400">Enrichment</span>
            <span :class="form.enabled ? 'text-emerald-300' : 'text-slate-300'">{{ form.enabled ? 'enabled' : 'off' }}</span>
          </div>
          <div class="flex justify-between gap-3">
            <span class="text-slate-400">Search fallback</span>
            <span :class="diagnostics.search_configured ? 'text-emerald-300' : 'text-slate-300'">{{ diagnostics.search_configured ? 'configured' : 'off' }}</span>
          </div>
          <div class="flex justify-between gap-3">
            <span class="text-slate-400">Search endpoints</span>
            <span class="text-white">{{ form.search_urls.length }}</span>
          </div>
          <div class="flex justify-between gap-3">
            <span class="text-slate-400">Known sources</span>
            <span class="text-white">{{ diagnostics.known_source_count ?? 0 }}</span>
          </div>
        </div>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">Poster Sources</h2>
        <textarea v-model="combatPosterUrlText" rows="8" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="One public event page per line" />
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">TV Sources</h2>
        <textarea v-model="broadcastUrlText" rows="8" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" :placeholder="examples.known_source_url" />
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
        <h2 class="text-lg font-semibold text-white mb-4">F1 Sources</h2>
        <textarea v-model="f1UrlText" rows="8" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="One public schedule page per line" />
      </div>
    </div>

    <details class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <summary class="cursor-pointer text-lg font-semibold text-white">Advanced Search Queries</summary>
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-4 mt-4">
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-2">Poster queries</label>
          <textarea v-model="combatPosterQueryText" rows="6" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="{event_name} fight poster official" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-2">TV queries</label>
          <textarea v-model="broadcastQueryText" rows="6" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="{event_name} UK TV channel" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-300 mb-2">F1 queries</label>
          <textarea v-model="f1QueryText" rows="6" class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm" placeholder="{event_name} F1 session schedule UK time" />
        </div>
      </div>
    </details>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const diagnostics = ref({})
const examples = ref({})
const form = reactive({
  enabled: true,
  search_enabled: true,
  search_urls: [],
  search_max_results: 5,
  timeout: 8,
  auto_use_confidence: 0.9,
  combat_poster_urls: [],
  broadcast_schedule_urls: [],
  f1_schedule_urls: [],
  combat_poster_search_queries: [],
  broadcast_schedule_search_queries: [],
  f1_schedule_search_queries: []
})

const searchUrlText = listField('search_urls')
const combatPosterUrlText = listField('combat_poster_urls')
const broadcastUrlText = listField('broadcast_schedule_urls')
const f1UrlText = listField('f1_schedule_urls')
const combatPosterQueryText = listField('combat_poster_search_queries')
const broadcastQueryText = listField('broadcast_schedule_search_queries')
const f1QueryText = listField('f1_schedule_search_queries')

function listField(key) {
  return computed({
    get: () => form[key].join('\n'),
    set: value => { form[key] = splitLines(value) }
  })
}

function splitLines(value) {
  return String(value || '').split(/\n|,/).map(item => item.trim()).filter(Boolean)
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/scraper-settings')
    Object.assign(form, data.settings || {})
    diagnostics.value = data.diagnostics || {}
    examples.value = data.examples || {}
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load scraper settings')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    await api.post('/admin/sportsbot/scraper-settings', form)
    toast.success('Scraper settings saved')
    await load()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to save scraper settings')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
