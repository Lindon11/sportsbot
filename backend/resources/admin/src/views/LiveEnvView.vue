<template>
  <div class="space-y-6">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
      <div>
        <h1 class="text-2xl font-bold text-white">Live Env</h1>
        <p class="mt-1 text-sm text-slate-400">Manage live server environment values without SSH.</p>
      </div>
      <div class="flex flex-wrap gap-3">
        <button
          type="button"
          @click="loadEnv"
          :disabled="loading || saving"
          class="inline-flex items-center gap-2 rounded-xl bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <ArrowPathIcon :class="['h-5 w-5', loading && 'animate-spin']" />
          Refresh
        </button>
        <button
          type="button"
          @click="saveEnv"
          :disabled="saving || !hasChanges || !writable"
          class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-amber-400 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
        >
          <CheckIcon class="h-5 w-5" />
          {{ saving ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
    </div>

    <div class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-4">
      <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <p class="text-sm font-medium text-white">{{ envPath || '.env' }}</p>
          <p class="text-xs text-slate-400">A timestamped backup is created before each save.</p>
        </div>
        <span :class="writable ? 'text-emerald-300' : 'text-red-300'" class="text-sm font-medium">
          {{ writable ? 'Writable' : 'Not writable' }}
        </span>
      </div>
    </div>

    <div v-if="error" class="rounded-xl border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-200">
      {{ error }}
    </div>

    <div v-if="loading && !groups.length" class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-8 text-center text-slate-400">
      Loading environment settings...
    </div>

    <template v-else>
      <div class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-2">
        <div class="flex flex-wrap gap-2">
          <button
            v-for="group in groups"
            :key="group.id"
            type="button"
            @click="activeGroup = group.id"
            :class="[
              'rounded-xl px-4 py-2 text-sm font-medium transition-colors',
              activeGroup === group.id
                ? 'bg-amber-500 text-slate-950'
                : 'text-slate-400 hover:bg-slate-700/70 hover:text-white'
            ]"
          >
            {{ group.label }}
          </button>
        </div>
      </div>

      <div
        v-for="group in groups"
        v-show="activeGroup === group.id"
        :key="group.id"
        class="rounded-2xl border border-slate-700/60 bg-slate-800/60 p-6"
      >
        <div class="mb-6 space-y-2">
          <h2 class="text-xl font-semibold text-white">{{ group.label }}</h2>
          <p v-if="group.description" class="text-sm text-slate-400">{{ group.description }}</p>
          <p v-if="group.warning" class="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 text-sm text-amber-100">
            {{ group.warning }}
          </p>
        </div>

        <div class="grid gap-5 xl:grid-cols-2">
          <div
            v-for="field in group.fields"
            :key="field.key"
            class="space-y-2 rounded-xl bg-slate-900/60 p-4"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <label :for="field.key" class="block text-sm font-medium text-slate-200">
                  {{ field.label }}
                </label>
                <p class="mt-1 font-mono text-xs text-slate-500">{{ field.key }}</p>
              </div>
              <span v-if="field.secret && field.configured" class="rounded-full bg-emerald-500/10 px-2 py-1 text-xs font-medium text-emerald-300">
                Set
              </span>
              <span v-else-if="field.read_only" class="rounded-full bg-slate-700 px-2 py-1 text-xs font-medium text-slate-300">
                Read only
              </span>
            </div>

            <template v-if="field.type === 'boolean'">
              <button
                type="button"
                @click="toggleBoolean(field)"
                :disabled="field.read_only"
                :class="[
                  'relative inline-flex h-7 w-12 items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-60',
                  form[field.key] ? 'bg-amber-500' : 'bg-slate-600'
                ]"
              >
                <span :class="[
                  'inline-block h-5 w-5 transform rounded-full bg-white transition-transform',
                  form[field.key] ? 'translate-x-6' : 'translate-x-1'
                ]" />
              </button>
            </template>

            <select
              v-else-if="field.type === 'select'"
              :id="field.key"
              v-model="form[field.key]"
              :disabled="field.read_only"
              @change="markDirty(field.key)"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/40 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <option v-for="option in field.options || []" :key="option" :value="option">
                {{ option }}
              </option>
            </select>

            <textarea
              v-else-if="field.type === 'textarea'"
              :id="field.key"
              v-model="form[field.key]"
              :disabled="field.read_only"
              @input="markDirty(field.key)"
              rows="4"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder-slate-500 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/40 disabled:cursor-not-allowed disabled:opacity-60"
            />

            <input
              v-else
              :id="field.key"
              v-model="form[field.key]"
              :type="field.secret ? 'password' : field.type === 'number' ? 'number' : 'text'"
              :disabled="field.read_only"
              :placeholder="field.secret && field.configured ? 'Leave blank to keep current value' : ''"
              @input="markDirty(field.key)"
              class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-white placeholder-slate-500 focus:border-amber-500 focus:outline-none focus:ring-2 focus:ring-amber-500/40 disabled:cursor-not-allowed disabled:opacity-60"
            >

            <p v-if="field.warning" class="text-xs text-amber-200">{{ field.warning }}</p>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { ArrowPathIcon, CheckIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const envPath = ref('')
const writable = ref(false)
const groups = ref([])
const activeGroup = ref('')
const dirty = reactive({})
const form = reactive({})

const hasChanges = computed(() => Object.values(dirty).some(Boolean))

async function loadEnv() {
  loading.value = true
  error.value = ''

  try {
    const { data } = await api.get('/admin/env')
    envPath.value = data.env_path || ''
    writable.value = Boolean(data.writable)
    groups.value = data.groups || []

    Object.keys(form).forEach(key => delete form[key])
    Object.keys(dirty).forEach(key => delete dirty[key])

    for (const group of groups.value) {
      for (const field of group.fields || []) {
        form[field.key] = field.type === 'boolean'
          ? normalizeBoolean(field.value)
          : field.value ?? ''
        dirty[field.key] = false
      }
    }

    if (!activeGroup.value && groups.value.length) {
      activeGroup.value = groups.value[0].id
    }
  } catch (err) {
    error.value = err?.response?.data?.message || 'Failed to load live environment settings'
  } finally {
    loading.value = false
  }
}

async function saveEnv() {
  if (!hasChanges.value) return

  saving.value = true
  error.value = ''

  const values = {}
  for (const key of Object.keys(dirty)) {
    if (dirty[key]) {
      values[key] = form[key]
    }
  }

  try {
    const { data } = await api.post('/admin/env', { values })
    toast.success(data.message || 'Environment settings saved')
    await loadEnv()
  } catch (err) {
    error.value = err?.response?.data?.message || 'Failed to save live environment settings'
    toast.error(error.value)
  } finally {
    saving.value = false
  }
}

function toggleBoolean(field) {
  if (field.read_only) return
  form[field.key] = !form[field.key]
  markDirty(field.key)
}

function markDirty(key) {
  dirty[key] = true
}

function normalizeBoolean(value) {
  return value === true || value === 'true' || value === '1' || value === 1
}

onMounted(loadEnv)
</script>
