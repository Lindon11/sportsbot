<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Discord Routes</h1>
        <p class="text-slate-400 text-sm mt-1">Assign Discord webhooks to SportsBot route keys.</p>
      </div>
      <button
        @click="load"
        :disabled="loading"
        class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
      >
        {{ loading ? 'Refreshing...' : 'Refresh' }}
      </button>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 xl:col-span-2 space-y-4">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div>
            <h2 class="text-lg font-semibold text-white">Global Discord Settings</h2>
            <p class="text-xs mt-1" :class="settingsForm.discord_enabled ? 'text-emerald-300' : 'text-slate-500'">
              {{ settingsForm.discord_enabled ? 'Discord delivery enabled' : 'Discord delivery disabled' }}
            </p>
          </div>
          <button
            @click="saveSettings"
            :disabled="savingSettings"
            class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
          >
            {{ savingSettings ? 'Saving...' : 'Save Settings' }}
          </button>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-300">
          <input v-model="settingsForm.discord_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
          Enable Discord webhook delivery
        </label>

        <label class="block">
          <span class="text-sm text-slate-300">Default Webhook URL</span>
          <input
            v-model="settingsForm.discord_default_webhook_url"
            type="password"
            autocomplete="off"
            class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2"
            placeholder="https://discord.com/api/webhooks/..."
          />
          <span class="block text-xs text-slate-500 mt-1">Used when a route does not have its own webhook.</span>
        </label>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <label class="block">
            <span class="text-sm text-slate-300">Bot Name</span>
            <input v-model="settingsForm.discord_username" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="SportsBot" />
          </label>
          <label class="block">
            <span class="text-sm text-slate-300">Avatar URL</span>
            <input v-model="settingsForm.discord_avatar_url" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2" placeholder="Optional image URL" />
          </label>
        </div>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">Add Route Webhook</h2>

        <label class="block">
          <span class="text-sm text-slate-300">Route Keys</span>
          <select v-model="form.route_keys" multiple size="10" class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2">
            <option v-for="key in routeKeys" :key="key" :value="key">{{ key }}</option>
          </select>
          <span class="block text-xs text-slate-500 mt-1">Select every route that should post to this webhook.</span>
        </label>

        <label class="block">
          <span class="text-sm text-slate-300">Webhook URL</span>
          <input
            v-model="form.webhook_url"
            type="password"
            autocomplete="off"
            class="mt-1 w-full rounded-xl bg-slate-900 border border-slate-700 text-white px-3 py-2"
            placeholder="https://discord.com/api/webhooks/..."
          />
        </label>

        <div class="flex flex-wrap gap-2">
          <button
            @click="saveRoute"
            :disabled="savingRoute"
            class="px-4 py-2 rounded-xl bg-indigo-700 text-white hover:bg-indigo-600 disabled:opacity-60"
          >
            {{ savingRoute ? 'Saving...' : 'Save Route' }}
          </button>
          <button
            @click="testRoute(form.route_keys[0])"
            :disabled="testingRouteKey === form.route_keys[0] || !settingsForm.discord_enabled || form.route_keys.length === 0"
            class="px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 disabled:opacity-60"
          >
            {{ testingRouteKey === form.route_keys[0] ? 'Testing...' : 'Test First Route' }}
          </button>
        </div>
      </div>
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
            :class="routeStatuses[key]?.configured ? 'bg-emerald-500/20 text-emerald-300' : 'bg-slate-700 text-slate-300'"
          >
            {{ routeStatuses[key]?.configured ? 'configured' : 'not set' }}
          </span>
        </div>
        <p class="text-slate-400 text-xs mt-2">Source: {{ routeStatuses[key]?.source || 'none' }}</p>
        <p v-if="routeStatuses[key]?.fallback" class="text-amber-300 text-xs mt-1">Using default webhook</p>
        <button
          v-if="botChannels[key]"
          @click="clearChannel(key)"
          :disabled="clearingChannel === key"
          class="mt-2 text-xs text-red-400 hover:text-red-300 disabled:opacity-50"
        >
          {{ clearingChannel === key ? 'Clearing...' : 'Clear channel' }}
        </button>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Saved Discord Routes</h2>
      <div v-if="routes.length === 0" class="text-sm text-slate-400">No Discord route webhooks saved yet.</div>
      <div v-else class="space-y-3">
        <div
          v-for="route in routes"
          :key="route.route_key"
          class="rounded-xl bg-slate-900 border border-slate-700 p-3 flex items-center justify-between gap-3"
        >
          <button class="text-left flex-1 min-w-0" @click="editRoute(route)">
            <p class="text-white font-medium">{{ route.route_key }}</p>
            <p class="text-xs text-slate-400 truncate">{{ maskedWebhook(route.webhook_url) }}</p>
          </button>
          <span class="text-xs text-slate-500">{{ route.source }}</span>
          <button
            class="text-xs text-cyan-300 hover:text-cyan-200 disabled:opacity-50"
            :disabled="testingRouteKey === route.route_key || !settingsForm.discord_enabled"
            @click.stop="testRoute(route.route_key)"
          >
            {{ testingRouteKey === route.route_key ? 'Testing...' : 'Test' }}
          </button>
          <button class="text-xs text-red-300 hover:text-red-200" @click.stop="deleteRoute(route.route_key)">Delete</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const savingSettings = ref(false)
const savingRoute = ref(false)
const testingRouteKey = ref('')
const clearingChannel = ref('')
const routeKeys = ref([])
const routes = ref([])
const routeStatuses = ref({})
const settings = ref({})

const settingsForm = reactive({
  discord_enabled: false,
  discord_default_webhook_url: '',
  discord_username: 'SportsBot',
  discord_avatar_url: '',
})

const form = reactive({
  route_keys: ['FORMULA_1'],
  webhook_url: '',
})

const botChannels = computed(() => {
  const channels = settings.value?.discord_bot_channels || {}
  const map = {}
  for (const [routeKey, info] of Object.entries(channels)) {
    map[routeKey] = info
  }
  return map
})

async function clearChannel(routeKey) {
  const channel = botChannels.value[routeKey]
  if (!channel) return
  if (!confirm(`Delete the bot's recent messages in #${channel.name || channel.id}?`)) return
  clearingChannel.value = routeKey
  try {
    const { data } = await api.post('/admin/sportsbot/discord/clear-channel', {
      channel_id: channel.id,
      limit: 100,
    })
    toast.success(`Cleared ${data.deleted} bot messages from #${channel.name || channel.id}`)
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to clear channel')
  } finally {
    clearingChannel.value = ''
  }
}

function applyData(data) {
  routeKeys.value = data.route_keys || []
  routes.value = data.routes || []
  routeStatuses.value = data.route_statuses || {}
  settings.value = data.settings || {}
  Object.assign(settingsForm, {
    discord_enabled: data.settings?.discord_enabled ?? false,
    discord_default_webhook_url: data.settings?.discord_default_webhook_url || '',
    discord_username: data.settings?.discord_username || 'SportsBot',
    discord_avatar_url: data.settings?.discord_avatar_url || '',
  })
}

function maskedWebhook(value) {
  const text = String(value || '')
  if (text.length <= 28) return text
  return `${text.slice(0, 28)}...${text.slice(-8)}`
}

function editRoute(route) {
  form.route_keys = [route.route_key]
  form.webhook_url = route.webhook_url || ''
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/discord/routes')
    applyData(data)
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load Discord routes')
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  savingSettings.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/discord/settings', settingsForm)
    settingsForm.discord_enabled = data.settings?.discord_enabled ?? settingsForm.discord_enabled
    settingsForm.discord_default_webhook_url = data.settings?.discord_default_webhook_url || ''
    settingsForm.discord_username = data.settings?.discord_username || 'SportsBot'
    settingsForm.discord_avatar_url = data.settings?.discord_avatar_url || ''
    routeStatuses.value = data.route_statuses || routeStatuses.value
    toast.success('Discord settings saved')
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Failed to save Discord settings')
  } finally {
    savingSettings.value = false
  }
}

async function saveRoute() {
  savingRoute.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/discord/routes', {
      ...form,
      route_key: form.route_keys[0] || '',
    })
    routes.value = data.routes || routes.value
    routeStatuses.value = data.route_statuses || routeStatuses.value
    toast.success('Discord route saved')
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Failed to save Discord route')
  } finally {
    savingRoute.value = false
  }
}

async function testRoute(routeKey) {
  testingRouteKey.value = routeKey
  try {
    const { data } = await api.post('/admin/sportsbot/discord/routes/test', { route_key: routeKey })
    toast.success(`Discord test sent (${(data.results || []).length} webhook(s))`)
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Discord test failed')
  } finally {
    testingRouteKey.value = ''
  }
}

async function deleteRoute(routeKey) {
  try {
    const { data } = await api.delete(`/admin/sportsbot/discord/routes/${routeKey}`)
    routes.value = data.routes || []
    routeStatuses.value = data.route_statuses || {}
    settingsForm.discord_default_webhook_url = data.settings?.discord_default_webhook_url || settingsForm.discord_default_webhook_url
    toast.success('Discord route deleted')
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to delete Discord route')
  }
}

onMounted(load)
</script>
