<template>
  <div :class="['loading-spinner', size]">
    <div class="spinner-ring"></div>
    <span v-if="text" class="spinner-text">{{ text }}</span>
  </div>
</template>

<script setup lang="ts">
interface Props {
  size?: 'sm' | 'md' | 'lg'
  text?: string
}

withDefaults(defineProps<Props>(), {
  size: 'md',
  text: '',
})
</script>

<style scoped>
.loading-spinner {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.75rem;
}

.spinner-ring {
  border-radius: 50%;
  border: 3px solid rgba(0, 188, 212, 0.2);
  border-top-color: #00bcd4;
  animation: spin 0.8s linear infinite;
}

.sm .spinner-ring {
  width: 1.25rem;
  height: 1.25rem;
  border-width: 2px;
}

.md .spinner-ring {
  width: 2rem;
  height: 2rem;
}

.lg .spinner-ring {
  width: 3rem;
  height: 3rem;
  border-width: 4px;
}

.spinner-text {
  color: #94a3b8;
  font-size: 0.875rem;
  font-weight: 500;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
