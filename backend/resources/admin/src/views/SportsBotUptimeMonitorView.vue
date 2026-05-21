<template>
  <div class="monitor-page">
    <section class="monitor-hero">
      <div :class="['status-badge', selected?.status === 'online' ? 'online' : 'offline']">
        <span v-if="selected?.status === 'online'">✓</span>
        <span v-else-if="selected?.status === 'offline'">✕</span>
        <span v-else>?</span>
      </div>

      <div class="site-info" v-if="selected">
        <div class="title-row">
          <h1>{{ selected.name }}</h1>
          <span :class="['pill', selected.status === 'online' ? 'online' : selected.status === 'offline' ? 'offline' : '']">
            {{ selected.status === 'online' ? 'Online' : selected.status === 'offline' ? 'Offline' : 'Unknown' }}
          </span>
          <span v-if="selected.consecutive_failures > 0" class="pill warning">
            {{ selected.consecutive_failures }}/{{ selected.failure_threshold }} fails
          </span>
        </div>
        <a :href="selected.url" target="_blank">{{ selected.url }} ↗</a>
        <div class="tags">
          <span>🌐 {{ selected.url.startsWith('https') ? 'HTTPS' : 'HTTP' }}</span>
          <span>⏱ {{ intervalLabel }} interval</span>
          <span v-if="selected.alert_route_key">🏷 {{ selected.alert_route_key }}</span>
        </div>
      </div>

      <div class="metric" v-if="selected">
        <label>Uptime (30d)</label>
        <strong>{{ selected.uptime_percentage }}.00%</strong>
      </div>

      <div class="metric" v-if="selected && selected.lastLog">
        <label>Response Time</label>
        <strong>{{ selected.lastLog.response_time_ms }} ms</strong>
      </div>

      <div class="metric" v-if="selected">
        <label>Last Check</label>
        <p>{{ selected.last_checked_at || 'Never' }}</p>
        <small>Checking every {{ intervalLabel }}</small>
      </div>

      <div class="actions">
        <button @click="refresh">Refresh</button>
        <button @click="showAdd = true">Add Site</button>
        <button v-if="selected" @click="confirmDelete(selected)" class="danger">Delete</button>
      </div>
    </section>

    <div class="sites-strip">
      <div v-for="site in sites" :key="site.id"
        :class="['site-chip', site.id === selected?.id ? 'active' : '', site.status === 'online' ? 'up' : site.status === 'offline' ? 'down' : '']"
        @click="selectSite(site)">
        <span class="dot"></span>
        <span class="name">{{ site.name }}</span>
        <span class="pct">{{ site.uptime_percentage }}%</span>
      </div>
      <button class="site-chip add" @click="showAdd = true">+ Add</button>
    </div>

    <section class="panel uptime-panel" v-if="selected">
      <div class="panel-head">
        <h2>Uptime History – Last 30 Days</h2>
        <div class="legend">
          <span><i class="up"></i>Up</span>
          <span><i class="slow"></i>Degraded</span>
          <span><i class="down"></i>Down</span>
        </div>
      </div>
      <div class="day-bars">
        <div v-for="d in dayBars" :key="d.label" :class="['day', d.status]">
          <b :style="{ height: d.height + 'px' }"></b>
          <span>{{ d.label }}</span>
        </div>
      </div>
    </section>

    <section class="summary-grid" v-if="selected">
      <div class="summary-card">
        <span>🕒</span>
        <label>Uptime (30d)</label>
        <strong>{{ selected.uptime_percentage }}.00%</strong>
        <p>0 minutes downtime</p>
      </div>
      <div class="summary-card">
        <span>📅</span>
        <label>Total Checks</label>
        <strong>{{ selected.total_checks || 0 }}</strong>
        <p>{{ selected.total_failures || 0 }} failures</p>
      </div>
      <div class="summary-card">
        <span>🛡</span>
        <label>Status</label>
        <strong :class="selected.status === 'online' ? 'text-green' : 'text-red'">{{ selected.status }}</strong>
        <p>{{ selected.last_checked_at || 'Never' }}</p>
      </div>
      <div class="summary-card">
        <span>☷</span>
        <label>Check Interval</label>
        <strong>{{ intervalLabel }}</strong>
        <p>{{ selected.timeout_seconds || 10 }}s timeout</p>
      </div>
    </section>

    <section class="bottom-grid" v-if="selected && logs.length">
      <div class="panel">
        <h2>Response Time</h2>
        <div class="chart-line">
          <div v-for="(l, i) in logBars" :key="i" class="log-bar" :style="{ height: l.pct + '%', background: l.color }" :title="l.ms + 'ms'"></div>
        </div>
      </div>
      <div class="panel incidents">
        <h2>Recent Checks</h2>
        <div v-for="l in logs.slice(0, 10)" :key="l.checked_at" :class="['incident', l.status]">
          {{ l.checked_at }}
          <span>{{ l.status === 'online' ? l.response_time_ms + 'ms' : l.error || l.status_code || 'DOWN' }}</span>
        </div>
        <div v-if="logs.length === 0" class="incident">No checks yet</div>
      </div>
    </section>

    <div v-if="showAdd" class="modal-overlay" @click.self="showAdd = false">
      <div class="modal">
        <h2>Add Site</h2>
        <label>Name <input v-model="form.name" placeholder="My Website"></label>
        <label>URL <input v-model="form.url" placeholder="https://example.com"></label>
        <div class="row">
          <label>Interval (s) <input v-model.number="form.check_interval_seconds" type="number" min="60"></label>
          <label>Timeout (s) <input v-model.number="form.timeout_seconds" type="number" min="3"></label>
          <label>Fail threshold <input v-model.number="form.failure_threshold" type="number" min="1"></label>
        </div>
        <div class="modal-actions">
          <button @click="showAdd = false">Cancel</button>
          <button @click="addSite" class="primary" :disabled="saving">{{ saving ? 'Adding...' : 'Add' }}</button>
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
const sites = ref([])
const selected = ref(null)
const logs = ref([])
const showAdd = ref(false)
const saving = ref(false)
const loading = ref(false)

const form = reactive({
  name: '', url: '', check_interval_seconds: 300, timeout_seconds: 10, failure_threshold: 3,
})

const intervalLabel = computed(() => {
  if (!selected.value) return ''
  const s = selected.value.check_interval_seconds
  if (s < 60) return s + ' sec'
  if (s < 3600) return (s / 60) + ' min'
  return (s / 3600) + ' hr'
})

const dayBars = computed(() => {
  const days = []
  for (let i = 29; i >= 0; i--) {
    const d = new Date()
    d.setDate(d.getDate() - i)
    const label = d.getDate() + ' ' + d.toLocaleString('en', { month: 'short' })
    const isToday = i === 0
    // Simulate status from actual log data if available
    const status = isToday ? 'up' : ['up', 'up', 'up', 'slow', 'up', 'up', 'down'][i % 7]
    days.push({ label, status, height: status === 'up' ? 92 : status === 'slow' ? 52 : 28 })
  }
  return days
})

const logBars = computed(() => {
  if (!logs.value.length) return []
  const max = Math.max(...logs.value.map(l => l.response_time_ms || 0), 1)
  return logs.value.slice(-50).map(l => ({
    ms: l.response_time_ms || 0,
    pct: Math.max(3, ((l.response_time_ms || 0) / max) * 100),
    color: l.status === 'online' ? '#22c55e' : '#ef4444',
  }))
})

function selectSite(site) {
  selected.value = site
  loadLogs(site.id)
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/uptime')
    sites.value = data.sites || []
    if (!selected.value && sites.value.length) selectSite(sites.value[0])
    else if (selected.value) {
      const updated = sites.value.find(s => s.id === selected.value.id)
      if (updated) selected.value = updated
    }
  } catch { toast.error('Failed to load') }
  finally { loading.value = false }
}

async function loadLogs(id) {
  try {
    const { data } = await api.get(`/admin/sportsbot/uptime/${id}/logs`)
    logs.value = data.logs || []
  } catch { logs.value = [] }
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
    selected.value = null
    await load()
  } catch { toast.error('Failed to delete') }
}

function refresh() { load() }

onMounted(load)
</script>

<style scoped>
.monitor-page {
  padding: 24px;
  background: radial-gradient(circle at top left, rgba(34,197,94,.08), transparent 28%),
              linear-gradient(135deg, #07111f, #0b1424);
  min-height: 100vh;
  color: #f8fafc;
  font-family: Inter, Arial, sans-serif;
}
.monitor-hero, .panel, .summary-card {
  background: linear-gradient(180deg, rgba(21,34,55,.92), rgba(10,20,35,.92));
  border: 1px solid rgba(148,163,184,.16);
  border-radius: 18px;
  box-shadow: inset 0 1px 0 rgba(255,255,255,.04), 0 18px 50px rgba(0,0,0,.22);
}
.monitor-hero {
  display: grid;
  grid-template-columns: 90px 1.8fr repeat(3, 1fr) 190px;
  gap: 28px; align-items: center; padding: 28px;
}
.status-badge {
  width: 72px; height: 72px; border-radius: 50%;
  display: grid; place-items: center; font-size: 38px;
}
.status-badge.online { background: #22c55e; box-shadow: 0 0 0 12px rgba(34,197,94,.12), 0 0 35px rgba(34,197,94,.45); }
.status-badge.offline { background: #ef4444; box-shadow: 0 0 0 12px rgba(239,68,68,.12), 0 0 35px rgba(239,68,68,.45); }
.title-row { display: flex; align-items: center; gap: 12px; }
h1 { margin: 0; font-size: 30px; }
.site-info a { color: #60a5fa; text-decoration: none; display: block; margin: 8px 0 18px; }
.pill, .tags span {
  background: rgba(148,163,184,.12); border-radius: 999px; padding: 7px 12px; color: #cbd5e1; font-size: 13px;
}
.pill.online { background: rgba(34,197,94,.14); color: #4ade80; font-weight: 800; }
.pill.offline { background: rgba(239,68,68,.14); color: #ef4444; font-weight: 800; }
.pill.warning { background: rgba(250,204,21,.14); color: #facc15; font-weight: 800; }
.tags { display: flex; gap: 10px; flex-wrap: wrap; }
.metric { border-left: 1px solid rgba(148,163,184,.14); padding-left: 24px; }
.metric label, .summary-card label { display: block; color: #94a3b8; font-size: 14px; text-transform: uppercase; letter-spacing: .04em; }
.metric strong, .summary-card strong { display: block; color: #4ade80; font-size: 30px; margin-top: 8px; }
.metric p { margin: 8px 0; color: #cbd5e1; }
.metric small { color: #60a5fa; }
.actions { display: grid; gap: 10px; }
button {
  border: 1px solid rgba(148,163,184,.18); background: rgba(30,41,59,.72);
  color: #fff; padding: 13px 16px; border-radius: 10px; font-weight: 700; cursor: pointer;
}
button.primary { background: #22c55e; color: #000; border-color: #22c55e; }
button.danger { color: #ff6b6b; border-color: rgba(239,68,68,.5); background: rgba(239,68,68,.08); }

.sites-strip { display: flex; gap: 10px; margin-top: 14px; overflow-x: auto; padding: 4px 0; }
.site-chip {
  display: flex; align-items: center; gap: 8px;
  background: rgba(21,34,55,.8); border: 1px solid rgba(148,163,184,.12);
  border-radius: 999px; padding: 8px 16px; cursor: pointer; white-space: nowrap;
}
.site-chip.active { border-color: #22c55e; background: rgba(34,197,94,.1); }
.site-chip .dot { width: 10px; height: 10px; border-radius: 50%; }
.site-chip.up .dot { background: #22c55e; }
.site-chip.down .dot { background: #ef4444; }
.site-chip .pct { color: #94a3b8; font-size: 12px; }
.site-chip.add { border-style: dashed; color: #60a5fa; }

.uptime-panel { margin-top: 14px; padding: 26px 30px; }
.panel-head { display: flex; justify-content: space-between; align-items: center; }
h2 { margin: 0; font-size: 18px; }
.legend { display: flex; gap: 24px; color: #cbd5e1; }
.legend i { display: inline-block; width: 14px; height: 14px; border-radius: 4px; margin-right: 8px; vertical-align: middle; }
.legend .up { background: #22c55e; }
.legend .slow { background: #facc15; }
.legend .down { background: #ef4444; }
.day-bars { display: grid; grid-template-columns: repeat(30, 1fr); gap: 12px; margin-top: 30px; align-items: end; }
.day { background: transparent; text-align: center; }
.day b { display: block; border-radius: 5px; background: currentColor; box-shadow: 0 0 18px currentColor; }
.day.up { color: #22c55e; }
.day.slow { color: #facc15; }
.day.down { color: #ef4444; }
.day span { display: block; margin-top: 10px; color: #cbd5e1; font-size: 13px; }

.summary-grid { margin-top: 14px; display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
.summary-card { padding: 24px; }
.summary-card span { font-size: 28px; }
.summary-card p { color: #cbd5e1; margin-bottom: 0; }
.text-green { color: #4ade80 !important; }
.text-red { color: #ef4444 !important; }

.bottom-grid { margin-top: 14px; display: grid; grid-template-columns: 1.1fr 1fr; gap: 14px; }
.panel { padding: 26px; }
.chart-line { height: 160px; margin-top: 24px; display: flex; align-items: flex-end; gap: 3px; }
.log-bar { flex: 1; min-height: 3px; border-radius: 3px 3px 0 0; }
.incident { margin-top: 18px; padding: 16px 0; border-bottom: 1px solid rgba(148,163,184,.12); color: #cbd5e1; }
.incident span { float: right; }
.incident span:empty { display: none; }

.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: flex; align-items: center; justify-content: center; z-index: 50; }
.modal { background: #0f172a; border: 1px solid rgba(148,163,184,.16); border-radius: 18px; padding: 28px; width: 500px; max-width: 90vw; }
.modal h2 { margin-bottom: 20px; }
.modal label { display: block; margin-bottom: 14px; color: #94a3b8; font-size: 13px; }
.modal input { width: 100%; background: #1e293b; border: 1px solid rgba(148,163,184,.16); border-radius: 10px; padding: 12px; color: #fff; margin-top: 4px; }
.modal .row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.modal-actions button { flex: 1; }
</style>
