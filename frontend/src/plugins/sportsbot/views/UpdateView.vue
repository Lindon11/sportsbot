<template>
  <div class="space-y-6">
    <div class="flex justify-between items-center">
      <div>
        <h1 class="text-2xl font-bold text-white">Update</h1>
        <p class="text-gray-400 mt-1">Check for updates and apply them from the repo</p>
      </div>
    </div>

    <div v-if="checking" class="text-gray-400 py-8 text-center">Checking for updates...</div>

    <template v-else>
      <div class="bg-gray-800 rounded-lg p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm text-gray-400">Current Commit</label>
            <span class="text-white font-mono">{{ status.current_commit }}</span>
          </div>
          <div>
            <label class="block text-sm text-gray-400">Branch</label>
            <span class="text-white font-mono">{{ status.branch }}</span>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <span v-if="status.update_available" class="text-yellow-400 font-medium">
            {{ status.commits_behind }} commit(s) behind. Update available.
          </span>
          <span v-else class="text-green-400 font-medium">Up to date.</span>
        </div>
        <div class="flex gap-3">
          <button
            @click="checkUpdates"
            :disabled="checking"
            class="px-4 py-2 bg-gray-700 hover:bg-gray-600 disabled:bg-gray-600 disabled:cursor-not-allowed text-white rounded-lg transition-colors text-sm"
          >
            {{ checking ? 'Checking...' : 'Refresh' }}
          </button>
          <button
            v-if="status.update_available"
            @click="confirmUpdate"
            :disabled="updating"
            class="px-4 py-2 bg-amber-500 hover:bg-amber-600 disabled:bg-gray-600 disabled:cursor-not-allowed text-black font-medium rounded-lg transition-colors"
          >
            {{ updating ? 'Applying...' : 'Apply Update' }}
          </button>
        </div>
      </div>

      <div v-if="updateLogs.length > 0" class="bg-gray-800 rounded-lg p-6">
        <h3 class="text-lg font-medium text-white mb-3">Update Log</h3>
        <div class="space-y-2">
          <div
            v-for="(log, i) in updateLogs"
            :key="i"
            class="bg-gray-900 rounded p-3"
          >
            <div class="flex items-center gap-2 mb-1">
              <span v-if="log.ok" class="text-green-400 text-sm font-medium">PASS</span>
              <span v-else class="text-red-400 text-sm font-medium">FAIL</span>
              <span class="text-white text-sm font-medium">{{ log.step }}</span>
            </div>
            <pre class="text-gray-400 text-xs whitespace-pre-wrap font-mono">{{ log.output || '(no output)' }}</pre>
          </div>
        </div>
      </div>

      <div v-if="updateError" class="bg-red-900/50 border border-red-700 text-red-300 px-4 py-3 rounded-lg">
        {{ updateError }}
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import api from '@/services/api'

const checking = ref(false)
const updating = ref(false)
const updateLogs = ref<any[]>([])
const updateError = ref('')

const status = reactive({
  current_commit: '',
  branch: '',
  commits_behind: 0,
  update_available: false,
})

async function checkUpdates() {
  checking.value = true
  updateError.value = ''
  try {
    const res = await api.get('/api/v1/admin/sportsbot/update/check')
    status.current_commit = res.data.current_commit
    status.branch = res.data.branch
    status.commits_behind = res.data.commits_behind
    status.update_available = res.data.update_available
  } catch (e: any) {
    updateError.value = e?.response?.data?.message || 'Failed to check for updates'
  } finally {
    checking.value = false
  }
}

function confirmUpdate() {
  if (confirm('Are you sure you want to apply the update? This may take a moment.')) {
    applyUpdate()
  }
}

async function applyUpdate() {
  updating.value = true
  updateLogs.value = []
  updateError.value = ''
  try {
    const res = await api.post('/api/v1/admin/sportsbot/update/run')
    updateLogs.value = res.data.logs || []
    if (res.data.ok) {
      status.update_available = false
    }
  } catch (e: any) {
    updateError.value = e?.response?.data?.message || 'Update failed'
  } finally {
    updating.value = false
  }
}

onMounted(checkUpdates)
</script>
