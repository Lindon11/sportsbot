<template>
  <div class="space-y-6">
    <!-- Tabs -->
    <div class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-2">
      <div class="flex flex-wrap gap-2">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          :class="[
            'px-4 py-2 rounded-xl text-sm font-medium transition-all flex items-center gap-2',
            activeTab === tab.id
              ? 'bg-gradient-to-r from-amber-500 to-orange-600 text-white shadow-lg shadow-amber-500/25'
              : 'text-slate-400 hover:text-white hover:bg-slate-700/50'
          ]"
          @click="activeTab = tab.id"
        >
          <component :is="tab.icon" class="w-5 h-5" />
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-8">
      <div class="flex items-center justify-center">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-amber-500"></div>
        <span class="ml-3 text-slate-400">Loading settings...</span>
      </div>
    </div>

    <form v-else @submit.prevent="saveSettings" class="space-y-6">
      <!-- Dynamic Settings Groups -->
      <div
        v-for="(group, groupId) in groups"
        v-show="activeTab === groupId"
        :key="groupId"
        class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-6"
      >
        <div class="flex items-center gap-3 mb-6">
          <component :is="getIconComponent(group.icon)" class="w-6 h-6 text-amber-400" />
          <div>
            <h2 class="text-xl font-semibold text-white">{{ group.label }}</h2>
            <p v-if="group.plugin_name && group.plugin_slug !== 'core'" class="text-sm text-slate-400">
              Provided by {{ group.plugin_name }}
            </p>
          </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div
            v-for="(config, key) in group.settings"
            :key="key"
            class="space-y-2"
          >
            <!-- Boolean Toggle -->
            <template v-if="config.type === 'boolean'">
              <label class="flex items-center justify-between">
                <span class="text-sm font-medium text-slate-300">{{ config.label }}</span>
                <div class="relative">
                  <input
                    v-model="settings[key]"
                    type="checkbox"
                    class="sr-only peer"
                    :id="key"
                  >
                  <label
                    :for="key"
                    class="relative flex h-6 w-11 cursor-pointer items-center rounded-full bg-slate-600 px-0.5 outline-none transition-colors duration-200 ease-in-out focus-visible:ring focus-visible:ring-amber-500 focus-visible:ring-opacity-75 peer-checked:bg-gradient-to-r peer-checked:from-amber-500 peer-checked:to-orange-600"
                  >
                    <span class="sr-only">{{ config.label }}</span>
                    <span class="h-5 w-5 rounded-full bg-white shadow-lg transition-transform duration-200 ease-in-out peer-checked:translate-x-5"></span>
                  </label>
                </div>
              </label>
              <p v-if="config.description" class="text-xs text-slate-400">{{ config.description }}</p>
            </template>

            <!-- Number Input -->
            <template v-else-if="config.type === 'number'">
              <label class="block text-sm font-medium text-slate-300">{{ config.label }}</label>
              <div class="relative">
                <input
                  v-model.number="settings[key]"
                  type="number"
                  :min="config.min"
                  :max="config.max"
                  :step="config.step || 1"
                  :placeholder="config.placeholder"
                  class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600/50 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all"
                >
                <span v-if="config.max === 100 && config.min === 1" class="absolute right-4 top-3.5 text-slate-400">%</span>
                <span v-else-if="key.includes('cash') || key.includes('balance')" class="absolute left-4 top-3.5 text-slate-400">$</span>
              </div>
              <p v-if="config.description" class="text-xs text-slate-400">{{ config.description }}</p>
            </template>

            <!-- Select Input -->
            <template v-else-if="config.type === 'select'">
              <label class="block text-sm font-medium text-slate-300">{{ config.label }}</label>
              <select
                v-model="settings[key]"
                class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600/50 rounded-xl text-white focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all"
              >
                <option v-for="option in config.options" :key="option.value" :value="option.value">
                  {{ option.label }}
                </option>
              </select>
              <p v-if="config.description" class="text-xs text-slate-400">{{ config.description }}</p>
            </template>

            <!-- Text Input (default) -->
            <template v-else>
              <label class="block text-sm font-medium text-slate-300">{{ config.label }}</label>
              <input
                v-model="settings[key]"
                type="text"
                :placeholder="config.placeholder"
                class="w-full px-4 py-3 bg-slate-900/50 border border-slate-600/50 rounded-xl text-white placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all"
              >
              <p v-if="config.description" class="text-xs text-slate-400">{{ config.description }}</p>
            </template>
          </div>
        </div>

        <!-- Show message if no settings in group -->
        <div v-if="Object.keys(group.settings || {}).length === 0" class="text-center py-8 text-slate-400">
          No settings available in this category.
        </div>
      </div>

      <!-- No Settings Available -->
      <div v-if="Object.keys(groups).length === 0" class="rounded-2xl bg-slate-800/50 backdrop-blur border border-slate-700/50 p-8 text-center">
        <Cog6ToothIcon class="w-12 h-12 text-slate-500 mx-auto mb-4" />
        <h3 class="text-lg font-medium text-white mb-2">No Settings Available</h3>
        <p class="text-slate-400">Install plugins to add configurable settings to your panel.</p>
      </div>

      <div v-if="Object.keys(groups).length > 0" class="flex items-center justify-between pt-6 border-t border-slate-700/50">
        <button type="button" @click="resetSettings" class="flex items-center gap-2 px-4 py-2.5 bg-slate-700/50 hover:bg-slate-700 text-slate-300 hover:text-white rounded-xl font-medium transition-all">
          <ArrowPathIcon class="w-5 h-5" />
          Reset to Defaults
        </button>
        <button type="submit" :disabled="saving" class="flex items-center gap-2 px-6 py-2.5 bg-gradient-to-r from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 text-white rounded-xl font-medium shadow-lg shadow-amber-500/20 transition-all disabled:opacity-50">
          <CheckIcon class="w-5 h-5" />
          {{ saving ? 'Saving...' : 'Save Settings' }}
        </button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, computed, shallowRef } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'
import {
  Cog6ToothIcon,
  FireIcon,
  BanknotesIcon,
  ChartBarIcon,
  ClockIcon,
  WrenchScrewdriverIcon,
  ArrowPathIcon,
  CheckIcon,
  ShieldCheckIcon,
  UserGroupIcon,
  ServerIcon,
  BoltIcon,
  PuzzlePieceIcon,
  GlobeAltIcon,
  ChatBubbleLeftRightIcon,
  Squares2X2Icon,
  TrophyIcon,
  MapIcon,
  TruckIcon,
  BuildingOfficeIcon,
  CurrencyDollarIcon,
  HeartIcon,
  KeyIcon,
} from '@heroicons/vue/24/outline'

const loading = ref(true)
const saving = ref(false)
const activeTab = ref('general')
const toast = useToast()

// Settings data
const settings = reactive({})
const groups = reactive({})

// Icon mapping for dynamic icons
const iconMap = {
  'Cog6ToothIcon': Cog6ToothIcon,
  'FireIcon': FireIcon,
  'BanknotesIcon': BanknotesIcon,
  'ChartBarIcon': ChartBarIcon,
  'ClockIcon': ClockIcon,
  'WrenchScrewdriverIcon': WrenchScrewdriverIcon,
  'ShieldCheckIcon': ShieldCheckIcon,
  'UserGroupIcon': UserGroupIcon,
  'ServerIcon': ServerIcon,
  'BoltIcon': BoltIcon,
  'PuzzlePieceIcon': PuzzlePieceIcon,
  'GlobeAltIcon': GlobeAltIcon,
  'ChatBubbleLeftRightIcon': ChatBubbleLeftRightIcon,
  'Squares2X2Icon': Squares2X2Icon,
  'TrophyIcon': TrophyIcon,
  'MapIcon': MapIcon,
  'TruckIcon': TruckIcon,
  'BuildingOfficeIcon': BuildingOfficeIcon,
  'CurrencyDollarIcon': CurrencyDollarIcon,
  'HeartIcon': HeartIcon,
  'KeyIcon': KeyIcon,
}

// Get icon component from string name
const getIconComponent = (iconName) => {
  return iconMap[iconName] || Cog6ToothIcon
}

// Computed tabs from groups
const tabs = computed(() => {
  return Object.entries(groups).map(([id, group]) => ({
    id,
    label: group.label,
    icon: getIconComponent(group.icon),
    order: group.order || 100,
  })).sort((a, b) => a.order - b.order)
})

const loadSettings = async () => {
  loading.value = true
  try {
    const response = await api.get('/admin/settings/all')

    // Clear and populate settings
    Object.keys(settings).forEach(key => delete settings[key])
    Object.assign(settings, response.data.settings || {})

    // Clear and populate groups
    Object.keys(groups).forEach(key => delete groups[key])
    Object.assign(groups, response.data.groups || {})

    // Set first tab as active if current tab doesn't exist
    const tabIds = Object.keys(groups)
    if (tabIds.length > 0 && !tabIds.includes(activeTab.value)) {
      activeTab.value = tabIds[0]
    }
  } catch (error) {
    console.error('Error loading settings:', error)
    toast.error('Failed to load settings')
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true

  try {
    await api.post('/admin/settings', settings)
    toast.success('Settings saved successfully!')
  } catch (error) {
    toast.error(error.response?.data?.message || 'Failed to save settings')
  } finally {
    saving.value = false
  }
}

const resetSettings = () => {
  if (confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
    loadSettings()
  }
}

onMounted(() => {
  loadSettings()
})
</script>

<style scoped>
.settings-view {
  padding: 2rem;
  max-width: 900px;
  margin: 0 auto;
}

.page-header {
  margin-bottom: 2rem;
}

.page-header h1 {
  font-size: 2rem;
  color: #f1f5f9;
  margin-bottom: 0.5rem;
}

.subtitle {
  color: #94a3b8;
}

.settings-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 2rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
}

.tab-btn {
  padding: 0.75rem 1.25rem;
  background: rgba(30, 41, 59, 0.5);
  border: 1px solid rgba(148, 163, 184, 0.1);
  border-radius: 0.5rem;
  color: #94a3b8;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
}

.tab-btn:hover {
  background: rgba(59, 130, 246, 0.1);
  color: #3b82f6;
}

.tab-btn.active {
  background: linear-gradient(135deg, #3b82f6, #1d4ed8);
  color: white;
  border-color: transparent;
}

.loading-state {
  padding: 4rem;
  text-align: center;
  color: #94a3b8;
}

.spinner {
  width: 40px;
  height: 40px;
  border: 3px solid rgba(59, 130, 246, 0.3);
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin: 0 auto 1rem;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.settings-section {
  background: rgba(30, 41, 59, 0.5);
  border-radius: 0.75rem;
  border: 1px solid rgba(148, 163, 184, 0.1);
  padding: 2rem;
  margin-bottom: 1.5rem;
}

.settings-section h2 {
  font-size: 1.25rem;
  color: #f1f5f9;
  margin-bottom: 1.5rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
}

.setting-group {
  margin-bottom: 1.5rem;
}

.setting-group label {
  display: block;
  font-weight: 600;
  color: #f1f5f9;
  margin-bottom: 0.5rem;
}

.setting-group input[type="text"],
.setting-group input[type="number"] {
  width: 100%;
  max-width: 400px;
  padding: 0.875rem 1.125rem;
  background: rgba(15, 23, 42, 0.5);
  border: 2px solid rgba(148, 163, 184, 0.15);
  border-radius: 0.625rem;
  color: #f1f5f9;
  font-size: 0.938rem;
  transition: all 0.2s ease;
}

.setting-group input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.help-text {
  display: block;
  font-size: 0.75rem;
  color: #64748b;
  margin-top: 0.5rem;
}

.toggle-wrapper {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.toggle-wrapper input[type="checkbox"] {
  width: 20px;
  height: 20px;
  accent-color: #3b82f6;
}

.toggle-label {
  font-weight: normal !important;
  color: #cbd5e1;
  cursor: pointer;
}

.feature-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 1rem;
}

.feature-toggle {
  padding: 1rem;
  background: rgba(15, 23, 42, 0.3);
  border-radius: 0.5rem;
  border: 1px solid rgba(148, 163, 184, 0.1);
}

.form-actions {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 2rem;
  padding-top: 2rem;
  border-top: 1px solid rgba(148, 163, 184, 0.1);
}

.btn-save,
.btn-reset {
  padding: 1rem 2rem;
  border-radius: 0.625rem;
  font-weight: 600;
  font-size: 1rem;
  cursor: pointer;
  transition: all 0.2s ease;
}

.btn-save {
  background: linear-gradient(135deg, #10b981, #059669);
  color: white;
  border: none;
}

.btn-save:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.btn-save:disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.btn-reset {
  background: transparent;
  border: 1px solid #64748b;
  color: #94a3b8;
}

.btn-reset:hover {
  background: rgba(100, 116, 139, 0.1);
}

.message {
  margin-top: 1.5rem;
  padding: 1rem 1.5rem;
  border-radius: 0.5rem;
  font-weight: 500;
}

.message.success {
  background: rgba(16, 185, 129, 0.1);
  border: 1px solid rgba(16, 185, 129, 0.3);
  color: #10b981;
}

.message.error {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #ef4444;
}

@media (max-width: 768px) {
  .settings-tabs {
    justify-content: center;
  }

  .form-actions {
    flex-direction: column;
  }

  .btn-save,
  .btn-reset {
    width: 100%;
  }
}
</style>
