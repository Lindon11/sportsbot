<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Telegram Settings</h1>
        <p class="text-slate-400 text-sm mt-1">Bot token, webhook mode, and delivery checks.</p>
      </div>
      <button
        @click="loadAll"
        :disabled="loading || diagnosticsLoading"
        class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
      >
        {{ loading || diagnosticsLoading ? 'Refreshing...' : 'Refresh' }}
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Bot Token</p>
        <p class="text-3xl font-bold mt-2" :class="settings.bot_token_configured ? 'text-emerald-400' : 'text-red-400'">
          {{ settings.bot_token_configured ? 'Set' : 'Missing' }}
        </p>
        <p class="text-xs text-slate-500 mt-2">{{ settings.bot_token || 'Add a BotFather token below.' }}</p>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Webhook Mode</p>
        <p class="text-3xl font-bold mt-2" :class="settings.webhook_enabled ? 'text-emerald-400' : 'text-amber-400'">
          {{ settings.webhook_enabled ? 'On' : 'Off' }}
        </p>
        <p class="text-xs text-slate-500 mt-2">Controls whether Laravel accepts Telegram updates.</p>
      </div>

      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Remote Webhook</p>
        <p class="text-3xl font-bold mt-2" :class="remoteHealthy ? 'text-emerald-400' : 'text-amber-400'">
          {{ remoteStatus }}
        </p>
        <p class="text-xs text-slate-500 mt-2 truncate">{{ telegramInfo.url || 'No URL reported by Telegram.' }}</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-5">
      <h2 class="text-lg font-semibold text-white">Connection</h2>

      <div>
        <label class="block text-sm font-medium text-slate-300 mb-2">Telegram Bot Token</label>
        <input
          v-model="form.bot_token"
          type="password"
          autocomplete="off"
          class="w-full rounded-xl bg-slate-900 border border-slate-700 text-white p-3 text-sm"
          placeholder="1234567890:ABCdefGHIjklmNOPqrstUVwxyz"
        >
        <p class="text-xs text-slate-500 mt-2">Leave blank to keep the existing token.</p>
      </div>

      <label class="flex items-center justify-between gap-4 rounded-xl bg-slate-900 border border-slate-700 p-4">
        <span>
          <span class="block text-sm font-medium text-slate-200">Enable webhook mode</span>
          <span class="block text-xs text-slate-500 mt-1">Required for inline buttons, follows, and Telegram callbacks.</span>
        </span>
        <input v-model="form.webhook_enabled" type="checkbox" class="h-5 w-5 rounded border-slate-600 bg-slate-900 text-cyan-500">
      </label>

      <div class="rounded-xl bg-slate-900 border border-slate-700 p-4">
        <p class="text-slate-400 text-sm">Expected Webhook URL</p>
        <p class="text-white text-sm mt-2 break-all">{{ settings.webhook_url || diagnostics.webhook_url || '-' }}</p>
      </div>

      <div class="flex flex-wrap justify-end gap-3">
        <button
          @click="saveSettings"
          :disabled="saving"
          class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
        >
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
        <button
          @click="setWebhook"
          :disabled="settingWebhook || saving || (!settings.bot_token_configured && !form.bot_token)"
          class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
        >
          {{ settingWebhook ? 'Setting...' : 'Set Telegram Webhook' }}
        </button>
        <button
          @click="deleteWebhook"
          :disabled="deletingWebhook || !settings.bot_token_configured"
          class="px-4 py-2 rounded-xl bg-red-700 text-white hover:bg-red-600 disabled:opacity-60"
        >
          {{ deletingWebhook ? 'Deleting...' : 'Delete Telegram Webhook' }}
        </button>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <h2 class="text-lg font-semibold text-white">Webhook Diagnostics</h2>
        <router-link to="/sportsbot/webhook-diagnostics" class="text-sm text-cyan-300 hover:text-cyan-200">
          Open full diagnostics
        </router-link>
      </div>

      <div v-if="diagnostics.error" class="rounded-xl bg-amber-500/10 border border-amber-500/30 p-4 text-sm text-amber-200">
        {{ diagnostics.error }}
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 text-sm">
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Remote URL</p>
          <p class="text-white mt-1 break-all">{{ telegramInfo.url || '-' }}</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Pending Updates</p>
          <p class="text-white font-semibold mt-1">{{ telegramInfo.pending_update_count ?? '-' }}</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Last Webhook</p>
          <p class="text-white font-semibold mt-1">{{ formatDate(diagnostics.last_webhook_received) }}</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Last Error</p>
          <p class="text-slate-300 mt-1">{{ telegramInfo.last_error_message || telegramInfo.error || 'None' }}</p>
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
const diagnosticsLoading = ref(false)
const saving = ref(false)
const settingWebhook = ref(false)
const deletingWebhook = ref(false)

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

const diagnostics = ref({})

const telegramInfo = computed(() => diagnostics.value.telegram_webhook_health || {})
const remoteHealthy = computed(() => telegramInfo.value.healthy === true)
const remoteStatus = computed(() => {
  if (telegramInfo.value.error) return 'Error'
  if (!telegramInfo.value.url) return 'Unset'
  return remoteHealthy.value ? 'OK' : 'Check'
})

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

async function loadSettings() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/telegram/settings')
    settings.bot_token_configured = Boolean(data.bot_token_configured)
    settings.bot_token = data.bot_token || ''
    settings.webhook_enabled = Boolean(data.webhook_enabled)
    settings.webhook_url = data.webhook_url || ''
    form.webhook_enabled = settings.webhook_enabled
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load Telegram settings')
  } finally {
    loading.value = false
  }
}

async function loadDiagnostics() {
  diagnosticsLoading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/telegram/webhook/diagnostics')
    diagnostics.value = data || {}
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load webhook diagnostics')
  } finally {
    diagnosticsLoading.value = false
  }
}

async function loadAll() {
  await loadSettings()
  await loadDiagnostics()
}

async function saveSettings(showToast = true) {
  saving.value = true
  try {
    const payload = {
      webhook_enabled: form.webhook_enabled,
    }

    if (form.bot_token.trim()) {
      payload.bot_token = form.bot_token.trim()
    }

    const { data } = await api.post('/admin/sportsbot/telegram/settings', payload)
    settings.bot_token_configured = Boolean(data.bot_token_configured)
    settings.webhook_enabled = form.webhook_enabled
    if (form.bot_token.trim()) {
      settings.bot_token = `${form.bot_token.trim().slice(0, 6)}...`
    }
    form.bot_token = ''

    if (showToast) {
      toast.success('Telegram settings saved')
    }

    await loadDiagnostics()
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to save Telegram settings')
    throw error
  } finally {
    saving.value = false
  }
}

async function setWebhook() {
  settingWebhook.value = true
  try {
    if (!form.webhook_enabled) {
      form.webhook_enabled = true
      await saveSettings(false)
    } else if (form.bot_token.trim()) {
      await saveSettings(false)
    }

    const { data } = await api.post('/admin/sportsbot/telegram/webhook/set')
    diagnostics.value = data.diagnostics || diagnostics.value
    toast.success('Telegram webhook set')
    await loadAll()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to set Telegram webhook')
  } finally {
    settingWebhook.value = false
  }
}

async function deleteWebhook() {
  deletingWebhook.value = true
  try {
    const { data } = await api.delete('/admin/sportsbot/telegram/webhook')
    diagnostics.value = data.diagnostics || diagnostics.value
    toast.success('Telegram webhook deleted')
    await loadDiagnostics()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to delete Telegram webhook')
  } finally {
    deletingWebhook.value = false
  }
}

onMounted(loadAll)
</script>
