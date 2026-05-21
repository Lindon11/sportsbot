<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-white">Monitor Bot Settings</h1>
        <p class="text-slate-400 text-sm mt-1">Configure where uptime alerts are sent.</p>
      </div>
      <button @click="save" :disabled="saving" class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60 font-semibold">{{ saving ? 'Saving...' : 'Save' }}</button>
    </div>

    <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Telegram Target</h2>
      <label class="block">
        <span class="text-slate-400 text-xs block mb-1">Chat ID</span>
        <input v-model="form.chat_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="-1001234567890">
      </label>
      <label class="block">
        <span class="text-slate-400 text-xs block mb-1">Message Thread ID (topic)</span>
        <input v-model="form.message_thread_id" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="886">
      </label>
    </section>

    <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Extra Targets</h2>
      <p class="text-xs text-slate-400">Additional chat IDs to send alerts to (one per line, format: chat_id:thread_id).</p>
      <textarea v-model="form.extra_targets" rows="4" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="-1001234567890:886"></textarea>
    </section>

    <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
      <h2 class="text-lg font-semibold text-white">Status</h2>
      <div class="text-sm text-slate-300">
        <p v-if="status.configured" class="text-emerald-400">✓ Bot configured and ready</p>
        <p v-else class="text-amber-400">⚠ Bot not fully configured — add a token and chat ID</p>
        <p class="mt-2">Token: <span :class="status.has_token ? 'text-emerald-400' : 'text-red-400'">{{ status.has_token ? 'Configured' : 'Missing' }}</span></p>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const saving = ref(false)
const status = ref({})

const form = reactive({
  chat_id: '',
  message_thread_id: '',
  extra_targets: '',
})

async function load() {
  try {
    const { data } = await api.get('/admin/sportsbot/monitor-settings')
    form.chat_id = data.chat_id || ''
    form.message_thread_id = data.message_thread_id || ''
    form.extra_targets = data.extra_targets || ''
    status.value = data.status || {}
  } catch { toast.error('Failed to load') }
}

async function save() {
  saving.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/monitor-settings', form)
    status.value = data.status || {}
    toast.success('Saved')
  } catch (e) { toast.error(e?.response?.data?.error || 'Failed') }
  finally { saving.value = false }
}

onMounted(load)
</script>
