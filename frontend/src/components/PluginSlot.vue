<script setup lang="ts">
/**
 * PluginSlot Component
 *
 * A dynamic component injector that renders components registered to a specific slot.
 * Plugins can register Vue components to slots via the hub store, and this component
 * will dynamically render them.
 *
 * Usage:
 *   <PluginSlot slotName="dashboard-widget" />
 *   <PluginSlot slotName="header-link" />
 */

import { computed } from 'vue'
import { useHubStore } from '@/stores/hub'
import type { SlotName } from '@/types/plugin'

const props = defineProps<{
  /**
   * The name of the slot to render components for.
   * Common slots: 'dashboard-widget', 'header-link', 'sidebar-widget'
   */
  slotName: SlotName

  /**
   * Optional CSS class to apply to the container.
   */
  containerClass?: string

  /**
   * Optional wrapper tag. Defaults to 'div'.
   */
  wrapperTag?: string

  /**
   * Filter components by plugin ID.
   */
  pluginId?: string

  /**
   * Maximum number of components to render.
   */
  limit?: number
}>()

const hub = useHubStore()

// Get components for this slot
const slottedComponents = computed(() => {
  let components = hub.getComponentsForSlot(props.slotName)

  // Filter by plugin if specified
  if (props.pluginId) {
    components = components.filter(c => c.plugin_id === props.pluginId)
  }

  // Apply limit if specified
  if (props.limit && props.limit > 0) {
    components = components.slice(0, props.limit)
  }

  return components
})

// Check if we have any components to render
const hasComponents = computed(() => slottedComponents.value.length > 0)

// Resolve the wrapper tag
const wrapperElement = computed(() => props.wrapperTag || 'div')
</script>

<template>
  <component
    :is="wrapperElement"
    v-if="hasComponents"
    :class="['plugin-slot', `plugin-slot--${slotName}`, containerClass]"
    :data-slot="slotName"
  >
    <template v-for="(item, index) in slottedComponents" :key="`${item.plugin_id}-${item.component}`">
      <div
        class="plugin-slot__item"
        :data-plugin="item.plugin_id"
        :data-component="item.component"
      >
        <slot
          name="component"
          :item="item"
          :index="index"
          :plugin-id="item.plugin_id"
          :component-name="item.component"
        >
          <div class="plugin-slot__default">
            <span class="plugin-slot__plugin-name">{{ item.plugin_name }}</span>
            <span class="plugin-slot__component-name">{{ item.component }}</span>
          </div>
        </slot>
      </div>
    </template>
  </component>
</template>

<style scoped>
.plugin-slot {
  display: contents;
}

.plugin-slot__item {
  display: contents;
}

.plugin-slot__default {
  padding: 0.5rem;
  border-radius: 0.375rem;
  background-color: var(--color-surface-variant, #f3f4f6);
  border: 1px dashed var(--color-border, #e5e7eb);
  font-size: 0.875rem;
  color: var(--color-text-muted, #6b7280);
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.plugin-slot__plugin-name {
  font-weight: 600;
  color: var(--color-text, #1f2937);
}

.plugin-slot__component-name {
  font-family: monospace;
  font-size: 0.75rem;
}

/* Slot-specific styles */
.plugin-slot--dashboard-widget {
  display: grid;
  gap: 1rem;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
}

.plugin-slot--header-link {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.plugin-slot--sidebar-widget {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
</style>
