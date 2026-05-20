<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Telegram Routing</h1>
        <p class="text-slate-400 text-sm mt-1">Discover topics and assign SportsBot route keys without touching the legacy bot.</p>
      </div>
      <div class="flex items-center gap-2">
        <button
          @click="syncTopics(false)"
          :disabled="syncing"
          class="px-4 py-2 rounded-xl bg-amber-700 text-white hover:bg-amber-600 disabled:opacity-60"
        >
          {{ syncing ? 'Syncing...' : 'Sync Pending' }}
        </button>
        <button
          @click="syncTopics(true)"
          :disabled="syncing"
          class="px-4 py-2 rounded-xl bg-orange-700 text-white hover:bg-orange-600 disabled:opacity-60"
        >
          Reset Offset Sync
        </button>
        <button
          @click="importLegacyTopics"
          :disabled="importingLegacy"
          class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
        >
          {{ importingLegacy ? 'Importing...' : 'Import Legacy Topics' }}
        </button>
        <button
          @click="loadRouting"
          :disabled="loading"
          class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
        >
          Refresh
        </button>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-3">Topic Discovery Status</h2>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Laravel Offset</p>
          <p class="text-white font-semibold mt-1">{{ diagnostics.laravel_offset ?? 0 }}</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Telegram Webhook</p>
          <p class="font-semibold mt-1" :class="diagnostics.webhook?.configured ? 'text-amber-300' : 'text-emerald-300'">
            {{ diagnostics.webhook?.configured ? 'configured' : 'not configured' }}
          </p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Legacy Topics</p>
          <p class="text-white font-semibold mt-1">{{ diagnostics.legacy_state_db?.telegram_topics ?? 'unknown' }}</p>
        </div>
      </div>
      <p class="text-xs text-slate-400 mt-3">
        Telegram cannot list historical forum topics. SportsBot can learn pending bot updates, import topics from the legacy SQLite DB, or learn a topic when you post <span class="text-slate-200">/topic Name</span> inside that Telegram topic.
      </p>
      <p v-if="diagnostics.webhook?.configured" class="text-xs text-amber-300 mt-2">
        A Telegram webhook is currently configured, so getUpdates may not receive new topic messages until that webhook is disabled or Laravel handles the webhook.
      </p>
      <p v-if="diagnostics.webhook?.last_error_message" class="text-xs text-red-300 mt-2">
        Telegram webhook error: {{ diagnostics.webhook.last_error_message }}
      </p>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div
        v-for="key in routeKeys"
        :key="key"
        class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4"
      >
        <div class="flex items-center justify-between gap-2">
          <p class="text-white font-semibold">{{ key }}</p>
          <span
            class="px-2 py-1 rounded text-xs font-medium"
            :class="routeStatuses[key]?.fallback ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-500/20 text-emerald-300'"
          >
            {{ routeStatuses[key]?.fallback ? 'fallback' : 'assigned' }}
          </span>
        </div>
        <p class="text-slate-400 text-xs mt-2">Targets: {{ routeStatuses[key]?.target_count ?? 0 }}</p>
        <p class="text-slate-500 text-xs mt-1 truncate">
          {{ targetText(routeStatuses[key]?.targets || []) }}
        </p>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">Add Topic Manually</h2>
        <p class="text-xs text-slate-400">
          Use this when Telegram has no pending updates. Paste a topic URL like <span class="text-slate-200">https://t.me/c/1234567890/777</span>, or enter chat/thread IDs directly.
        </p>

        <label class="block">
          <span class="text-sm text-slate-300">Topic Title</span>
          <input v-model="manualTopic.title" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="Football" />
        </label>

        <label class="block">
          <span class="text-sm text-slate-300">Telegram Topic URL</span>
          <input v-model="manualTopic.topic_url" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="https://t.me/c/1234567890/777" />
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="block">
            <span class="text-sm text-slate-300">Chat ID</span>
            <input v-model="manualTopic.chat_id" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="-1001234567890" />
          </label>
          <label class="block">
            <span class="text-sm text-slate-300">Thread ID</span>
            <input v-model="manualTopic.message_thread_id" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="777" />
          </label>
        </div>

        <button
          @click="saveManualTopic"
          :disabled="savingTopic"
          class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
        >
          {{ savingTopic ? 'Saving...' : 'Save Topic' }}
        </button>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">Assign Route</h2>

        <label class="block">
          <span class="text-sm text-slate-300">Route Keys</span>
          <select v-model="form.route_keys" multiple size="10" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2">
            <option v-for="key in routeKeys" :key="key" :value="key">{{ key }}</option>
          </select>
          <span class="block text-xs text-slate-500 mt-1">Select every route that should post into this topic.</span>
        </label>

        <label class="block">
          <span class="text-sm text-slate-300">Discovered Topic</span>
          <select v-model="selectedTopicKey" @change="applySelectedTopic" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2">
            <option value="">Manual target</option>
            <option v-for="topic in topics" :key="topicKey(topic)" :value="topicKey(topic)">
              {{ topic.title || 'Untitled topic' }} — {{ topic.chat_id }}:{{ topic.message_thread_id ?? '-' }}
            </option>
          </select>
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="block">
            <span class="text-sm text-slate-300">Chat ID</span>
            <input v-model="form.chat_id" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" />
          </label>
          <label class="block">
            <span class="text-sm text-slate-300">Thread ID</span>
            <input v-model="form.message_thread_id" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="optional" />
          </label>
        </div>

        <label class="block">
          <span class="text-sm text-slate-300">Label</span>
          <input v-model="form.label" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" />
        </label>

        <div class="flex items-center gap-4">
          <label class="inline-flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.enabled" type="checkbox" class="rounded bg-slate-900 border-slate-700" />
            Enabled
          </label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.fallback" type="checkbox" class="rounded bg-slate-900 border-slate-700" />
            Fallback/default target
          </label>
        </div>

        <div class="border-t border-slate-700 pt-4">
          <p class="text-sm font-medium text-slate-300 mb-2">Branding Override</p>
          <p class="text-xs text-slate-500 mb-3">Leave empty to use the global watermark. Set a custom watermark per route/customer.</p>
          <label class="block">
            <span class="text-sm text-slate-300">Watermark Text</span>
            <input v-model="form.branding_watermark" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="e.g. My Brand" />
          </label>
        </div>

        <button
          @click="saveRoute"
          :disabled="saving"
          class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
        >
          {{ saving ? 'Saving...' : 'Save Route' }}
        </button>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 xl:col-span-2">
        <h2 class="text-lg font-semibold text-white mb-4">Saved Routes</h2>
        <div v-if="routes.length === 0" class="text-sm text-slate-400">No saved Telegram routes yet.</div>
        <div v-else class="space-y-3">
          <div
            v-for="route in routes"
            :key="route.id || `${route.route_key}:${route.chat_id}:${route.message_thread_id ?? '-'}`"
              class="rounded-xl bg-slate-900 border border-slate-700 p-3 flex items-center justify-between gap-3"
            >
              <button class="text-left flex-1" @click="editRoute(route)">
                <p class="text-white font-medium">{{ route.route_key }}</p>
                <p class="text-xs text-slate-400">{{ route.chat_id }}:{{ route.message_thread_id ?? '-' }} · {{ route.label }}</p>
                <p v-if="route.branding?.watermark" class="text-xs text-amber-400 mt-1">Watermark: {{ route.branding.watermark }}</p>
              </button>
            <button
              class="text-xs text-cyan-300 hover:text-cyan-200 disabled:opacity-50"
              :disabled="testingRouteKey === route.route_key"
              @click.stop="testSavedRoute(route.route_key)"
            >
              {{ testingRouteKey === route.route_key ? 'Testing...' : 'Test' }}
            </button>
            <button class="text-xs text-red-300 hover:text-red-200" @click.stop="deleteRoute(route.id || route.route_key)">Delete</button>
          </div>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Discovered Topics</h2>
      <div v-if="topics.length === 0" class="text-sm text-slate-400">No topics discovered yet. Send a message in a Telegram forum topic, then sync.</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-slate-400 border-b border-slate-700">
            <tr>
              <th class="text-left py-2">Topic</th>
              <th class="text-left py-2">Target</th>
              <th class="text-left py-2">Source</th>
              <th class="text-left py-2">Last Seen</th>
              <th class="text-left py-2">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="topic in topics" :key="topicKey(topic)" class="border-b border-slate-800">
              <td class="py-2 text-slate-300">{{ topic.title || 'Untitled topic' }}</td>
              <td class="py-2 text-slate-300">{{ topic.chat_id }}:{{ topic.message_thread_id ?? '-' }}</td>
              <td class="py-2 text-slate-300">{{ topic.source || '-' }}</td>
              <td class="py-2 text-slate-300">{{ topic.last_seen_at || '-' }}</td>
              <td class="py-2">
                <div class="flex flex-wrap gap-2">
                  <button class="text-xs text-cyan-300 hover:text-cyan-200" @click="useTopic(topic)">Use</button>
                  <button
                    class="text-xs text-emerald-300 hover:text-emerald-200 disabled:opacity-50"
                    :disabled="saving"
                    @click="assignTopicToCurrentRoute(topic)"
                  >
                    Assign to selected routes
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const syncing = ref(false)
const importingLegacy = ref(false)
const saving = ref(false)
const savingTopic = ref(false)
const testingRouteKey = ref('')
const routeKeys = ref([])
const routes = ref([])
const topics = ref([])
const routeStatuses = ref({})
const diagnostics = ref({})
const selectedTopicKey = ref('')

const form = reactive({
  route_keys: ['FIXTURES_TODAY'],
  label: 'Fixtures Today',
  chat_id: '',
  message_thread_id: '',
  enabled: true,
  fallback: false,
  branding_watermark: '',
})

const manualTopic = reactive({
  title: '',
  topic_url: '',
  chat_id: '',
  message_thread_id: '',
})

function targetText(targets) {
  if (!targets.length) return 'No targets resolved'
  return targets.map(target => `${target.chat_id}:${target.message_thread_id ?? '-'}`).join(', ')
}

function topicKey(topic) {
  return `${topic.chat_id}:${topic.message_thread_id ?? ''}`
}

function optionalThreadId(value) {
  const raw = String(value ?? '').trim()
  if (raw === '') return null
  const parsed = Number(raw)
  return Number.isInteger(parsed) && parsed > 0 ? parsed : null
}

function applySelectedTopic() {
  const topic = topics.value.find(item => topicKey(item) === selectedTopicKey.value)
  if (!topic) return
  useTopic(topic)
}

function useTopic(topic) {
  form.chat_id = topic.chat_id || ''
  form.message_thread_id = topic.message_thread_id || ''
  form.label = topic.title || selectedRouteLabel()
  selectedTopicKey.value = topicKey(topic)
}

function editRoute(route) {
  form.route_keys = [route.route_key]
  form.label = route.label || route.route_key
  form.chat_id = route.chat_id || ''
  form.message_thread_id = route.message_thread_id || ''
  form.enabled = Boolean(route.enabled)
  form.fallback = Boolean(route.fallback)
  form.branding_watermark = route.branding?.watermark || ''
  selectedTopicKey.value = ''
}

async function loadRouting() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/telegram/routes')
    routeKeys.value = data.route_keys || []
    routes.value = data.routes || []
    topics.value = data.topics || []
    routeStatuses.value = data.route_statuses || {}
    diagnostics.value = data.diagnostics || {}
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load SportsBot routing')
  } finally {
    loading.value = false
  }
}

async function syncTopics(resetOffset = false) {
  syncing.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/telegram/topics/sync', {
      limit: 100,
      timeout: 0,
      reset_offset: resetOffset,
    })
    const summary = data.summary || {}
    diagnostics.value = data.diagnostics || diagnostics.value
    toast.success(`Telegram topics synced (${summary.topics_saved || 0} topic update(s))`)
    await loadRouting()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to sync topics')
  } finally {
    syncing.value = false
  }
}

async function importLegacyTopics() {
  importingLegacy.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/telegram/topics/import-legacy')
    routes.value = data.routes || routes.value
    topics.value = data.topics || topics.value
    routeStatuses.value = data.route_statuses || routeStatuses.value
    diagnostics.value = data.diagnostics || diagnostics.value
    const summary = data.summary || {}
    toast.success(`Legacy import complete (${summary.topics_imported || 0} topic(s))`)
  } catch (error) {
    diagnostics.value = error?.response?.data?.diagnostics || diagnostics.value
    toast.error(error?.response?.data?.error || 'Failed to import legacy topics')
  } finally {
    importingLegacy.value = false
  }
}

async function saveManualTopic() {
  savingTopic.value = true
  try {
    const payload = {
      title: manualTopic.title,
      topic_url: manualTopic.topic_url,
      chat_id: manualTopic.chat_id,
      message_thread_id: optionalThreadId(manualTopic.message_thread_id),
    }
    const { data } = await api.post('/admin/sportsbot/telegram/topics', payload)
    topics.value = data.topics || topics.value
    toast.success('Telegram topic saved')
    manualTopic.title = ''
    manualTopic.topic_url = ''
    manualTopic.chat_id = ''
    manualTopic.message_thread_id = ''
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Failed to save topic')
  } finally {
    savingTopic.value = false
  }
}

async function saveRoute() {
  saving.value = true
  try {
    const branding = form.branding_watermark
      ? { watermark: form.branding_watermark }
      : null
    const payload = {
      ...form,
      branding,
      route_key: form.route_keys[0] || '',
      message_thread_id: optionalThreadId(form.message_thread_id),
    }
    const { data } = await api.post('/admin/sportsbot/telegram/routes', payload)
    routes.value = data.routes || routes.value
    routeStatuses.value = data.route_statuses || routeStatuses.value
    toast.success('Telegram route saved')
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Failed to save route')
  } finally {
    saving.value = false
  }
}

async function assignTopicToCurrentRoute(topic) {
  useTopic(topic)
  await saveRoute()
}

async function testSavedRoute(routeKey) {
  testingRouteKey.value = routeKey
  try {
    const { data } = await api.post('/admin/sportsbot/test-route', {
      route_key: routeKey,
      send: true,
    })
    routeStatuses.value = {
      ...routeStatuses.value,
      [routeKey]: data.resolved || routeStatuses.value[routeKey],
    }
    toast.success(`Route test sent (${(data.results || []).length} target(s))`)
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Route test failed')
  } finally {
    testingRouteKey.value = ''
  }
}

async function deleteRoute(routeKey) {
  try {
    const { data } = await api.delete(`/admin/sportsbot/telegram/routes/${routeKey}`)
    routes.value = data.routes || []
    routeStatuses.value = data.route_statuses || {}
    toast.success('Telegram route deleted')
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to delete route')
  }
}

function selectedRouteLabel() {
  return form.route_keys.length === 1 ? form.route_keys[0] : 'Selected routes'
}

onMounted(loadRouting)
</script>
