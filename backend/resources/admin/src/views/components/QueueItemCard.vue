<template>
  <div :class="[accentBorder]" class="rounded-2xl bg-slate-800/50 border overflow-hidden hover:border-slate-500/70 transition-all duration-200 group">
    <div class="relative bg-slate-900/90 aspect-video flex items-center justify-center overflow-hidden cursor-pointer" @click="$emit('preview', item)">
      <template v-if="item.card_path && item.status === 'ready'">
        <img :src="`/admin/sportsbot/fixture-queue/${item.id}/card`" :alt="title" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" loading="lazy">
        <div class="absolute inset-0 bg-gradient-to-t from-slate-900/80 via-transparent to-transparent"></div>
        <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 transition-colors flex items-center justify-center">
          <span class="opacity-0 group-hover:opacity-100 transition-opacity px-4 py-2 rounded-xl bg-white/15 text-white backdrop-blur-sm text-sm font-medium">Click to preview</span>
        </div>
      </template>
      <template v-else>
        <div class="absolute inset-0 bg-gradient-to-br from-slate-800 to-slate-900"></div>
        <div class="absolute inset-0 opacity-[0.03]" style="background-image: radial-gradient(circle at 25% 25%, white 1px, transparent 1px); background-size: 30px 30px;"></div>
        <div class="relative text-center">
          <span class="text-5xl block mb-2">{{ emoji }}</span>
          <p class="text-slate-500 text-sm font-medium">{{ placeholderText }}</p>
          <p v-if="item.status === 'failed' && item.error" class="text-red-400/70 text-xs mt-1 max-w-[240px] mx-auto truncate">{{ item.error }}</p>
        </div>
      </template>

      <div class="absolute top-3 left-3 flex items-center gap-2">
        <span class="text-lg drop-shadow-lg">{{ emoji }}</span>
        <QueueStatusBadge :status="item.status" size="sm" />
      </div>

      <div class="absolute bottom-3 left-3 right-3 flex items-center justify-between gap-2">
        <span class="text-xs text-white/70 bg-black/40 px-2 py-1 rounded-lg backdrop-blur-sm">
          {{ publishLabel }}
        </span>
        <span v-if="item.asset_status === 'cached'" class="inline-flex items-center gap-1 text-xs text-emerald-300 bg-emerald-500/20 px-2 py-1 rounded-lg backdrop-blur-sm">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" /></svg>
          assets ready
        </span>
      </div>
    </div>

    <div class="p-4 space-y-3">
      <div class="space-y-1">
        <h3 class="text-white font-semibold text-base leading-tight truncate">{{ title }}</h3>
        <p class="text-slate-400 text-xs flex items-center gap-2">
          <span>{{ fixture.league || 'League TBC' }}</span>
          <span v-if="fixture.venue" class="text-slate-500">·</span>
          <span v-if="fixture.venue" class="truncate">{{ fixture.venue }}</span>
        </p>
      </div>

      <div class="flex items-center gap-3 text-xs text-slate-400">
        <span v-if="fixture.kickoff_label || fixture.time" class="flex items-center gap-1">
          <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ fixture.kickoff_label || fixture.time }}
        </span>
        <span v-if="fixture.tv_channel" class="flex items-center gap-1">
          <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
          {{ fixture.tv_channel }}
        </span>
      </div>

      <QueueAssetHealth :fixture-data="fixture" :asset-status="item.asset_status" :card-path="item.card_path" :sport-key="item.sport_key" />

      <div class="flex items-center gap-3 text-xs text-slate-500 pt-1">
        <span class="flex items-center gap-1">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
          {{ item.route_key || '—' }}
        </span>
        <span v-if="item.updated_at" class="flex items-center gap-1">
          <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
          {{ relativeTime(item.updated_at) }}
        </span>
        <span v-if="item.payload_hash" class="font-mono text-slate-600 truncate max-w-[80px]" :title="item.payload_hash">{{ shortHash(item.payload_hash) }}</span>
      </div>

      <div class="flex flex-wrap gap-1.5 pt-2 border-t border-slate-700/40">
        <button @click="$emit('preview', item)" class="px-3 py-1.5 rounded-lg bg-white/5 text-slate-300 hover:bg-white/10 hover:text-white text-xs font-medium transition-colors">Preview</button>
        <button @click="$emit('render', item.id)" :disabled="busy" class="px-3 py-1.5 rounded-lg bg-amber-500/10 text-amber-300 hover:bg-amber-500/20 text-xs font-medium transition-colors disabled:opacity-40">
          {{ busyId === item.id && busyAction === 'render' ? '...' : 'Render' }}
        </button>
        <button v-if="item.status === 'ready'" @click="$emit('send', item.id)" :disabled="busy" class="px-3 py-1.5 rounded-lg bg-emerald-500/10 text-emerald-300 hover:bg-emerald-500/20 text-xs font-medium transition-colors disabled:opacity-40">
          {{ busyId === item.id && busyAction === 'send' ? '...' : 'Send' }}
        </button>
        <button @click="$emit('skip', item.id)" :disabled="busy" class="px-3 py-1.5 rounded-lg bg-slate-700/30 text-slate-400 hover:text-slate-300 text-xs font-medium transition-colors disabled:opacity-40">Skip</button>
        <button @click="$emit('delete', item.id)" :disabled="busy" class="px-3 py-1.5 rounded-lg bg-red-500/10 text-red-300 hover:bg-red-500/20 text-xs font-medium transition-colors disabled:opacity-40 ml-auto">Delete</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import QueueStatusBadge from './QueueStatusBadge.vue'
import QueueAssetHealth from './QueueAssetHealth.vue'

const props = defineProps({
  item: { type: Object, required: true },
  sportConfig: { type: Object, default: () => ({}) },
  busy: { type: Boolean, default: false },
  busyId: { type: [Number, String], default: null },
  busyAction: { type: String, default: '' },
})

defineEmits(['preview', 'render', 'send', 'skip', 'delete'])

const fixture = computed(() => props.item.fixture_data || {})
const title = computed(() => {
  const d = fixture.value
  const home = d.home_team || ''
  const away = d.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return d.event_name || d.strEvent || `Event ${props.item.event_id}`
})

const emoji = computed(() => props.sportConfig?.emoji || '🏅')

const sportAccents = {
  football: 'border-emerald-700/30 hover:border-emerald-500/70',
  rugby: 'border-blue-700/30 hover:border-blue-500/70',
  fights: 'border-red-700/30 hover:border-red-500/70',
  mma: 'border-red-700/30 hover:border-red-500/70',
  boxing: 'border-red-700/30 hover:border-red-500/70',
  basketball: 'border-orange-700/30 hover:border-orange-500/70',
  baseball: 'border-blue-900/30 hover:border-blue-700/70',
  ice_hockey: 'border-cyan-700/30 hover:border-cyan-500/70',
  formula_1: 'border-purple-700/30 hover:border-purple-500/70',
  american_football: 'border-amber-700/30 hover:border-amber-500/70',
}

const accentBorder = computed(() => sportAccents[props.item.sport_key] || 'border-slate-700/50')

const placeholderText = computed(() => {
  if (props.item.status === 'failed') return 'Render failed'
  if (props.item.status === 'draft') return 'Waiting for render'
  if (props.item.status === 'skipped') return 'Skipped'
  return 'Card pending'
})

const publishLabel = computed(() => {
  const today = new Date().toISOString().slice(0, 10)
  const pd = props.item.publish_date
  if (!pd) return 'Date TBC'
  if (pd === today) return '📌 Today'
  const diff = Math.ceil((new Date(pd) - new Date(today)) / (1000 * 60 * 60 * 24))
  if (diff === 1) return 'Tomorrow'
  if (diff > 0) return `In ${diff} days`
  if (diff === 0) return 'Today'
  return `${Math.abs(diff)}d ago`
})

function relativeTime(dateStr) {
  if (!dateStr) return ''
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins = Math.floor(diff / 60000)
  if (mins < 1) return 'just now'
  if (mins < 60) return `${mins}m ago`
  const hrs = Math.floor(mins / 60)
  if (hrs < 24) return `${hrs}h ago`
  return `${Math.floor(hrs / 24)}d ago`
}

function shortHash(hash) {
  if (!hash) return ''
  return hash.slice(0, 8)
}
</script>
