<template>
  <div :class="containerClass" class="rounded-xl border p-3">
    <div class="flex items-start justify-between gap-3">
      <div>
        <p class="text-xs font-semibold uppercase tracking-wider" :class="headingClass">Readiness</p>
        <p v-if="mainBlocker" class="mt-1 text-xs" :class="blockerClass">{{ mainBlocker.message }}</p>
      </div>
      <span class="shrink-0 rounded-lg px-2 py-1 text-[11px] font-bold uppercase tracking-wide" :class="summaryClass">
        {{ summaryLabel }}
      </span>
    </div>

    <div class="mt-3 grid gap-2" :class="compact ? 'grid-cols-1' : 'grid-cols-1 sm:grid-cols-2'">
      <div v-for="check in checks" :key="check.key" class="flex items-center justify-between gap-2 rounded-lg bg-slate-950/45 px-2.5 py-2">
        <span class="flex min-w-0 items-center gap-2 text-xs text-slate-300">
          <span class="h-2 w-2 shrink-0 rounded-full" :class="dotClass(check.state)"></span>
          <span class="truncate">{{ check.label }}</span>
        </span>
        <span class="max-w-[52%] truncate text-right text-[11px]" :class="valueClass(check.state)" :title="check.value">
          {{ check.value }}
        </span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  item: { type: Object, required: true },
  compact: { type: Boolean, default: false },
})

const fixture = computed(() => props.item.fixture_data || {})
const payload = computed(() => props.item.payload || {})
const scraper = computed(() => payload.value.scraper || {})
const title = computed(() => {
  const home = fixture.value.home_team || ''
  const away = fixture.value.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return fixture.value.event_name || fixture.value.strEvent || props.item.event_id || ''
})
const hasTv = computed(() => {
  const channels = fixture.value.tv_channels
  return Boolean(
    fixture.value.tv_channel
      || fixture.value.strChannel
      || (Array.isArray(channels) && channels.length)
  )
})
const hasAssets = computed(() => {
  if (props.item.asset_status === 'cached') return true
  return Boolean(
    fixture.value.home_badge
      || fixture.value.away_badge
      || fixture.value.league_badge
      || fixture.value.event_poster
      || fixture.value.event_thumb
      || fixture.value.strThumb
  )
})
const hasCard = computed(() => Boolean(props.item.card_path))
const hasRoute = computed(() => Boolean(props.item.route_key))
const isSent = computed(() => Boolean(props.item.sent_at || props.item.telegram_message_id || props.item.status === 'sent'))

const fallbackChecks = computed(() => [
  {
    key: 'fixture',
    label: 'Fixture data',
    state: title.value ? 'ok' : 'error',
    value: title.value ? 'ready' : 'missing',
    message: 'Fixture title data is missing.',
  },
  {
    key: 'tv',
    label: 'TV data',
    state: hasTv.value ? 'ok' : 'warn',
    value: hasTv.value ? 'available' : 'missing',
    message: 'TV data is missing.',
  },
  {
    key: 'assets',
    label: 'Assets',
    state: props.item.asset_failures?.length ? 'error' : (hasAssets.value ? 'ok' : 'warn'),
    value: props.item.asset_failures?.length ? 'failed' : (hasAssets.value ? 'ready' : 'pending'),
    message: props.item.asset_failures?.length ? 'One or more assets failed to cache.' : 'Artwork assets are still pending.',
  },
  {
    key: 'card',
    label: 'Card render',
    state: props.item.status === 'failed' ? 'error' : (hasCard.value ? 'ok' : 'warn'),
    value: props.item.status === 'failed' ? 'failed' : (hasCard.value ? 'rendered' : 'missing'),
    message: props.item.status === 'failed' ? (props.item.error || 'Card render failed.') : 'No card has been rendered yet.',
  },
  {
    key: 'route',
    label: 'Route',
    state: hasRoute.value ? 'ok' : 'warn',
    value: hasRoute.value ? props.item.route_key : 'not set',
    message: 'No route key is set for this queue item.',
  },
  {
    key: 'publish',
    label: 'Publish',
    state: props.item.status === 'failed' ? 'error' : (isSent.value || props.item.status === 'ready' ? 'ok' : 'warn'),
    value: isSent.value ? 'sent' : (props.item.status === 'ready' ? 'ready' : props.item.status || 'draft'),
    message: props.item.status === 'failed' ? 'Publishing is blocked until the failure is fixed.' : 'Item is not ready to publish yet.',
  },
])

const checks = computed(() => {
  return Array.isArray(props.item.readiness_checks) && props.item.readiness_checks.length
    ? props.item.readiness_checks
    : fallbackChecks.value
})

const mainBlocker = computed(() => {
  if (props.item.main_blocker) {
    return { state: hasErrors.value ? 'error' : 'warn', message: props.item.main_blocker }
  }

  return checks.value.find(check => check.state === 'error') || checks.value.find(check => check.state === 'warn') || null
})
const hasErrors = computed(() => checks.value.some(check => check.state === 'error'))
const hasWarnings = computed(() => checks.value.some(check => check.state === 'warn'))
const summaryLabel = computed(() => {
  if (hasErrors.value) return 'Blocked'
  if (hasWarnings.value) return 'Needs attention'
  return scraper.value.status === 'found' ? 'Review scrape' : 'Ready'
})

const containerClass = computed(() => {
  if (hasErrors.value) return 'border-red-500/30 bg-red-500/10'
  if (hasWarnings.value || scraper.value.status === 'found') return 'border-amber-500/30 bg-amber-500/10'
  return 'border-emerald-500/30 bg-emerald-500/10'
})
const headingClass = computed(() => {
  if (hasErrors.value) return 'text-red-200'
  if (hasWarnings.value || scraper.value.status === 'found') return 'text-amber-200'
  return 'text-emerald-200'
})
const blockerClass = computed(() => {
  if (hasErrors.value) return 'text-red-100/90'
  if (hasWarnings.value || scraper.value.status === 'found') return 'text-amber-100/90'
  return 'text-emerald-100/90'
})
const summaryClass = computed(() => {
  if (hasErrors.value) return 'bg-red-300 text-red-950'
  if (hasWarnings.value || scraper.value.status === 'found') return 'bg-amber-300 text-amber-950'
  return 'bg-emerald-300 text-emerald-950'
})

function dotClass(state) {
  if (state === 'ok') return 'bg-emerald-400'
  if (state === 'error') return 'bg-red-400'
  return 'bg-amber-400'
}

function valueClass(state) {
  if (state === 'ok') return 'text-emerald-200'
  if (state === 'error') return 'text-red-200'
  return 'text-amber-200'
}
</script>
