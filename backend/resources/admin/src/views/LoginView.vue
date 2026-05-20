<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-4">
    <!-- Background Effects -->
    <div class="absolute inset-0 overflow-hidden">
      <div class="absolute -top-40 -right-40 w-80 h-80 bg-amber-500/20 rounded-full blur-3xl" />
      <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-orange-500/20 rounded-full blur-3xl" />
    </div>

    <div class="relative w-full max-w-md">
      <!-- Logo Card -->
      <div class="text-center mb-8">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-lg shadow-amber-500/30 mb-4">
          <BoltIcon class="w-8 h-8 text-white" />
        </div>
        <h1 class="text-3xl font-bold text-white">LaravelCP</h1>
        <p class="text-slate-400 mt-1">Admin Control Panel</p>
      </div>

      <!-- Login Card -->
      <div class="bg-slate-800/50 backdrop-blur-xl border border-slate-700/50 rounded-2xl p-8 shadow-2xl">
        <form @submit.prevent="twoFactorRequired ? handle2faVerify() : handleLogin()" class="space-y-6">
                    <!-- 2FA Code Input -->
                    <div v-if="twoFactorRequired" class="space-y-2">
                      <label class="block text-sm font-medium text-slate-300">Two-Factor Code</label>
                      <div class="relative">
                        <input
                          v-model="twoFactorCode"
                          type="text"
                          maxlength="6"
                          placeholder="Enter your 2FA code"
                          required
                          class="w-full pl-4 pr-4 py-4 rounded-xl bg-slate-900/50 border border-slate-600/50 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all text-base"
                        />
                      </div>
                    </div>
          <!-- Error Message -->
          <div v-if="error"
            class="flex items-center gap-3 p-4 rounded-xl bg-red-500/10 border border-red-500/30"
          >
            <ExclamationCircleIcon class="w-5 h-5 text-red-400 flex-shrink-0" />
            <p class="text-sm text-red-400">{{ error }}</p>
          </div>

          <!-- Email/Username -->
          <div class="space-y-2">
            <label class="block text-sm font-medium text-slate-300">Email or Username</label>
            <div class="relative">
              <UserIcon class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
              <input
                v-model="credentials.login"
                type="text"
                placeholder="Enter your credentials"
                required
                class="w-full pl-12 pr-4 py-4 rounded-xl bg-slate-900/50 border border-slate-600/50 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all text-base"
              />
            </div>
          </div>

          <!-- Password -->
          <div class="space-y-2">
            <label class="block text-sm font-medium text-slate-300">Password</label>
            <div class="relative">
              <LockClosedIcon class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 text-slate-400" />
              <input
                v-model="credentials.password"
                :type="showPassword ? 'text' : 'password'"
                placeholder="Enter your password"
                required
                class="w-full pl-12 pr-12 py-4 rounded-xl bg-slate-900/50 border border-slate-600/50 text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-amber-500/50 focus:border-amber-500/50 transition-all text-base"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-white transition-colors"
              >
                <EyeIcon v-if="!showPassword" class="w-5 h-5" />
                <EyeSlashIcon v-else class="w-5 h-5" />
              </button>
            </div>
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="loading"
            class="w-full py-4 rounded-xl bg-gradient-to-r from-amber-500 to-orange-600 text-white font-semibold shadow-lg shadow-amber-500/30 hover:shadow-amber-500/50 hover:scale-[1.02] disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:scale-100 transition-all text-base"
          >
            <span v-if="loading" class="flex items-center justify-center gap-2">
              <svg class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
              </svg>
              Signing in...
            </span>
            <span v-else class="flex items-center justify-center gap-2">
              <ArrowRightOnRectangleIcon class="w-5 h-5" />
              Sign In
            </span>
          </button>
        </form>

        <!-- OAuth Providers -->
        <div v-if="oauthProviders.length > 0" class="mt-6">
          <div class="flex items-center gap-3 mb-4">
            <div class="flex-1 h-px bg-slate-600/50" />
            <span class="text-xs text-slate-500 uppercase tracking-wider">or continue with</span>
            <div class="flex-1 h-px bg-slate-600/50" />
          </div>
          <div class="grid gap-2" :class="oauthProviders.length === 1 ? 'grid-cols-1' : 'grid-cols-2'">
            <button
              v-for="provider in oauthProviders"
              :key="provider.name"
              @click="loginWithOAuth(provider.name)"
              :disabled="oauthLoading === provider.name"
              class="flex items-center justify-center gap-2 py-2.5 px-4 rounded-xl border border-slate-600/50 bg-slate-900/50 text-slate-300 hover:text-white hover:bg-slate-700/50 hover:border-slate-500/50 transition-all disabled:opacity-50"
            >
              <component :is="oauthIcons[provider.name]" class="w-5 h-5" />
              <span class="text-sm font-medium capitalize">{{ provider.name }}</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <p class="text-center text-slate-500 text-sm mt-6">
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
  BoltIcon,
  UserIcon,
  LockClosedIcon,
  EyeIcon,
  EyeSlashIcon,
  ArrowRightOnRectangleIcon,
  ExclamationCircleIcon
} from '@heroicons/vue/24/outline'

// Inline SVG icon components for OAuth providers
const DiscordIcon = { template: '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994a.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>' }
const GoogleIcon = { template: '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09zM12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23zM5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62zM12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>' }
const GithubIcon = { template: '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/></svg>' }
const TwitterIcon = { template: '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>' }
const FacebookIcon = { template: '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>' }

const oauthIcons = { discord: DiscordIcon, google: GoogleIcon, github: GithubIcon, twitter: TwitterIcon, facebook: FacebookIcon }

const router = useRouter()

const credentials = ref({
  login: '',
  password: ''
})
const error = ref('')
const loading = ref(false)
const showPassword = ref(false)
const twoFactorRequired = ref(false)
const challengeToken = ref('')
const twoFactorCode = ref('')
const pendingUser = ref(null)
const oauthProviders = ref([])
const oauthLoading = ref(null)

onMounted(async () => {
  // Handle redirect back from OAuth provider
  const params = new URLSearchParams(window.location.search)
  const oauthToken = params.get('oauth_token')
  const oauthError = params.get('oauth_error')

  if (oauthError) {
    error.value = 'OAuth login failed: ' + decodeURIComponent(oauthError)
    window.history.replaceState({}, '', window.location.pathname)
  } else if (oauthToken) {
    try {
      const userEncoded = params.get('oauth_user')
      const user = userEncoded ? JSON.parse(atob(userEncoded)) : {}
      localStorage.setItem('admin_token', oauthToken)
      localStorage.setItem('admin_user', JSON.stringify(user))
      window.history.replaceState({}, '', window.location.pathname)

      try {
        const licenseRes = await api.get('/admin/license/status')
        if (!licenseRes.data.licensed) { router.push('/license-required'); return }
      } catch {}

      router.push('/dashboard')
      return
    } catch {
      error.value = 'OAuth login failed. Please try again.'
      window.history.replaceState({}, '', window.location.pathname)
    }
  }

  // Load enabled OAuth providers
  try {
    const res = await api.get('/oauth/providers')
    oauthProviders.value = (res.data.providers || []).map(name => ({ name }))
  } catch {}
})

const loginWithOAuth = async (provider) => {
  oauthLoading.value = provider
  try {
    const res = await api.get(`/oauth/${provider}/redirect`)
    // Full page redirect — Discord/Google will redirect back to our callback URL
    window.location.href = res.data.redirect_url
  } catch (err) {
    error.value = err.response?.data?.message || `Failed to start ${provider} login`
    oauthLoading.value = null
  }
}

const handleLogin = async () => {
  error.value = ''
  loading.value = true
  twoFactorRequired.value = false
  challengeToken.value = ''
  twoFactorCode.value = ''
  pendingUser.value = null

  try {
    const response = await api.post('/login', credentials.value)

    if (response.data.two_factor_required) {
      twoFactorRequired.value = true
      challengeToken.value = response.data.challenge_token
      pendingUser.value = response.data.user
      return
    }

    localStorage.setItem('admin_token', response.data.token)
    localStorage.setItem('admin_user', JSON.stringify(response.data.user))

    // Check license status before entering the panel
    try {
      const licenseRes = await api.get('/admin/license/status')
      if (!licenseRes.data.licensed) {
        router.push('/license-required')
        return
      }
    } catch {
      // If license check fails, let the 423 interceptor handle it
    }

    router.push('/dashboard')
  } catch (err) {
    error.value = err.response?.data?.message || 'Login failed. Please check your credentials.'
  } finally {
    loading.value = false
  }
}

const handle2faVerify = async () => {
  error.value = ''
  loading.value = true
  try {
    const response = await api.post('/2fa/verify', {
      challenge_token: challengeToken.value,
      code: twoFactorCode.value
    })
    localStorage.setItem('admin_token', response.data.token)
    localStorage.setItem('admin_user', JSON.stringify(response.data.user))

    // Check license status before entering the panel
    try {
      const licenseRes = await api.get('/admin/license/status')
      if (!licenseRes.data.licensed) {
        router.push('/license-required')
        return
      }
    } catch {
      // If license check fails, let the 423 interceptor handle it
    }

    router.push('/dashboard')
  } catch (err) {
    error.value = err.response?.data?.message || '2FA verification failed. Please try again.'
    // If too many attempts or challenge expired, reset to login
    if (err.response?.status === 403 || err.response?.status === 429) {
      twoFactorRequired.value = false
      challengeToken.value = ''
      twoFactorCode.value = ''
      pendingUser.value = null
    }
  } finally {
    loading.value = false
  }
}
</script>
