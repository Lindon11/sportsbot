<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-3 md:flex-row md:justify-between md:items-center">
      <div>
        <h1 class="text-2xl font-bold text-white">Telegram Settings</h1>
        <p class="text-gray-400 mt-1">Bot connection, webhook status, and Telegram delivery diagnostics.</p>
      </div>
      <button
        type="button"
        @click="loadAll"
        :disabled="loading || diagnosticsLoading"
        class="px-4 py-2 bg-gray-700 hover:bg-gray-600 disabled:bg-gray-700 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors"
      >
        {{ loading || diagnosticsLoading ? 'Refreshing...' : 'Refresh' }}
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="bg-gray-800 rounded-lg p-4">
        <div class="text-sm text-gray-400">Bot token</div>
        <div :class="['mt-2 text-lg font-semibold', settings.bot_token_configured ? 'text-green-300' : 'text-red-300']">
          {{ settings.bot_token_configured ? 'Configured' : 'Missing' }}
        </div>
        <div v-if="settings.bot_token" class="mt-1 text-xs text-gray-500">{{ settings.bot_token }}</div>
      </div>

      <div class="bg-gray-800 rounded-lg p-4">
        <div class="text-sm text-gray-400">Webhook mode</div>
        <div :class="['mt-2 text-lg font-semibold', settings.webhook_enabled ? 'text-green-300' : 'text-yellow-300']">
          {{ settings.webhook_enabled ? 'Enabled' : 'Disabled' }}
        </div>
        <div class="mt-1 text-xs text-gray-500">Controls whether this app accepts Telegram callbacks.</div>
      </div>

      <div class="bg-gray-800 rounded-lg p-4">
        <div class="text-sm text-gray-400">Remote Telegram webhook</div>
        <div :class="['mt-2 text-lg font-semibold', remoteHealthy ? 'text-green-300' : 'text-yellow-300']">
          {{ remoteStatus }}
        </div>
        <div class="mt-1 text-xs text-gray-500 truncate">{{ diagnostics.telegram_webhook_health?.url || 'No remote URL reported' }}</div>
      </div>
    </div>

    <div v-if="loading" class="text-gray-400 py-8 text-center">Loading...</div>

    <template v-else>
      <div v-if="error" class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg">
        {{ error }}
      </div>

      <div v-if="saved" class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg">
        {{ saved }}
      </div>

      <form @submit.prevent="saveSettings" class="space-y-6">
        <div class="bg-gray-800 rounded-lg p-6 space-y-4">
          <h3 class="text-lg font-medium text-white">Bot Token</h3>

          <div>
            <label class="block text-sm font-medium text-gray-300 mb-1">Telegram Bot Token</label>
            <input
              v-model="form.bot_token"
              type="password"
              placeholder="1234567890:ABCdefGHIjklmNOPqrstUVwxyz"
              class="w-full bg-gray-700 border border-gray-600 rounded-md px-3 py-2 text-white placeholder-gray-400 focus:ring-amber-500 focus:border-amber-500"
            />
            <p class="text-xs text-gray-500 mt-1">
              Get this from <a href="https://t.me/BotFather" target="_blank" class="text-amber-500 hover:underline">@BotFather</a> on Telegram.
            </p>
          </div>

          <div v-if="settings.bot_token_configured" class="flex items-center p-3 bg-gray-900 rounded-lg">
            <span class="text-green-400 mr-2">&#9679;</span>
            <span class="text-gray-300">Bot token is configured</span>
            <span class="text-gray-500 text-sm ml-2">({{ settings.bot_token }})</span>
          </div>
          <div v-else class="flex items-center p-3 bg-gray-900 rounded-lg">
            <span class="text-red-400 mr-2">&#9679;</span>
            <span class="text-gray-300">Bot token is not configured</span>
          </div>
        </div>

        <div class="flex items-center justify-between p-4 bg-gray-800 rounded-lg">
          <div>
            <h4 class="text-white font-medium">Webhook</h4>
            <p class="text-sm text-gray-400">When enabled, Telegram will send updates to this server</p>
          </div>
          <label class="relative inline-flex items-center cursor-pointer">
            <input type="checkbox" v-model="form.webhook_enabled" class="sr-only peer">
            <div class="w-11 h-6 bg-gray-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-amber-500 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
          </label>
        </div>

        <div v-if="settings.webhook_url" class="bg-gray-800 rounded-lg p-4">
          <label class="block text-sm font-medium text-gray-400 mb-1">Webhook URL</label>
          <code class="text-sm text-amber-400 break-all">{{ settings.webhook_url }}</code>
        </div>

        <div class="flex flex-wrap justify-end gap-3">
          <button
            type="button"
            @click="setWebhook"
            :disabled="webhookBusy || saving || !settings.bot_token_configured && !form.bot_token"
            class="px-6 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors"
          >
            {{ webhookBusy === 'set' ? 'Setting...' : 'Set Telegram Webhook' }}
          </button>
          <button
            type="button"
            @click="deleteWebhook"
            :disabled="webhookBusy || !settings.bot_token_configured"
            class="px-6 py-2 bg-gray-700 hover:bg-gray-600 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors"
          >
            {{ webhookBusy === 'delete' ? 'Deleting...' : 'Delete Telegram Webhook' }}
          </button>
          <button
            type="submit"
            :disabled="saving"
            class="px-6 py-2 bg-amber-500 hover:bg-amber-600 disabled:bg-gray-600 disabled:cursor-not-allowed text-black font-medium rounded-lg transition-colors"
          >
            {{ saving ? 'Saving...' : 'Save Settings' }}
          </button>
        </div>
      </form>

      <div class="bg-gray-800 rounded-lg p-6 space-y-4">
        <div class="flex items-center justify-between gap-4">
          <h3 class="text-lg font-medium text-white">Webhook Diagnostics</h3>
          <button
            type="button"
            @click="loadDiagnostics"
            :disabled="diagnosticsLoading"
            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 disabled:bg-gray-600 disabled:cursor-not-allowed text-white text-sm font-medium rounded-lg transition-colors"
          >
            {{ diagnosticsLoading ? 'Checking...' : 'Check' }}
          </button>
        </div>

        <div v-if="diagnostics.error" class="bg-yellow-900/40 border border-yellow-700 text-yellow-200 px-4 py-3 rounded-lg">
          {{ diagnostics.error }}
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="text-gray-500">Expected URL</div>
            <code class="block mt-1 text-amber-400 break-all">{{ diagnostics.webhook_url || settings.webhook_url || '-' }}</code>
          </div>
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="text-gray-500">Remote URL</div>
            <code class="block mt-1 text-amber-400 break-all">{{ diagnostics.telegram_webhook_health?.url || '-' }}</code>
          </div>
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="text-gray-500">Pending updates</div>
            <div class="mt-1 text-white font-medium">{{ diagnostics.telegram_webhook_health?.pending_update_count ?? '-' }}</div>
          </div>
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="text-gray-500">Last callback</div>
            <div class="mt-1 text-white font-medium">{{ diagnostics.last_callback_received || '-' }}</div>
          </div>
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="text-gray-500">Last webhook</div>
            <div class="mt-1 text-white font-medium">{{ diagnostics.last_webhook_received || '-' }}</div>
          </div>
          <div class="bg-gray-900 rounded-lg p-4">
            <div class="text-gray-500">Last remote error</div>
            <div :class="['mt-1 font-medium', diagnostics.telegram_webhook_health?.last_error_message ? 'text-red-300' : 'text-white']">
              {{ diagnostics.telegram_webhook_health?.last_error_message || 'None' }}
            </div>
          </div>
        </div>
      </div>

      <div class="bg-gray-800 rounded-lg p-6">
        <h3 class="text-lg font-medium text-white mb-2">Clear Cache</h3>
        <p class="text-sm text-gray-400 mb-4">Clear Laravel config, route, view, and application cache.</p>
        <button
          @click="clearCache"
          :disabled="clearing"
          class="px-6 py-2 bg-red-600 hover:bg-red-700 disabled:bg-gray-600 disabled:cursor-not-allowed text-white font-medium rounded-lg transition-colors"
        >
          {{ clearing ? 'Clearing...' : 'Clear Cache' }}
        </button>
        <div v-if="cacheMessage" :class="['mt-3 text-sm px-3 py-2 rounded', cacheOk ? 'bg-green-900/50 text-green-300' : 'bg-red-900/50 text-red-300']">
          {{ cacheMessage }}
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

interface TelegramSettingsResponse {
  bot_token_configured: boolean
  bot_token: string
  webhook_enabled: boolean
  webhook_url: string
}

interface TelegramWebhookHealth {
  url?: string
  pending_update_count?: number
  last_error_date?: string | null
  last_error_message?: string | null
  max_connections?: number | null
  healthy?: boolean
  error?: string
}

interface TelegramDiagnosticsResponse {
  webhook_enabled?: boolean
  webhook_url?: string
  bot_token_configured?: boolean
  error?: string | null
  last_webhook_received?: string | null
  last_callback_received?: string | null
  last_callback_data?: string | null
  last_callback_action?: string | null
  last_callback_handler?: string | null
  last_callback_error?: string | null
  telegram_webhook_health?: TelegramWebhookHealth | null
}

interface SaveSettingsResponse {
  saved: boolean
  bot_token_configured: boolean
}

interface CacheClearResponse {
  message?: string
}

const loading = ref(true)
const saving = ref(false)
const saved = ref('')
const error = ref('')

const clearing = ref(false)
const cacheMessage = ref('')
const cacheOk = ref(false)
const diagnosticsLoading = ref(false)
const webhookBusy = ref<'' | 'set' | 'delete'>('')

const settings = reactive({
  bot_token_configured: false,
  bot_token: '',
  webhook_enabled: false,
  webhook_url: '',
})

const form = reactive({
  bot_token: '',
  webhook_enabled: false,
})

const diagnostics = reactive<TelegramDiagnosticsResponse>({})

const remoteHealthy = computed(() => diagnostics.telegram_webhook_health?.healthy === true)
const remoteStatus = computed(() => {
  if (diagnostics.telegram_webhook_health?.error) {
    return 'Error'
  }

  if (diagnostics.telegram_webhook_health?.url) {
    return remoteHealthy.value ? 'Healthy' : 'Needs attention'
  }

  return 'Not set'
})

function errorMessage(e: unknown, fallback: string): string {
  const maybe = e as { response?: { data?: { message?: string; error?: string } } }
  return maybe.response?.data?.message || maybe.response?.data?.error || fallback
}

async function loadSettings() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get<TelegramSettingsResponse>('/api/v1/admin/sportsbot/telegram/settings', { forceRefresh: true })
    settings.bot_token_configured = res.data.bot_token_configured
    settings.bot_token = res.data.bot_token
    settings.webhook_enabled = res.data.webhook_enabled
    settings.webhook_url = res.data.webhook_url
    form.webhook_enabled = res.data.webhook_enabled
  } catch (e: unknown) {
    error.value = errorMessage(e, 'Failed to load settings')
  } finally {
    loading.value = false
  }
}

async function loadDiagnostics() {
  diagnosticsLoading.value = true
  try {
    const res = await api.get<TelegramDiagnosticsResponse>('/api/v1/admin/sportsbot/telegram/webhook/diagnostics', { forceRefresh: true })
    Object.assign(diagnostics, res.data)
  } catch (e: unknown) {
    error.value = errorMessage(e, 'Failed to load webhook diagnostics')
  } finally {
    diagnosticsLoading.value = false
  }
}

async function loadAll() {
  await loadSettings()
  await loadDiagnostics()
}

async function saveSettings(showSaved = true) {
  saving.value = true
  saved.value = ''
  error.value = ''
  try {
    const payload: Record<string, any> = {
      webhook_enabled: form.webhook_enabled,
    }
    if (form.bot_token) {
      payload.bot_token = form.bot_token
    }
    const res = await api.post<SaveSettingsResponse>('/api/v1/admin/sportsbot/telegram/settings', payload)
    settings.bot_token_configured = res.data.bot_token_configured
    if (form.bot_token) {
      settings.bot_token = form.bot_token.substring(0, 6) + '...'
    }
    settings.webhook_enabled = form.webhook_enabled
    form.bot_token = ''
    if (showSaved) {
      saved.value = 'Settings saved successfully.'
      setTimeout(() => { saved.value = '' }, 3000)
    }
    await loadDiagnostics()
  } catch (e: unknown) {
    error.value = errorMessage(e, 'Failed to save settings')
  } finally {
    saving.value = false
  }
}

async function setWebhook() {
  webhookBusy.value = 'set'
  saved.value = ''
  error.value = ''
  try {
    if (!form.webhook_enabled) {
      form.webhook_enabled = true
      await saveSettings(false)
    }

    await api.post('/api/v1/admin/sportsbot/telegram/webhook/set')
    saved.value = 'Telegram webhook set successfully.'
    await loadAll()
  } catch (e: unknown) {
    error.value = errorMessage(e, 'Failed to set Telegram webhook')
  } finally {
    webhookBusy.value = ''
  }
}

async function deleteWebhook() {
  webhookBusy.value = 'delete'
  saved.value = ''
  error.value = ''
  try {
    await api.delete('/api/v1/admin/sportsbot/telegram/webhook')
    saved.value = 'Telegram webhook deleted successfully.'
    await loadDiagnostics()
  } catch (e: unknown) {
    error.value = errorMessage(e, 'Failed to delete Telegram webhook')
  } finally {
    webhookBusy.value = ''
  }
}

async function clearCache() {
  clearing.value = true
  cacheMessage.value = ''
  try {
    const res = await api.post<CacheClearResponse>('/api/v1/admin/cache/clear')
    cacheOk.value = true
    cacheMessage.value = res.data?.message || 'Cache cleared successfully'
  } catch (e: unknown) {
    cacheOk.value = false
    cacheMessage.value = errorMessage(e, 'Failed to clear cache')
  } finally {
    clearing.value = false
  }
}

onMounted(loadAll)
</script>
