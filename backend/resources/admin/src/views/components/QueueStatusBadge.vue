<template>
  <span :class="[badgeClass, sizeClass]" class="inline-flex items-center gap-1.5 rounded-lg font-semibold leading-none">
    <span :class="dotClass" class="w-1.5 h-1.5 rounded-full inline-block"></span>
    {{ label }}
  </span>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  status: { type: String, default: 'draft' },
  size: { type: String, default: 'sm' },
})

const colors = {
  draft: { bg: 'bg-amber-500/15 text-amber-300', dot: 'bg-amber-400' },
  ready: { bg: 'bg-emerald-500/15 text-emerald-300', dot: 'bg-emerald-400' },
  sent: { bg: 'bg-sky-500/15 text-sky-300', dot: 'bg-sky-400' },
  failed: { bg: 'bg-red-500/15 text-red-300', dot: 'bg-red-400' },
  skipped: { bg: 'bg-slate-600/30 text-slate-300', dot: 'bg-slate-400' },
}

const badgeClass = computed(() => colors[props.status]?.bg ?? 'bg-slate-700/50 text-slate-300')
const dotClass = computed(() => colors[props.status]?.dot ?? 'bg-slate-400')
const sizeClass = computed(() => props.size === 'lg' ? 'px-3 py-1.5 text-sm' : 'px-2 py-1 text-xs')

const label = computed(() => props.status.charAt(0).toUpperCase() + props.status.slice(1))
</script>
