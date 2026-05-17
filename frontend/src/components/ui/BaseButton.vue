<template>
  <button
    :class="['base-button', variant, size, { loading, disabled: isDisabled }]"
    :disabled="isDisabled || loading"
    :type="type"
  >
    <span v-if="loading" class="spinner">...</span>
    <slot v-else />
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
  variant?: 'primary' | 'secondary' | 'danger' | 'ghost'
  size?: 'sm' | 'md' | 'lg'
  loading?: boolean
  disabled?: boolean
  type?: 'button' | 'submit' | 'reset'
}

const props = withDefaults(defineProps<Props>(), {
  variant: 'primary',
  size: 'md',
  loading: false,
  disabled: false,
  type: 'button',
})

const isDisabled = computed(() => props.disabled)
</script>

<style scoped>
.base-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  font-weight: 600;
  border-radius: 0.375rem;
  cursor: pointer;
  transition: all 0.2s;
  border: 1px solid transparent;
}

.base-button:focus {
  outline: none;
  box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.5);
}

.primary {
  background: linear-gradient(135deg, #00bcd4 0%, #0891b2 100%);
  color: #ffffff;
  border-color: #00bcd4;
}

.primary:hover:not(.disabled):not(.loading) {
  background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 188, 212, 0.4);
}

.secondary {
  background: rgba(30, 41, 59, 0.8);
  color: #e2e8f0;
  border-color: rgba(148, 163, 184, 0.3);
}

.secondary:hover:not(.disabled):not(.loading) {
  background: rgba(30, 41, 59, 1);
  border-color: rgba(148, 163, 184, 0.5);
}

.danger {
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  color: #ffffff;
  border-color: #ef4444;
}

.danger:hover:not(.disabled):not(.loading) {
  background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.4);
}

.ghost {
  background: transparent;
  color: #94a3b8;
  border-color: transparent;
}

.ghost:hover:not(.disabled):not(.loading) {
  background: rgba(148, 163, 184, 0.1);
  color: #e2e8f0;
}

.sm {
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
}

.md {
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
}

.lg {
  padding: 0.75rem 1.5rem;
  font-size: 1rem;
}

.disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.loading {
  cursor: wait;
}

.spinner {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}
</style>
