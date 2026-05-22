<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">Uptime Monitor</h1>
        <p class="text-slate-400 text-sm mt-1">Monitor websites and get alerts when they go down.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button @click="showBotAdd = true" class="px-4 py-2 rounded-xl border border-sky-500/40 bg-sky-500/10 text-sky-100 hover:bg-sky-500/20 font-semibold">Add Bot</button>
        <button @click="showAdd = true" class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 font-semibold">Add Site</button>
      </div>
    </div>

    <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5">
      <div class="flex items-center justify-between gap-3 flex-wrap mb-4">
        <h2 class="text-lg font-semibold text-white">Monitor Bots</h2>
        <span class="text-xs text-slate-400">{{ bots.length }} configured profiles</span>
      </div>
      <div v-if="bots.length === 0" class="rounded-xl border border-slate-700 bg-slate-950/50 p-4 text-sm text-slate-400">
        Sites use the default Monitor Bot until a bot profile is assigned.
      </div>
      <div v-else class="grid grid-cols-1 gap-3 xl:grid-cols-2">
        <div v-for="bot in bots" :key="bot.id" class="rounded-xl border border-slate-700 bg-slate-950/55 p-4">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="truncate font-semibold text-white">{{ bot.name }}</h3>
                <span class="rounded px-2 py-1 text-xs font-medium" :class="bot.enabled ? 'bg-emerald-500/15 text-emerald-200' : 'bg-slate-700 text-slate-300'">{{ bot.enabled ? 'Enabled' : 'Disabled' }}</span>
              </div>
              <p class="mt-1 truncate text-xs text-slate-400">{{ bot.owner_label || 'Owner not labelled' }}</p>
            </div>
            <div class="flex gap-2">
              <button @click="openBotEdit(bot)" class="rounded bg-slate-700/60 px-2 py-1 text-xs text-slate-200 hover:bg-slate-600">Edit</button>
              <button @click="deleteBot(bot)" class="rounded bg-red-500/10 px-2 py-1 text-xs text-red-300 hover:bg-red-500/20">Delete</button>
            </div>
          </div>
          <div class="mt-3 grid grid-cols-1 gap-2 text-sm sm:grid-cols-3">
            <div class="rounded-lg bg-slate-900/80 px-3 py-2">
              <p class="text-xs text-slate-500">Chat</p>
              <p class="truncate text-slate-100">{{ bot.telegram_chat_id }}</p>
            </div>
            <div class="rounded-lg bg-slate-900/80 px-3 py-2">
              <p class="text-xs text-slate-500">Topic</p>
              <p class="text-slate-100">{{ bot.telegram_message_thread_id || '-' }}</p>
            </div>
            <div class="rounded-lg bg-slate-900/80 px-3 py-2">
              <p class="text-xs text-slate-500">Sites</p>
              <p class="text-slate-100">{{ bot.sites_count || 0 }}</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <div v-if="sites.length === 0" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-8 text-center">
      <p class="text-slate-400">No sites monitored yet.</p>
    </div>

    <div v-for="site in sites" :key="site.id" class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-4">
      <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0 flex-1">
          <span :class="site.status === 'online' ? 'bg-emerald-500' : site.status === 'offline' ? 'bg-red-500' : 'bg-slate-500'" class="w-3 h-3 rounded-full flex-shrink-0"></span>
          <div class="min-w-0 flex-1">
            <div class="flex items-center gap-2 flex-wrap">
              <h3 class="text-white font-semibold truncate">{{ site.name }}</h3>
              <span :class="site.status === 'online' ? 'text-emerald-400' : site.status === 'offline' ? 'text-red-400' : 'text-slate-400'" class="text-sm font-medium capitalize">{{ site.status }}</span>
              <span class="rounded bg-slate-700/60 px-2 py-0.5 text-xs text-slate-200">{{ site.monitor_bot_name || 'Default Monitor Bot' }}</span>
              <span v-if="site.last_offline_at" class="text-slate-500 text-xs">Last down: {{ site.last_offline_at }}</span>
            </div>
            <p class="text-slate-400 text-xs truncate">{{ site.url }}</p>
          </div>
        </div>
        <div class="flex items-center gap-4 text-sm flex-shrink-0">
          <div class="text-right">
            <p class="text-white font-bold">{{ site.uptime_percentage }}%</p>
            <p class="text-slate-400 text-xs">{{ site.total_checks || 0 }} checks</p>
          </div>
          <div class="text-right min-w-[80px]">
            <p class="text-white text-xs">{{ site.last_checked_at || 'Never' }}</p>
            <p v-if="site.consecutive_failures > 0" class="text-amber-400 text-xs">{{ site.consecutive_failures }}/{{ site.failure_threshold }} fails</p>
          </div>
          <button @click="openEdit(site)" class="text-slate-400 hover:text-white text-xs px-2 py-1 rounded bg-slate-700/50">Edit</button>
          <button @click="confirmDelete(site)" class="text-red-400 hover:text-red-300 text-xs px-2 py-1 rounded bg-red-400/10">Delete</button>
        </div>
      </div>

      <div v-if="site.daily_status && site.daily_status.length" class="mt-4">
        <div class="flex gap-[3px] h-10 items-end">
          <div v-for="d in site.daily_status" :key="d.day"
            :title="(d.label || '') + ': ' + d.status"
            :class="d.status === 'up' ? 'bg-emerald-500' : d.status === 'degraded' ? 'bg-amber-500' : d.status === 'down' ? 'bg-red-500' : 'bg-slate-700'"
            class="flex-1 rounded-t cursor-pointer hover:opacity-80 transition-opacity"
            :style="{ height: d.status === 'up' ? '100%' : d.status === 'degraded' ? '60%' : d.status === 'down' ? '30%' : '4px' }">
          </div>
        </div>
        <div class="flex justify-between text-xs mt-2">
          <div class="flex gap-3">
            <span v-if="site.uptime_percentage !== undefined" class="text-white font-semibold">{{ site.uptime_percentage }}% uptime</span>
            <span v-if="site.consecutive_failures > 0" class="text-amber-400">{{ site.consecutive_failures }} consecutive failures</span>
          </div>
          <div class="flex gap-2 text-slate-500">
            <span><span class="inline-block w-2 h-2 rounded bg-emerald-500 mr-1 align-middle"></span>Up</span>
            <span><span class="inline-block w-2 h-2 rounded bg-amber-500 mr-1 align-middle"></span>Deg</span>
            <span><span class="inline-block w-2 h-2 rounded bg-red-500 mr-1 align-middle"></span>Down</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="showEdit" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50" @click.self="showEdit = false">
      <div class="bg-slate-800 rounded-2xl p-6 w-full max-w-md border border-slate-700">
        <h2 class="text-lg font-semibold text-white mb-4">Edit Site</h2>
        <div class="space-y-3">
          <label class="block"><span class="text-slate-400 text-xs block mb-1">Name</span><input v-model="editForm.name" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2"></label>
          <label class="block"><span class="text-slate-400 text-xs block mb-1">URL</span><input v-model="editForm.url" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2"></label>
          <label class="block"><span class="text-slate-400 text-xs block mb-1">Monitor Bot</span><select v-model="editForm.monitor_bot_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2"><option value="">Default Monitor Bot</option><option v-for="bot in bots" :key="bot.id" :value="bot.id">{{ bot.name }}</option></select></label>
          <div class="grid grid-cols-3 gap-3">
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Interval (s)</span><input v-model.number="editForm.check_interval_seconds" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" min="60"></label>
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Timeout (s)</span><input v-model.number="editForm.timeout_seconds" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" min="3"></label>
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Fail threshold</span><input v-model.number="editForm.failure_threshold" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" min="1"></label>
          </div>
        </div>
        <div class="flex gap-2 mt-5">
          <button @click="showEdit = false" class="flex-1 px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 font-semibold">Cancel</button>
          <button @click="saveEdit" :disabled="saving" class="flex-1 px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60 font-semibold">{{ saving ? 'Saving...' : 'Save' }}</button>
        </div>
      </div>
    </div>

    <div v-if="showAdd" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50" @click.self="showAdd = false">
      <div class="bg-slate-800 rounded-2xl p-6 w-full max-w-md border border-slate-700">
        <h2 class="text-lg font-semibold text-white mb-4">Add Site</h2>
        <div class="space-y-3">
          <label class="block"><span class="text-slate-400 text-xs block mb-1">Name</span><input v-model="form.name" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="My Website"></label>
          <label class="block"><span class="text-slate-400 text-xs block mb-1">URL</span><input v-model="form.url" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="https://example.com"></label>
          <label class="block"><span class="text-slate-400 text-xs block mb-1">Monitor Bot</span><select v-model="form.monitor_bot_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2"><option value="">Default Monitor Bot</option><option v-for="bot in bots" :key="bot.id" :value="bot.id">{{ bot.name }}</option></select></label>
          <div class="grid grid-cols-3 gap-3">
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Interval (s)</span><input v-model.number="form.check_interval_seconds" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" min="60"></label>
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Timeout (s)</span><input v-model.number="form.timeout_seconds" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" min="3"></label>
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Fail threshold</span><input v-model.number="form.failure_threshold" type="number" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" min="1"></label>
          </div>
        </div>
        <div class="flex gap-2 mt-5">
          <button @click="showAdd = false" class="flex-1 px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 font-semibold">Cancel</button>
          <button @click="addSite" :disabled="saving" class="flex-1 px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60 font-semibold">{{ saving ? 'Adding...' : 'Add' }}</button>
        </div>
      </div>
    </div>

    <div v-if="showBotAdd || showBotEdit" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50 p-4" @click.self="closeBotModal">
      <div class="bg-slate-800 rounded-2xl p-6 w-full max-w-xl border border-slate-700">
        <h2 class="text-lg font-semibold text-white mb-4">{{ showBotEdit ? 'Edit Monitor Bot' : 'Add Monitor Bot' }}</h2>
        <div class="space-y-3">
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Bot Name</span><input v-model="botForm.name" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="Owner Alerts"></label>
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Owner Label</span><input v-model="botForm.owner_label" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="Customer name"></label>
          </div>
          <label class="block">
            <span class="text-slate-400 text-xs block mb-1">Telegram Bot Token</span>
            <input v-model="botForm.telegram_token" type="password" autocomplete="off" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" :placeholder="showBotEdit ? 'Leave blank to keep current token' : '123456:bot-token'">
          </label>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Telegram Chat ID</span><input v-model="botForm.telegram_chat_id" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="-1001234567890"></label>
            <label class="block"><span class="text-slate-400 text-xs block mb-1">Topic ID</span><input v-model.number="botForm.telegram_message_thread_id" type="number" min="1" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="886"></label>
          </div>
          <label class="block"><span class="text-slate-400 text-xs block mb-1">Extra Targets</span><textarea v-model="botForm.telegram_extra_targets" rows="2" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="-1001234567890:886"></textarea></label>
          <label class="inline-flex items-center gap-2 text-sm text-slate-200"><input v-model="botForm.enabled" type="checkbox" class="rounded border-slate-600 bg-slate-950 text-emerald-500">Enabled</label>
        </div>
        <div class="flex gap-2 mt-5">
          <button @click="closeBotModal" class="flex-1 px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 font-semibold">Cancel</button>
          <button @click="saveBot" :disabled="savingBot" class="flex-1 px-4 py-2 rounded-xl bg-sky-700 text-white hover:bg-sky-600 disabled:opacity-60 font-semibold">{{ savingBot ? 'Saving...' : 'Save Bot' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const sites = ref([])
const bots = ref([])
const showAdd = ref(false)
const showEdit = ref(false)
const showBotAdd = ref(false)
const showBotEdit = ref(false)
const editingId = ref(null)
const editingBotId = ref(null)
const saving = ref(false)
const savingBot = ref(false)

const form = reactive({
  name: '', url: '',
  monitor_bot_id: '',
  check_interval_seconds: 300, timeout_seconds: 10, failure_threshold: 3,
})

const editForm = reactive({
  name: '', url: '',
  monitor_bot_id: '',
  check_interval_seconds: 300, timeout_seconds: 10, failure_threshold: 3,
})

const botForm = reactive({
  name: '',
  owner_label: '',
  telegram_token: '',
  telegram_chat_id: '',
  telegram_message_thread_id: '',
  telegram_extra_targets: '',
  enabled: true,
})

function openEdit(site) {
  editingId.value = site.id
  editForm.name = site.name
  editForm.url = site.url
  editForm.monitor_bot_id = site.monitor_bot_id || ''
  editForm.check_interval_seconds = site.check_interval_seconds
  editForm.timeout_seconds = site.timeout_seconds
  editForm.failure_threshold = site.failure_threshold
  showEdit.value = true
}

async function load() {
  try {
    const { data } = await api.get('/admin/sportsbot/uptime')
    sites.value = data.sites || []
    bots.value = data.monitor_bots || []
  } catch { toast.error('Failed to load') }
}

async function addSite() {
  saving.value = true
  try {
    await api.post('/admin/sportsbot/uptime', form)
    toast.success('Site added')
    showAdd.value = false
    form.name = ''; form.url = ''
    await load()
  } catch (e) { toast.error(e?.response?.data?.error || 'Failed') }
  finally { saving.value = false }
}

function openBotEdit(bot) {
  editingBotId.value = bot.id
  botForm.name = bot.name || ''
  botForm.owner_label = bot.owner_label || ''
  botForm.telegram_token = ''
  botForm.telegram_chat_id = bot.telegram_chat_id || ''
  botForm.telegram_message_thread_id = bot.telegram_message_thread_id || ''
  botForm.telegram_extra_targets = bot.telegram_extra_targets || ''
  botForm.enabled = !!bot.enabled
  showBotEdit.value = true
}

function closeBotModal() {
  showBotAdd.value = false
  showBotEdit.value = false
  editingBotId.value = null
  botForm.name = ''
  botForm.owner_label = ''
  botForm.telegram_token = ''
  botForm.telegram_chat_id = ''
  botForm.telegram_message_thread_id = ''
  botForm.telegram_extra_targets = ''
  botForm.enabled = true
}

async function saveBot() {
  savingBot.value = true
  try {
    if (showBotEdit.value) {
      await api.put(`/admin/sportsbot/uptime/bots/${editingBotId.value}`, botForm)
      toast.success('Monitor bot updated')
    } else {
      await api.post('/admin/sportsbot/uptime/bots', botForm)
      toast.success('Monitor bot added')
    }
    closeBotModal()
    await load()
  } catch (e) { toast.error(e?.response?.data?.message || e?.response?.data?.error || 'Failed to save bot') }
  finally { savingBot.value = false }
}

async function deleteBot(bot) {
  if (!confirm(`Delete monitor bot "${bot.name}"? Assigned sites will use the default Monitor Bot.`)) return
  try {
    await api.delete(`/admin/sportsbot/uptime/bots/${bot.id}`)
    toast.success('Monitor bot deleted')
    await load()
  } catch { toast.error('Failed to delete monitor bot') }
}

async function saveEdit() {
  saving.value = true
  try {
    await api.put(`/admin/sportsbot/uptime/${editingId.value}`, editForm)
    toast.success('Site updated')
    showEdit.value = false
    await load()
  } catch (e) { toast.error(e?.response?.data?.error || 'Failed') }
  finally { saving.value = false }
}

async function confirmDelete(site) {
  if (!confirm(`Delete "${site.name}"?`)) return
  try {
    await api.delete(`/admin/sportsbot/uptime/${site.id}`)
    toast.success('Deleted')
    await load()
  } catch { toast.error('Failed to delete') }
}

onMounted(load)
</script>
