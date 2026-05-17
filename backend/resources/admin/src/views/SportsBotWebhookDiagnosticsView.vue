<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Webhook Diagnostics</h1>
        <p class="text-slate-400 text-sm mt-1">Telegram webhook status and callback activity.</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button
          @click="loadDiagnostics"
          :disabled="loading"
          class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60"
        >
          {{ loading ? 'Refreshing...' : 'Refresh Status' }}
        </button>
        <button
          @click="setWebhook"
          :disabled="settingWebhook"
          class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60"
        >
          {{ settingWebhook ? 'Setting...' : 'Set Webhook' }}
        </button>
        <button
          @click="deleteWebhook"
          :disabled="deletingWebhook"
          class="px-4 py-2 rounded-xl bg-red-700 text-white hover:bg-red-600 disabled:opacity-60"
        >
          {{ deletingWebhook ? 'Deleting...' : 'Delete Webhook' }}
        </button>
        <button
          @click="testRoute"
          :disabled="testingRoute"
          class="px-4 py-2 rounded-xl bg-cyan-700 text-white hover:bg-cyan-600 disabled:opacity-60"
        >
          {{ testingRoute ? 'Testing...' : 'Test Route' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Webhook Enabled</p>
        <p class="text-3xl font-bold mt-2" :class="diagnostics.webhook_enabled ? 'text-emerald-400' : 'text-red-400'">
          {{ diagnostics.webhook_enabled ? 'Yes' : 'No' }}
        </p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4 md:col-span-2">
        <p class="text-slate-400 text-sm">Endpoint URL</p>
        <p class="text-sm text-white mt-2 break-all">{{ diagnostics.webhook_url || '-' }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Remote Health</p>
        <p class="text-2xl font-bold mt-2" :class="remoteHealthy ? 'text-emerald-400' : 'text-amber-400'">
          {{ remoteHealthy ? 'OK' : 'Check' }}
        </p>
      </div>
    </div>

    <div v-if="diagnostics.error" class="rounded-2xl bg-amber-500/10 border border-amber-500/30 p-4 text-sm text-amber-200">
      {{ diagnostics.error }}
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Last Webhook Received</p>
        <p class="text-white font-semibold mt-2">{{ formatDate(diagnostics.last_webhook_received) }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Last Callback Received</p>
        <p class="text-white font-semibold mt-2">{{ formatDate(diagnostics.last_callback_received) }}</p>
      </div>
      <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
        <p class="text-slate-400 text-sm">Last callback_data</p>
        <p class="text-white font-semibold mt-2 break-all">{{ diagnostics.last_callback_data || '-' }}</p>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Telegram Webhook Info</h2>
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
          <p class="text-slate-400">Max Connections</p>
          <p class="text-white font-semibold mt-1">{{ telegramInfo.max_connections ?? '-' }}</p>
        </div>
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-3">
          <p class="text-slate-400">Last Error</p>
          <p class="text-slate-300 mt-1">{{ telegramInfo.last_error_message || telegramInfo.error || 'None' }}</p>
        </div>
      </div>
    </div>

    <div class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <h2 class="text-lg font-semibold text-white mb-4">Recent Callback Logs</h2>
      <div v-if="recentCallbacks.length === 0" class="text-sm text-slate-400">No callbacks received yet.</div>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-slate-400 border-b border-slate-700">
            <tr>
              <th class="text-left py-2">Received</th>
              <th class="text-left py-2">callback_data</th>
              <th class="text-left py-2">Chat</th>
              <th class="text-left py-2">Message</th>
              <th class="text-left py-2">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in recentCallbacks" :key="row.id" class="border-b border-slate-800">
              <td class="py-2 text-slate-300">{{ formatDate(row.created_at) }}</td>
              <td class="py-2 text-slate-300">{{ row.callback_data || '-' }}</td>
              <td class="py-2 text-slate-300">{{ row.chat_id || '-' }}:{{ row.message_thread_id || '-' }}</td>
              <td class="py-2 text-slate-300">{{ row.telegram_message_id || '-' }}</td>
              <td class="py-2"><span :class="statusClass(row.status)" class="px-2 py-1 rounded text-xs font-medium">{{ row.status || '-' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()

const loading = ref(false)
const settingWebhook = ref(false)
const deletingWebhook = ref(false)
const testingRoute = ref(false)
const diagnostics = ref({})

const telegramInfo = computed(() => diagnostics.value.telegram_webhook_health || {})
const recentCallbacks = computed(() => diagnostics.value.recent_callbacks || [])
const remoteHealthy = computed(() => telegramInfo.value.healthy === true)

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}

function statusClass(status) {
  if (status === 'processed') return 'bg-emerald-500/20 text-emerald-400'
  if (status === 'received') return 'bg-cyan-500/20 text-cyan-300'
  if (status === 'failed') return 'bg-red-500/20 text-red-400'
  return 'bg-slate-700 text-slate-300'
}

async function loadDiagnostics() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/telegram/webhook/diagnostics')
    diagnostics.value = data || {}
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load webhook diagnostics')
  } finally {
    loading.value = false
  }
}

async function setWebhook() {
  settingWebhook.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/telegram/webhook/set')
    diagnostics.value = data.diagnostics || diagnostics.value
    toast.success('Telegram webhook set')
    await loadDiagnostics()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Failed to set webhook')
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
    toast.error(error?.response?.data?.error || 'Failed to delete webhook')
  } finally {
    deletingWebhook.value = false
  }
}

async function testRoute() {
  testingRoute.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/test-route', {
      route_key: 'FIXTURES_TODAY',
      send: true,
    })
    toast.success(`Route test sent (${(data.results || []).length} target(s))`)
    await loadDiagnostics()
  } catch (error) {
    toast.error(error?.response?.data?.error || 'Route test failed')
  } finally {
    testingRoute.value = false
  }
}

onMounted(loadDiagnostics)
</script>
