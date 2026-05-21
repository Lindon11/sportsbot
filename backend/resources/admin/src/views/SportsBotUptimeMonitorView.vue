<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">Uptime Monitor</h1>
        <p class="text-slate-400 text-sm mt-1">Monitor websites and get alerts when they go down.</p>
      </div>
      <button @click="showAdd = true" class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 font-semibold">+ Add Site</button>
    </div>

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
          <button @click="confirmDelete(site)" class="text-red-400 hover:text-red-300 text-xs px-2 py-1 rounded bg-red-400/10">Delete</button>
        </div>
      </div>

      <div v-if="site.daily_status && site.daily_status.length" class="mt-3">
        <div class="flex gap-[2px] h-7 items-end">
          <div v-for="d in site.daily_status" :key="d.day"
            :title="(d.label || 'No data') + ': ' + d.status"
            :class="d.status === 'up' ? 'bg-emerald-500/80' : d.status === 'degraded' ? 'bg-amber-500/80' : d.status === 'down' ? 'bg-red-500/80' : 'bg-slate-700/50'"
            class="flex-1 rounded-sm cursor-pointer hover:opacity-80 transition-opacity"
            :style="{ height: d.status === 'up' ? '100%' : d.status === 'degraded' ? '60%' : d.status === 'down' ? '30%' : '4px' }">
          </div>
        </div>
        <div class="flex justify-between text-xs text-slate-500 mt-1">
          <span>30 days ago</span>
          <div class="flex gap-3">
            <span><span class="inline-block w-2 h-2 rounded bg-emerald-500/80 mr-1"></span>Up</span>
            <span><span class="inline-block w-2 h-2 rounded bg-amber-500/80 mr-1"></span>Degraded</span>
            <span><span class="inline-block w-2 h-2 rounded bg-red-500/80 mr-1"></span>Down</span>
            <span><span class="inline-block w-2 h-2 rounded bg-slate-700/50 mr-1"></span>No data</span>
          </div>
          <span>Today</span>
        </div>
      </div>
    </div>

    <div v-if="showAdd" class="fixed inset-0 bg-black/60 flex items-center justify-center z-50" @click.self="showAdd = false">
      <div class="bg-slate-800 rounded-2xl p-6 w-full max-w-md border border-slate-700">
        <h2 class="text-lg font-semibold text-white mb-4">Add Site</h2>
        <div class="space-y-3">
          <label class="block"><span class="text-slate-400 text-xs block mb-1">Name</span><input v-model="form.name" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="My Website"></label>
          <label class="block"><span class="text-slate-400 text-xs block mb-1">URL</span><input v-model="form.url" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2" placeholder="https://example.com"></label>
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
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const sites = ref([])
const showAdd = ref(false)
const saving = ref(false)

const form = reactive({
  name: '', url: '',
  check_interval_seconds: 300, timeout_seconds: 10, failure_threshold: 3,
})

async function load() {
  try {
    const { data } = await api.get('/admin/sportsbot/uptime')
    sites.value = data.sites || []
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
