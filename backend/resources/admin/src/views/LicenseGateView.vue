<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-4">
    <!-- Background Effects -->
    <div class="absolute inset-0 overflow-hidden">
      <div class="absolute -top-40 -right-40 w-80 h-80 bg-red-500/15 rounded-full blur-3xl" />
      <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-orange-500/15 rounded-full blur-3xl" />
    </div>

    <div class="relative w-full max-w-lg">
      <!-- Lock Icon -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-20 h-20 rounded-2xl bg-gradient-to-br from-red-500 to-orange-600 shadow-lg shadow-red-500/30 mb-4">
          <LockClosedIcon class="w-10 h-10 text-white" />
        </div>
        <h1 class="text-3xl font-bold text-white">Panel Locked</h1>
        <p class="text-slate-400 mt-2 max-w-sm mx-auto">
          A valid license key is required to access the admin panel. Enter your license key below to unlock.
        </p>
      </div>

      <!-- Activation Card -->
      <div class="bg-slate-800/50 backdrop-blur-xl border border-slate-700/50 rounded-2xl p-8 shadow-2xl">
        <!-- License Status -->
        <div v-if="licenseStatus && !licenseStatus.licensed" class="mb-6">
          <div class="flex items-center gap-3 p-4 rounded-xl bg-red-500/10 border border-red-500/30">
            <ShieldExclamationIcon class="w-5 h-5 text-red-400 flex-shrink-0" />
            <div>
              <p class="text-sm font-medium text-red-400">No Active License</p>
              <p class="text-xs text-red-400/70 mt-0.5">{{ licenseStatus.message || 'Please activate a valid license key.' }}</p>
            </div>
          </div>
        </div>

        <form @submit.prevent="handleActivate" class="space-y-6">
          <!-- Success Message -->
          <div v-if="success"
            class="flex items-center gap-3 p-4 rounded-xl bg-emerald-500/10 border border-emerald-500/30"
          >
            <CheckCircleIcon class="w-5 h-5 text-emerald-400 flex-shrink-0" />
            <div>
              <p class="text-sm font-medium text-emerald-400">License Activated!</p>
              <p class="text-xs text-emerald-400/70 mt-0.5">Redirecting to dashboard...</p>
            </div>
          </div>

          <!-- Error Message -->
          <div v-if="error"
            class="flex items-center gap-3 p-4 rounded-xl bg-red-500/10 border border-red-500/30"
          >
            <ExclamationCircleIcon class="w-5 h-5 text-red-400 flex-shrink-0" />
            <p class="text-sm text-red-400">{{ error }}</p>
          </div>

          <!-- License Key Input -->
          <div class="space-y-2">
            <label class="block text-sm font-medium text-slate-300">License Key</label>
            <div class="relative">
              <KeyIcon class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
              <input
                v-model="licenseKey"
                type="text"
                placeholder="LCP-XXX-XXXXXXXX..."
                required
                :disabled="loading || success"
                class="w-full pl-12 pr-4 py-3 rounded-xl bg-slate-900/50 border border-slate-600/50 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all font-mono text-sm disabled:opacity-50"
              />
            </div>
            <p class="text-xs text-slate-500">Paste your full license key starting with LCP-</p>
          </div>

          <!-- Activate Button -->
          <button
            type="submit"
            :disabled="loading || success || !licenseKey.trim()"
            class="w-full py-3.5 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white font-semibold shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100 transition-all"
          >
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <svg class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Verifying License...
            </span>
            <span v-else class="flex items-center justify-center gap-2">
              <LockOpenIcon class="w-5 h-5" />
              Activate &amp; Unlock Panel
            </span>
          </button>
        </form>

        <!-- Divider -->
        <div class="my-6 border-t border-slate-700/50" />

        <!-- Help Info -->
        <div class="space-y-3">
          <div class="flex items-start gap-3">
            <InformationCircleIcon class="w-5 h-5 text-slate-500 flex-shrink-0 mt-0.5" />
            <div>
              <p class="text-sm text-slate-400">Don't have a license key?</p>
              <p class="text-xs text-slate-500 mt-1">
                Contact the LaravelCP developer to purchase a license for your installation.
              </p>
            </div>
          </div>
        </div>
      </div>

      <!-- Back to Login / Logout -->
      <div class="flex items-center justify-center gap-4 mt-6">
        <button
          @click="handleLogout"
          class="text-slate-500 hover:text-slate-300 text-sm transition-colors flex items-center gap-1.5"
        >
          <ArrowLeftOnRectangleIcon class="w-4 h-4" />
          Sign Out
        </button>
      </div>

      <!-- Footer -->
      <p class="text-center text-slate-500 text-sm mt-4">
        LaravelCP Admin Panel &copy; {{ new Date().getFullYear() }}
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import {
  LockClosedIcon,
  LockOpenIcon,
  KeyIcon,
  ShieldExclamationIcon,
  CheckCircleIcon,
  ExclamationCircleIcon,
  InformationCircleIcon,
  ArrowLeftOnRectangleIcon
} from '@heroicons/vue/24/outline'

const router = useRouter()

const licenseKey = ref('')
const error = ref('')
const success = ref(false)
const loading = ref(false)
const licenseStatus = ref(null)

onMounted(async () => {
  // Check if already licensed — if so, redirect straight to dashboard
  try {
    const res = await api.get('/admin/license/status')
    licenseStatus.value = res.data
    if (res.data.licensed) {
      router.replace('/dashboard')
    }
  } catch (err) {
    // 423 is expected when unlicensed — license/status is excluded from the gate
    // Any other error just means we show the activation form
    if (err.response?.data) {
      licenseStatus.value = { licensed: false, message: err.response.data.message }
    }
  }
})

const handleActivate = async () => {
  error.value = ''
  success.value = false
  loading.value = true

  try {
    const res = await api.post('/admin/license/activate', {
      license_key: licenseKey.value.trim()
    })

    if (res.data.success) {
      success.value = true
      // Short delay so the user sees the success message
      setTimeout(() => {
        router.replace('/dashboard')
      }, 1500)
    } else {
      error.value = res.data.error || 'Activation failed.'
    }
  } catch (err) {
    error.value = err.response?.data?.error || err.response?.data?.message || 'Failed to activate license. Please check your key and try again.'
  } finally {
    loading.value = false
  }
}

const handleLogout = () => {
  localStorage.removeItem('admin_token')
  localStorage.removeItem('admin_user')
  router.push('/login')
}
</script>
