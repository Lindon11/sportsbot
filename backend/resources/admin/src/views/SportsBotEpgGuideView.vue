<template>
  <div class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot TV Guide</h1>
        <p class="mt-1 text-sm text-slate-400">{{ dateLabel }} · {{ summaryLabel }}</p>
      </div>
      <RouterLink to="/sportsbot/epg-provider" class="rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-sm font-medium text-slate-200 hover:bg-slate-800">
        EPG Provider
      </RouterLink>
    </header>

    <section class="rounded-lg border border-slate-700/60 bg-slate-800/50 p-4">
      <div class="grid grid-cols-1 gap-3 xl:grid-cols-[auto_auto_minmax(200px,1fr)_auto]">
        <div class="flex flex-wrap items-end gap-2">
          <button type="button" title="Previous day" @click="shiftDay(-1)" class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 text-slate-200 hover:bg-slate-800">
            <ChevronLeftIcon class="h-5 w-5" />
          </button>
          <label class="min-w-[170px] text-xs font-medium uppercase text-slate-400">
            Day
            <input v-model="selectedDate" type="date" @change="load" class="mt-1 h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-sky-400" />
          </label>
          <button type="button" title="Next day" @click="shiftDay(1)" class="inline-flex h-11 w-11 items-center justify-center rounded-lg border border-slate-700 bg-slate-900 text-slate-200 hover:bg-slate-800">
            <ChevronRightIcon class="h-5 w-5" />
          </button>
          <div class="flex gap-1">
            <button v-for="day in quickDays" :key="day.label" type="button" @click="pickDay(day.offset)" class="h-11 rounded-lg border px-3 text-sm font-medium" :class="dayClass(day.offset)">
              {{ day.label }}
            </button>
          </div>
        </div>

        <div class="flex flex-wrap items-end gap-2">
          <label class="min-w-[130px] text-xs font-medium uppercase text-slate-400">
            Region
            <select v-model="region" @change="load" class="mt-1 h-11 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-white outline-none focus:border-sky-400">
              <option value="ALL">All</option>
              <option v-for="item in regions" :key="item" :value="item">{{ item }}</option>
            </select>
          </label>
          <button type="button" @click="toggleSports" class="h-11 rounded-lg border px-3 text-sm font-medium" :class="ukSportsOnly ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-100' : 'border-slate-700 bg-slate-900 text-slate-200 hover:bg-slate-800'">
            UK Sports
          </button>
        </div>

        <label class="relative self-end">
          <span class="sr-only">Search programmes and channels</span>
          <MagnifyingGlassIcon class="pointer-events-none absolute left-3 top-3 h-5 w-5 text-slate-500" />
          <input v-model="search" @input="queueLoad" type="search" placeholder="Search team, programme, or channel" class="h-11 w-full rounded-lg border border-slate-700 bg-slate-950 pl-10 pr-3 text-sm text-white outline-none placeholder:text-slate-500 focus:border-sky-400" />
        </label>

        <button type="button" title="Refresh guide" :disabled="loading" @click="load" class="inline-flex h-11 items-center justify-center gap-2 rounded-lg border border-sky-500/40 bg-sky-500/10 px-4 text-sm font-medium text-sky-100 hover:bg-sky-500/20 disabled:opacity-60">
          <ArrowPathIcon class="h-5 w-5" :class="loading ? 'animate-spin' : ''" />
          Refresh
        </button>
      </div>
    </section>

    <div v-if="error" class="rounded-lg border border-red-500/40 bg-red-500/10 p-4 text-sm text-red-100">{{ error }}</div>
    <div v-else-if="guide.truncated" class="rounded-lg border border-amber-500/35 bg-amber-500/10 p-3 text-sm text-amber-100">
      The guide is showing the first {{ guide.filters?.channel_limit || 400 }} matching channels. Narrow the search or enable UK Sports to inspect a smaller slice.
    </div>

    <section class="overflow-hidden rounded-lg border border-slate-700/60 bg-slate-900/70">
      <div v-if="loading && !channels.length" class="flex h-72 items-center justify-center text-sm text-slate-400">Loading guide...</div>
      <div v-else-if="!channels.length" class="flex h-72 items-center justify-center px-6 text-center text-sm text-slate-400">No programmes found for this guide window.</div>
      <div v-else ref="guideViewport" class="guide-viewport max-h-[calc(100vh-18rem)] overflow-auto">
        <div class="relative" :style="guideStyle">
          <div class="guide-header sticky top-0 z-30 flex border-b border-slate-700 bg-slate-950/95 backdrop-blur">
            <div class="channel-rail sticky left-0 z-40 flex shrink-0 items-center border-r border-slate-700 bg-slate-950 px-4 text-xs font-semibold uppercase text-slate-400">
              Channels
            </div>
            <div class="relative flex shrink-0" :style="{ width: `${timelineWidth}px` }">
              <div v-for="slot in slots" :key="slot.index" class="slot-header shrink-0 border-r border-slate-800 px-2 py-3 text-xs" :style="{ width: `${slotWidth}px` }">
                <span :class="slot.index % 2 === 0 ? 'text-slate-200' : 'text-slate-500'">{{ slot.label }}</span>
              </div>
            </div>
          </div>

          <div v-for="channel in channels" :key="channel.canonical_channel_id" class="guide-row flex border-b border-slate-800/90" :style="{ height: `${rowHeight}px` }">
            <div class="channel-rail sticky left-0 z-20 flex shrink-0 items-center gap-3 border-r border-slate-700 bg-slate-950/95 px-3">
              <div class="relative flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded border border-slate-700 bg-slate-800 text-xs font-bold text-sky-200">
                <span>{{ initials(channel.name) }}</span>
                <img v-if="channel.logo_url" :src="channel.logo_url" alt="" class="absolute inset-0 h-full w-full bg-slate-950 object-contain p-1" @error="hideBrokenLogo" />
              </div>
              <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ channel.name }}</p>
                <p class="truncate text-xs text-slate-500">{{ channel.programme_count }} programmes</p>
              </div>
            </div>
            <div class="relative shrink-0 overflow-hidden" :style="{ width: `${timelineWidth}px` }">
              <div class="pointer-events-none absolute inset-0 flex">
                <div v-for="slot in slots" :key="`${channel.canonical_channel_id}-${slot.index}`" class="h-full shrink-0 border-r" :class="slot.index % 2 === 0 ? 'border-slate-800 bg-slate-900/30' : 'border-slate-800/70 bg-slate-950/20'" :style="{ width: `${slotWidth}px` }"></div>
              </div>
              <button
                v-for="programme in channel.programmes"
                :key="`${channel.canonical_channel_id}-${programme.id}-${programme.start_time}`"
                type="button"
                :title="programme.title"
                @click="selectedProgramme = { ...programme, channel_name: channel.name }"
                class="programme absolute top-2 z-10 overflow-hidden rounded border border-sky-400/30 bg-sky-500/15 px-2 py-1.5 text-left shadow-sm shadow-black/20 outline-none hover:border-sky-300/70 hover:bg-sky-500/25 focus:border-sky-200"
                :style="programmeStyle(programme)"
              >
                <p class="truncate text-xs font-semibold text-white">{{ programme.title }}</p>
                <p class="mt-0.5 truncate text-[11px] text-sky-100/80">{{ programmeRange(programme) }}</p>
              </button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <div v-if="selectedProgramme" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/80 p-4" @click.self="selectedProgramme = null">
      <section class="w-full max-w-xl rounded-lg border border-slate-700 bg-slate-900 p-5 shadow-2xl">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-xs font-medium uppercase text-sky-300">{{ selectedProgramme.channel_name }}</p>
            <h2 class="mt-1 text-xl font-bold text-white">{{ selectedProgramme.title }}</h2>
          </div>
          <button type="button" title="Close" @click="selectedProgramme = null" class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-slate-700 bg-slate-950 text-slate-300 hover:bg-slate-800">
            <XMarkIcon class="h-5 w-5" />
          </button>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
          <div class="rounded border border-slate-700 bg-slate-950/70 p-3">
            <p class="text-xs uppercase text-slate-500">Start</p>
            <p class="mt-1 text-slate-100">{{ detailTime(selectedProgramme.start_time) }}</p>
          </div>
          <div class="rounded border border-slate-700 bg-slate-950/70 p-3">
            <p class="text-xs uppercase text-slate-500">End</p>
            <p class="mt-1 text-slate-100">{{ detailTime(selectedProgramme.end_time) }}</p>
          </div>
          <div class="rounded border border-slate-700 bg-slate-950/70 p-3">
            <p class="text-xs uppercase text-slate-500">Sources</p>
            <p class="mt-1 text-slate-100">{{ selectedProgramme.source_count || 1 }}</p>
          </div>
        </div>
        <p v-if="selectedProgramme.description" class="mt-4 whitespace-pre-line text-sm text-slate-300">{{ selectedProgramme.description }}</p>
        <p v-else class="mt-4 text-sm text-slate-500">No programme description supplied.</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { ArrowPathIcon, ChevronLeftIcon, ChevronRightIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import api from '@/services/api'

const slotWidth = 88
const rowHeight = 86
const railWidth = 260
const timelineWidth = slotWidth * 48
const quickDays = [
  { label: 'Yesterday', offset: -1 },
  { label: 'Today', offset: 0 },
  { label: 'Tomorrow', offset: 1 },
]

const selectedDate = ref(dateInput(new Date()))
const region = ref('UK')
const ukSportsOnly = ref(true)
const search = ref('')
const loading = ref(false)
const error = ref('')
const guide = ref({})
const selectedProgramme = ref(null)
let searchTimer = null

const channels = computed(() => guide.value.channels || [])
const regions = computed(() => Array.from(new Set(['UK', ...(guide.value.regions || [])])))
const slots = computed(() => Array.from({ length: 48 }, (_, index) => ({
  index,
  label: slotLabel(index),
})))
const dateLabel = computed(() => new Date(`${selectedDate.value}T12:00:00`).toLocaleDateString(undefined, {
  weekday: 'long',
  day: 'numeric',
  month: 'short',
  year: 'numeric',
}))
const summaryLabel = computed(() => `${guide.value.channel_count || 0} channels · ${guide.value.programme_count || 0} programmes`)
const guideStyle = computed(() => ({
  minWidth: `${railWidth + timelineWidth}px`,
}))

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params = {
      date: selectedDate.value,
      region: region.value,
      uk_sports: ukSportsOnly.value ? 1 : 0,
      search: search.value.trim() || undefined,
    }
    const { data } = await api.get('/admin/sportsbot/epg-provider/guide', { params })
    guide.value = data || {}
  } catch (err) {
    error.value = err.response?.data?.error || err.message || 'Failed to load TV guide'
  } finally {
    loading.value = false
  }
}

function queueLoad() {
  if (searchTimer) {
    window.clearTimeout(searchTimer)
  }
  searchTimer = window.setTimeout(load, 280)
}

function shiftDay(offset) {
  const day = new Date(`${selectedDate.value}T12:00:00`)
  day.setDate(day.getDate() + offset)
  selectedDate.value = dateInput(day)
  load()
}

function pickDay(offset) {
  const day = new Date()
  day.setDate(day.getDate() + offset)
  selectedDate.value = dateInput(day)
  load()
}

function toggleSports() {
  ukSportsOnly.value = !ukSportsOnly.value
  load()
}

function dayClass(offset) {
  const day = new Date()
  day.setDate(day.getDate() + offset)
  return selectedDate.value === dateInput(day)
    ? 'border-sky-500/50 bg-sky-500/15 text-sky-100'
    : 'border-slate-700 bg-slate-900 text-slate-300 hover:bg-slate-800'
}

function slotLabel(index) {
  const hours = String(Math.floor(index / 2)).padStart(2, '0')
  const minutes = index % 2 === 0 ? '00' : '30'
  return `${hours}:${minutes}`
}

function programmeStyle(programme) {
  const start = minuteOfDay(programme.start_time, 0)
  const end = Math.max(start + 15, minuteOfDay(programme.end_time, start + 30))
  const left = Math.max(0, Math.min(timelineWidth - 64, (start / 30) * slotWidth))
  const width = Math.max(64, Math.min(timelineWidth - left - 4, ((Math.min(1440, end) - Math.max(0, start)) / 30) * slotWidth - 5))

  return {
    left: `${left}px`,
    width: `${width}px`,
    height: `${rowHeight - 16}px`,
  }
}

function minuteOfDay(value, fallback) {
  if (!value) return fallback
  const dayStart = new Date(`${selectedDate.value}T00:00:00`)
  const date = new Date(value)
  const minutes = Math.round((date.getTime() - dayStart.getTime()) / 60000)
  if (!Number.isFinite(minutes)) return fallback
  return Math.max(0, Math.min(1440, minutes))
}

function programmeRange(programme) {
  return `${clock(programme.start_time)} - ${clock(programme.end_time)}`
}

function clock(value) {
  if (!value) return '...'
  return new Date(value).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

function detailTime(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString([], {
    weekday: 'short',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function initials(value) {
  return String(value || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 3)
    .map(word => word[0])
    .join('')
    .toUpperCase()
}

function hideBrokenLogo(event) {
  event.currentTarget.style.display = 'none'
}

function dateInput(date) {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

onMounted(load)
onBeforeUnmount(() => {
  if (searchTimer) {
    window.clearTimeout(searchTimer)
  }
})
</script>

<style scoped>
.channel-rail {
  width: 260px;
}

.guide-header {
  height: 52px;
}

.programme {
  line-height: 1.25;
}
</style>
