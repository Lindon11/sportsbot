<template>
  <div class="base-input-wrapper">
    <label v-if="label" :for="inputId" class="input-label">
      {{ label }}
      <span v-if="required" class="required">*</span>
    </label>
    <div class="input-container">
      <span v-if="$slots.prefix" class="input-prefix">
        <slot name="prefix" />
      </span>
      <input
        :id="inputId"
        v-model="inputValue"
        :type="type"
        :placeholder="placeholder"
        :disabled="disabled"
        :readonly="readonly"
        :class="['base-input', { 'has-error': error, 'has-prefix': $slots.prefix, 'has-suffix': $slots.suffix }]"
        @focus="emit('focus', $event)"
        @blur="emit('blur', $event)"
      />
      <span v-if="$slots.suffix" class="input-suffix">
        <slot name="suffix" />
      </span>
    </div>
    <p v-if="error" class="input-error">{{ error }}</p>
    <p v-else-if="hint" class="input-hint">{{ hint }}</p>
  </div>
</template>

<script setup lang="ts">
import { computed, useId } from 'vue'

interface Props {
  modelValue?: string | number
  label?: string
  type?: 'text' | 'password' | 'email' | 'number' | 'search' | 'url'
  placeholder?: string
  disabled?: boolean
  readonly?: boolean
  required?: boolean
  error?: string
  hint?: string
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: '',
  label: '',
  type: 'text',
  placeholder: '',
  disabled: false,
  readonly: false,
  required: false,
  error: '',
  hint: '',
})

const emit = defineEmits<{
  'update:modelValue': [value: string | number]
  'focus': [event: FocusEvent]
  'blur': [event: FocusEvent]
}>()

const inputId = useId()

const inputValue = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})
</script>

<style scoped>
.base-input-wrapper {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.input-label {
  font-size: 0.875rem;
  font-weight: 600;
  color: #cbd5e1;
}

.required {
  color: #ef4444;
  margin-left: 0.25rem;
}

.input-container {
  position: relative;
  display: flex;
  align-items: center;
}

.base-input {
  width: 100%;
  background: rgba(30, 41, 59, 0.5);
  border: 1px solid rgba(148, 163, 184, 0.2);
  border-radius: 0.375rem;
  padding: 0.625rem 0.875rem;
  color: #e2e8f0;
  font-size: 0.875rem;
  transition: all 0.2s;
}

.base-input::placeholder {
  color: #64748b;
}

.base-input:hover:not(:disabled):not(:read-only) {
  border-color: rgba(148, 163, 184, 0.4);
}

.base-input:focus {
  outline: none;
  border-color: #00bcd4;
  box-shadow: 0 0 0 2px rgba(0, 188, 212, 0.2);
}

.base-input:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.base-input:read-only {
  background: rgba(30, 41, 59, 0.3);
}

.base-input.has-error {
  border-color: #ef4444;
}

.base-input.has-error:focus {
  box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.2);
}

.base-input.has-prefix {
  padding-left: 2.5rem;
}

.base-input.has-suffix {
  padding-right: 2.5rem;
}

.input-prefix,
.input-suffix {
  position: absolute;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  font-size: 0.875rem;
}

.input-prefix {
  left: 0.875rem;
}

.input-suffix {
  right: 0.875rem;
}

.input-error {
  font-size: 0.75rem;
  color: #ef4444;
  margin: 0;
}

.input-hint {
  font-size: 0.75rem;
  color: #64748b;
  margin: 0;
}
</style>
