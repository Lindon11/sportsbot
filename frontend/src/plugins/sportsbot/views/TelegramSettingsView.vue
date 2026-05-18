<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-white">Telegram Settings</h1>
        <p class="text-gray-400 mt-1">Configure your Telegram bot connection</p>
      </div>
    </div>

    <div v-if="loading" class="text-gray-400 py-8 text-center">Loading...</div>

    <template v-else>
      <div v-if="error" class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg">
        {{ error }}
      </div>

      <div v-if="saved" class="bg-green-900/50 border border-green-700 text-green-300 px-4 py-3 rounded-lg">
        Settings saved successfully.
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

        <div class="flex justify-end">
          <button
            type="submit"
            :disabled="saving"
            class="px-6 py-2 bg-amber-500 hover:bg-amber-600 disabled:bg-gray-600 disabled:cursor-not-allowed text-black font-medium rounded-lg transition-colors"
          >
            {{ saving ? 'Saving...' : 'Save Settings' }}
          </button>
        </div>
      </form>

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
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

const loading = ref(true)
const saving = ref(false)
const saved = ref(false)
const error = ref('')

const clearing = ref(false)
const cacheMessage = ref('')
const cacheOk = ref(false)

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

async function loadSettings() {
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/api/v1/admin/sportsbot/telegram/settings')
    settings.bot_token_configured = res.data.bot_token_configured
    settings.bot_token = res.data.bot_token
    settings.webhook_enabled = res.data.webhook_enabled
    settings.webhook_url = res.data.webhook_url
    form.webhook_enabled = res.data.webhook_enabled
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Failed to load settings'
  } finally {
    loading.value = false
  }
}

async function saveSettings() {
  saving.value = true
  saved.value = false
  error.value = ''
  try {
    const payload: Record<string, any> = {
      webhook_enabled: form.webhook_enabled,
    }
    if (form.bot_token) {
      payload.bot_token = form.bot_token
    }
    const res = await api.post('/api/v1/admin/sportsbot/telegram/settings', payload)
    settings.bot_token_configured = res.data.bot_token_configured
    if (form.bot_token) {
      settings.bot_token = form.bot_token.substring(0, 6) + '...'
    }
    form.bot_token = ''
    saved.value = true
    setTimeout(() => { saved.value = false }, 3000)
  } catch (e: any) {
    error.value = e?.response?.data?.message || 'Failed to save settings'
  } finally {
    saving.value = false
  }
}

async function clearCache() {
  clearing.value = true
  cacheMessage.value = ''
  try {
    const res = await api.post('/api/v1/admin/cache/clear')
    cacheOk.value = true
    cacheMessage.value = res.data?.message || 'Cache cleared successfully'
  } catch (e: any) {
    cacheOk.value = false
    cacheMessage.value = e?.response?.data?.message || 'Failed to clear cache'
  } finally {
    clearing.value = false
  }
}

onMounted(loadSettings)
</script>
