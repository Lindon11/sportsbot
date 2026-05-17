<template>
  <slot v-if="!hasError" />
  <div v-else class="error-boundary">
    <div class="error-content">
      <span class="error-icon">!</span>
      <h2 class="error-title">Something went wrong</h2>
      <p class="error-message">{{ errorMessage }}</p>
      <div class="error-actions">
        <button class="retry-btn" @click="retry">
          Try Again
        </button>
        <button class="home-btn" @click="goHome">
          Go Home
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onErrorCaptured } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const hasError = ref(false)
const errorMessage = ref('An unexpected error occurred')

const emit = defineEmits<{
  error: [error: Error]
}>()

onErrorCaptured((error: Error) => {
  hasError.value = true
  errorMessage.value = error.message || 'An unexpected error occurred'
  emit('error', error)
  return false
})

const retry = () => {
  hasError.value = false
  errorMessage.value = ''
}

const goHome = () => {
  hasError.value = false
  errorMessage.value = ''
  router.push('/')
}
</script>

<style scoped>
.error-boundary {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 400px;
  padding: 2rem;
}

.error-content {
  text-align: center;
  max-width: 400px;
}

.error-icon {
  font-size: 4rem;
  display: block;
  margin-bottom: 1rem;
  color: #ef4444;
}

.error-title {
  color: #e2e8f0;
  font-size: 1.5rem;
  font-weight: 700;
  margin: 0 0 0.5rem;
}

.error-message {
  color: #94a3b8;
  font-size: 0.875rem;
  margin: 0 0 1.5rem;
  line-height: 1.5;
}

.error-actions {
  display: flex;
  gap: 0.75rem;
  justify-content: center;
}

.retry-btn {
  background: linear-gradient(135deg, #00bcd4 0%, #0891b2 100%);
  border: none;
  border-radius: 0.375rem;
  padding: 0.625rem 1.25rem;
  color: #ffffff;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.retry-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 188, 212, 0.4);
}

.home-btn {
  background: rgba(30, 41, 59, 0.8);
  border: 1px solid rgba(148, 163, 184, 0.3);
  border-radius: 0.375rem;
  padding: 0.625rem 1.25rem;
  color: #e2e8f0;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.home-btn:hover {
  background: rgba(30, 41, 59, 1);
  border-color: rgba(148, 163, 184, 0.5);
}
</style>
