<template>
  <div class="flex flex-wrap gap-1.5">
    <span v-if="hasBadges" class="inline-flex items-center gap-1 rounded-md bg-emerald-500/10 text-emerald-400 px-2 py-0.5 text-xs">
      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
      badges cached
    </span>
    <span v-if="hasVenue === false" class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 text-amber-400 px-2 py-0.5 text-xs">
      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z" /></svg>
      no venue
    </span>
    <span v-if="!hasTv" class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 text-amber-400 px-2 py-0.5 text-xs">
      <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
      no TV data
    </span>
    <span v-if="!hasPoster && !isFight" class="inline-flex items-center gap-1 rounded-md bg-amber-500/10 text-amber-400 px-2 py-0.5 text-xs">
      missing poster
    </span>
    <span v-if="!hasCard" class="inline-flex items-center gap-1 rounded-md bg-slate-600/30 text-slate-400 px-2 py-0.5 text-xs">
      no card rendered
    </span>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  fixtureData: { type: Object, default: () => ({}) },
  assetStatus: { type: String, default: '' },
  cardPath: { type: String, default: '' },
  sportKey: { type: String, default: '' },
})

const isFight = computed(() => ['fights', 'mma', 'boxing'].includes(props.sportKey))
const hasBadges = computed(() => !!(props.fixtureData?.home_badge || props.fixtureData?.away_badge || props.fixtureData?.league_badge))
const hasVenue = computed(() => !!(props.fixtureData?.venue || props.fixtureData?.strVenue))
const hasTv = computed(() => !!(props.fixtureData?.tv_channel || props.fixtureData?.strChannel))
const hasPoster = computed(() => !!(props.fixtureData?.event_poster || props.fixtureData?.event_thumb || props.fixtureData?.strThumb))
const hasCard = computed(() => !!props.cardPath)
</script>
