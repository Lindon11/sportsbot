<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Update</h1>
        <p class="text-slate-400 text-sm mt-1">Pull and apply the latest live-server code update.</p>
      </div>
      <button
        @click="loadStatus"
        :disabled="checking || updating || forceSyncing || rebuildingAdmin"
        class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-800 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
      >
        <ArrowPathIcon :class="['h-5 w-5', checking && 'animate-spin']" />
        Refresh
      </button>
    </div>

    <div v-if="checking && !status" class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-8 text-center text-slate-400">
      Checking for updates...
    </div>

    <template v-else>
      <div v-if="error" class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
        {{ error }}
      </div>

      <div v-if="status" class="grid gap-4 md:grid-cols-4">
        <div class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-5">
          <p class="text-sm text-slate-400">Current</p>
          <p class="mt-2 font-mono text-lg text-white">{{ status.current_commit || 'unknown' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-5">
          <p class="text-sm text-slate-400">Remote</p>
          <p class="mt-2 font-mono text-lg text-white">{{ status.remote_commit || 'unknown' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-5">
          <p class="text-sm text-slate-400">Branch</p>
          <p class="mt-2 font-mono text-lg text-white">{{ status.branch || 'unknown' }}</p>
        </div>
        <div class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-5">
          <p class="text-sm text-slate-400">Behind</p>
          <p class="mt-2 text-lg font-semibold text-white">{{ status.commits_behind }} commit(s)</p>
        </div>
      </div>

      <div v-if="status" class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-6 space-y-5">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <div class="flex items-start gap-3">
            <CheckCircleIcon v-if="status.can_update" class="mt-0.5 h-6 w-6 text-emerald-400" />
            <ExclamationTriangleIcon v-else-if="status.update_available" class="mt-0.5 h-6 w-6 text-amber-400" />
            <InformationCircleIcon v-else class="mt-0.5 h-6 w-6 text-sky-400" />
            <div>
              <p class="font-medium text-white">{{ statusLabel }}</p>
              <p v-if="status.message" class="mt-1 text-sm text-slate-400">{{ status.message }}</p>
            </div>
          </div>
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
            <button
              @click="applyUpdate"
              :disabled="!status.can_update || updating || forceSyncing || rebuildingAdmin"
              class="inline-flex items-center justify-center gap-2 rounded-xl bg-amber-500 px-5 py-2.5 text-sm font-semibold text-slate-950 transition-colors hover:bg-amber-400 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
            >
              <ArrowDownTrayIcon v-if="!updating" class="h-5 w-5" />
              <ArrowPathIcon v-else class="h-5 w-5 animate-spin" />
              {{ updating ? 'Applying...' : 'Apply Update' }}
            </button>
            <button
              @click="rebuildAdminUi"
              :disabled="!canRebuildAdmin || updating || forceSyncing || rebuildingAdmin"
              class="inline-flex items-center justify-center gap-2 rounded-xl border border-sky-400/50 bg-sky-500/10 px-5 py-2.5 text-sm font-semibold text-sky-100 transition-colors hover:bg-sky-500/20 disabled:cursor-not-allowed disabled:border-slate-700 disabled:bg-slate-800 disabled:text-slate-500"
            >
              <WrenchScrewdriverIcon v-if="!rebuildingAdmin" class="h-5 w-5" />
              <ArrowPathIcon v-else class="h-5 w-5 animate-spin" />
              {{ rebuildingAdmin ? 'Rebuilding...' : 'Rebuild Admin UI' }}
            </button>
            <button
              @click="forceSync"
              :disabled="!canForceSync || updating || forceSyncing || rebuildingAdmin"
              class="inline-flex items-center justify-center gap-2 rounded-xl border border-red-400/50 bg-red-500/10 px-5 py-2.5 text-sm font-semibold text-red-100 transition-colors hover:bg-red-500/20 disabled:cursor-not-allowed disabled:border-slate-700 disabled:bg-slate-800 disabled:text-slate-500"
            >
              <ExclamationTriangleIcon v-if="!forceSyncing" class="h-5 w-5" />
              <ArrowPathIcon v-else class="h-5 w-5 animate-spin" />
              {{ forceSyncing ? 'Force Syncing...' : 'Force Sync' }}
            </button>
          </div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
          <div
            v-for="item in requirementItems"
            :key="item.name"
            class="flex items-center justify-between rounded-xl bg-slate-900/70 px-4 py-3"
          >
            <span class="text-sm text-slate-300">{{ item.name }}</span>
            <span :class="item.ok ? 'text-emerald-400' : 'text-red-300'" class="text-sm font-medium">
              {{ item.ok ? 'Ready' : 'Missing' }}
            </span>
          </div>
        </div>

        <div v-if="status.tracked_changes?.length" class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4">
          <p class="text-sm font-medium text-amber-200">Tracked changes on live</p>
          <pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-xs text-amber-100">{{ status.tracked_changes.join('\n') }}</pre>
        </div>

        <div
          v-if="status.has_tracked_changes || status.untracked_count"
          class="rounded-xl border border-red-500/30 bg-red-500/10 p-4 text-sm text-red-100"
        >
          Force Sync will discard tracked changes and remove {{ status.untracked_count || 0 }} untracked file(s) before rebuilding the app.
        </div>
      </div>

      <div v-if="logs.length" class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-6">
        <h2 class="text-lg font-semibold text-white">Update Log</h2>
        <div class="mt-4 space-y-3">
          <div
            v-for="(log, index) in logs"
            :key="`${log.step}-${index}`"
            class="rounded-xl bg-slate-900/80 p-4"
          >
            <div class="flex items-center gap-2">
              <CheckCircleIcon v-if="log.ok" class="h-5 w-5 text-emerald-400" />
              <XCircleIcon v-else class="h-5 w-5 text-red-400" />
              <p class="text-sm font-medium text-white">{{ log.step }}</p>
            </div>
            <pre class="mt-3 max-h-72 overflow-auto whitespace-pre-wrap text-xs leading-5 text-slate-300">{{ log.output || '(no output)' }}</pre>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import {
  ArrowDownTrayIcon,
  ArrowPathIcon,
  CheckCircleIcon,
  ExclamationTriangleIcon,
  InformationCircleIcon,
  WrenchScrewdriverIcon,
  XCircleIcon,
} from '@heroicons/vue/24/outline'
import api from '@/services/api'

const checking = ref(false)
const updating = ref(false)
const forceSyncing = ref(false)
const rebuildingAdmin = ref(false)
const error = ref('')
const logs = ref([])
const status = ref(null)

const requirementItems = computed(() => {
  const requirements = status.value?.requirements || {}

  return [
    { name: 'Git', ok: Boolean(requirements.git) },
    { name: 'PHP', ok: Boolean(requirements.php) },
    { name: 'Composer', ok: Boolean(requirements.composer) },
    { name: 'NPM', ok: Boolean(requirements.npm) },
    { name: 'Permission Helper', ok: Boolean(status.value?.deployment?.permission_helper?.available) },
  ]
})

const statusLabel = computed(() => {
  if (!status.value) return 'Unknown'
  if (!status.value.enabled) return 'Updater disabled'
  if (!status.value.repository_ready) return 'Repository not ready'
  if (status.value.can_update) return 'Update ready'
  if (status.value.update_available) return 'Update blocked'
  return 'Up to date'
})

const canForceSync = computed(() => {
  if (!status.value) return false

  const requirements = status.value.requirements || {}

  return (
    Boolean(status.value.enabled)
    && Boolean(status.value.repository_ready)
    && Boolean(requirements.git)
    && Boolean(requirements.php)
    && Boolean(requirements.composer)
    && Boolean(requirements.npm)
  )
})

const canRebuildAdmin = computed(() => {
  if (!status.value) return false

  const requirements = status.value.requirements || {}

  return (
    Boolean(status.value.enabled)
    && Boolean(requirements.php)
    && Boolean(requirements.npm)
  )
})

async function loadStatus() {
  checking.value = true
  error.value = ''

  try {
    const { data } = await api.get('/admin/sportsbot/update/check')
    status.value = data
  } catch (err) {
    error.value = err?.response?.data?.message || 'Failed to check update status'
  } finally {
    checking.value = false
  }
}

async function applyUpdate() {
  if (!status.value?.can_update || updating.value || forceSyncing.value || rebuildingAdmin.value) return
  if (!window.confirm('Apply the latest update now?')) return

  updating.value = true
  error.value = ''
  logs.value = []

  try {
    const { data } = await api.post('/admin/sportsbot/update/run')
    logs.value = data.logs || []
    status.value = data.status || status.value
  } catch (err) {
    const data = err?.response?.data
    logs.value = data?.logs || []
    status.value = data?.status || status.value
    error.value = data?.message || 'Update failed'
  } finally {
    updating.value = false
  }
}

async function forceSync() {
  if (!canForceSync.value || updating.value || forceSyncing.value || rebuildingAdmin.value) return

  const target = status.value?.force_sync_target || 'origin/main'
  const confirmation = window.prompt(
    `This will run git fetch, git reset --hard ${target}, git clean -fd, then rebuild the live admin app. Type RESET_AND_CLEAN to continue.`
  )

  if (confirmation !== 'RESET_AND_CLEAN') return

  forceSyncing.value = true
  error.value = ''
  logs.value = []

  try {
    const { data } = await api.post('/admin/sportsbot/update/force-sync', { confirmation })
    logs.value = data.logs || []
    status.value = data.status || status.value
  } catch (err) {
    const data = err?.response?.data
    logs.value = data?.logs || []
    status.value = data?.status || status.value
    error.value = data?.message || 'Force sync failed'
  } finally {
    forceSyncing.value = false
  }
}

async function rebuildAdminUi() {
  if (!canRebuildAdmin.value || updating.value || forceSyncing.value || rebuildingAdmin.value) return
  if (!window.confirm('Rebuild the admin Vue/Vite assets now?')) return

  rebuildingAdmin.value = true
  error.value = ''
  logs.value = []

  try {
    const { data } = await api.post('/admin/sportsbot/update/rebuild-admin-ui')
    logs.value = data.logs || []
    status.value = data.status || status.value
  } catch (err) {
    const data = err?.response?.data
    logs.value = data?.logs || []
    status.value = data?.status || status.value
    error.value = data?.message || 'Admin UI rebuild failed'
  } finally {
    rebuildingAdmin.value = false
  }
}

onMounted(loadStatus)
</script>
