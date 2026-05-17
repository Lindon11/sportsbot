<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-900 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <!-- Loading State -->
      <div v-if="isValidating" class="text-center">
        <svg class="animate-spin h-10 w-10 text-amber-500 mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="mt-4 text-gray-400">Validating reset link...</p>
      </div>

      <!-- Invalid Token -->
      <div v-else-if="tokenError" class="text-center">
        <div class="rounded-md bg-red-900 p-4 mb-6">
          <div class="flex justify-center">
            <svg class="h-12 w-12 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
          </div>
          <h3 class="mt-4 text-lg font-medium text-red-400">Invalid or Expired Link</h3>
          <p class="mt-2 text-sm text-red-300">
            {{ tokenError }}
          </p>
        </div>
        <router-link
          to="/forgot-password"
          class="font-medium text-amber-500 hover:text-amber-400"
        >
          Request a new reset link
        </router-link>
      </div>

      <!-- Reset Form -->
      <template v-else>
        <div>
          <h2 class="mt-6 text-center text-3xl font-extrabold text-white">
            Reset Password
          </h2>
          <p class="mt-2 text-center text-sm text-gray-400">
            Enter your new password below.
          </p>
        </div>

        <!-- Success Message -->
        <div v-if="successMessage" class="rounded-md bg-green-900 p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-green-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-green-400">
                {{ successMessage }}
              </p>
              <p class="mt-2 text-sm text-green-300">
                Redirecting to login...
              </p>
            </div>
          </div>
        </div>

        <!-- Error Message -->
        <div v-if="errorMessage" class="rounded-md bg-red-900 p-4">
          <div class="flex">
            <div class="flex-shrink-0">
              <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
              </svg>
            </div>
            <div class="ml-3">
              <p class="text-sm font-medium text-red-400">
                {{ errorMessage }}
              </p>
            </div>
          </div>
        </div>

        <form v-if="!successMessage" class="mt-8 space-y-6" @submit.prevent="submitForm">
          <div class="space-y-4">
            <div>
              <label for="password" class="block text-sm font-medium text-gray-300">
                New Password
              </label>
              <input
                id="password"
                v-model="password"
                name="password"
                type="password"
                required
                :disabled="isLoading"
                class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-700 placeholder-gray-500 text-white bg-gray-800 rounded-md focus:outline-none focus:ring-amber-500 focus:border-amber-500 focus:z-10 sm:text-sm disabled:opacity-50"
                placeholder="Enter new password"
              />
            </div>

            <div>
              <label for="password_confirmation" class="block text-sm font-medium text-gray-300">
                Confirm New Password
              </label>
              <input
                id="password_confirmation"
                v-model="passwordConfirmation"
                name="password_confirmation"
                type="password"
                required
                :disabled="isLoading"
                class="mt-1 appearance-none relative block w-full px-3 py-2 border border-gray-700 placeholder-gray-500 text-white bg-gray-800 rounded-md focus:outline-none focus:ring-amber-500 focus:border-amber-500 focus:z-10 sm:text-sm disabled:opacity-50"
                placeholder="Confirm new password"
              />
            </div>
          </div>

          <div>
            <button
              type="submit"
              :disabled="isLoading"
              class="group relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md text-black bg-amber-500 hover:bg-amber-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span v-if="isLoading" class="absolute left-0 inset-y-0 flex items-center pl-3">
                <svg class="animate-spin h-5 w-5 text-black" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
              </span>
              {{ isLoading ? 'Resetting...' : 'Reset Password' }}
            </button>
          </div>

          <div class="text-center">
            <router-link
              to="/login"
              class="font-medium text-amber-500 hover:text-amber-400"
            >
              Back to Login
            </router-link>
          </div>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'

const route = useRoute()
const router = useRouter()

const password = ref('')
const passwordConfirmation = ref('')
const isLoading = ref(false)
const isValidating = ref(true)
const successMessage = ref('')
const errorMessage = ref('')
const tokenError = ref('')

const token = route.query.token || ''
const email = route.query.email || ''

onMounted(async () => {
  if (!token || !email) {
    tokenError.value = 'Invalid reset link. Please request a new password reset.'
    isValidating.value = false
    return
  }

  try {
    await api.post('/api/v1/validate-reset-token', { email, token })
    isValidating.value = false
  } catch (err: unknown) {
    const error = err as { response?: { data?: { message?: string } } }
    if (error.response?.data?.message) {
      tokenError.value = error.response.data.message
    } else {
      tokenError.value = 'This reset link is invalid or has expired. Please request a new one.'
    }
    isValidating.value = false
  }
})

const submitForm = async () => {
  if (password.value !== passwordConfirmation.value) {
    errorMessage.value = 'Passwords do not match.'
    return
  }

  if (password.value.length < 8) {
    errorMessage.value = 'Password must be at least 8 characters long.'
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    const response = await api.post('/api/v1/reset-password', {
      email,
      token,
      password: password.value,
      password_confirmation: passwordConfirmation.value
    })

    successMessage.value = response.data.message || 'Your password has been reset successfully!'

    // Redirect to login after 2 seconds
    setTimeout(() => {
      router.push('/login')
    }, 2000)
  } catch (err: unknown) {
    const error = err as { response?: { data?: { errors?: Record<string, string[]>; message?: string } } }
    if (error.response?.data?.errors) {
      const errors = error.response.data.errors
      errorMessage.value = Object.values(errors).flat().join(' ')
    } else if (error.response?.data?.message) {
      errorMessage.value = error.response.data.message
    } else {
      errorMessage.value = 'An error occurred. Please try again later.'
    }
  } finally {
    isLoading.value = false
  }
}
</script>
