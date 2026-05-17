<template>
  <teleport to="body">
    <div v-if="item" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4" @click.self="$emit('close')">
      <div class="bg-slate-900 border border-slate-700 rounded-2xl max-w-lg w-full max-h-[95vh] overflow-y-auto shadow-2xl">
        <div class="sticky top-0 z-10 bg-slate-900/95 backdrop-blur-sm flex items-center justify-between p-4 border-b border-slate-700">
          <div class="flex items-center gap-2.5">
            <span class="text-2xl">{{ emoji }}</span>
            <div>
              <h2 class="text-base font-bold text-white leading-tight">{{ title }}</h2>
              <p class="text-xs text-slate-400">{{ item.sport_key.toUpperCase() }} · {{ item.route_key || 'No route' }}</p>
            </div>
          </div>
          <button @click="$emit('close')" class="p-2 rounded-xl hover:bg-slate-700 text-slate-400 hover:text-white transition-colors">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
          </button>
        </div>

        <div class="p-4 space-y-4">
          <div class="rounded-xl overflow-hidden bg-slate-800 border border-slate-700" style="aspect-ratio: 16/9;">
            <template v-if="item.card_path && item.status === 'ready'">
              <img :src="`${cardBase}/admin/sportsbot/fixture-queue/${item.id}/card`" :alt="title" class="w-full h-full object-contain bg-slate-900">
            </template>
            <template v-else>
              <div class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-br from-slate-800 to-slate-900">
                <span class="text-5xl mb-3">{{ emoji }}</span>
                <p class="text-slate-500 text-sm font-medium">{{ placeholderText }}</p>
                <p v-if="item.error" class="text-red-400/70 text-xs mt-2 max-w-[280px] text-center">{{ item.error }}</p>
              </div>
            </template>
          </div>

          <div class="rounded-xl bg-slate-800/60 border border-slate-700/50 p-4 space-y-2">
            <p class="text-white font-semibold text-sm">{{ title }}</p>
            <p class="text-slate-400 text-xs">{{ fixture.league || 'League TBC' }}</p>
            <div v-if="fixture.kickoff_label || fixture.time || fixture.tv_channel" class="flex flex-wrap gap-3 text-xs text-slate-400 pt-1 border-t border-slate-700/30">
              <span v-if="fixture.kickoff_label || fixture.time" class="flex items-center gap-1">
                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                {{ fixture.kickoff_label || fixture.time }}
              </span>
              <span v-if="fixture.tv_channel" class="flex items-center gap-1">
                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" /></svg>
                {{ fixture.tv_channel }}
              </span>
              <span v-if="fixture.venue" class="flex items-center gap-1">
                <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                {{ fixture.venue }}
              </span>
            </div>
          </div>

          <details class="rounded-xl bg-slate-800/30 border border-slate-700/30">
            <summary class="px-4 py-3 text-xs font-medium text-slate-400 cursor-pointer hover:text-slate-300 select-none flex items-center gap-2">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
              Pipeline metadata
            </summary>
            <div class="px-4 pb-4 grid grid-cols-2 gap-3 text-xs">
              <div>
                <p class="text-slate-500">Status</p>
                <p class="text-white font-medium">{{ item.status }}</p>
              </div>
              <div>
                <p class="text-slate-500">Publish Date</p>
                <p class="text-white">{{ item.publish_date }}</p>
              </div>
              <div>
                <p class="text-slate-500">Route Key</p>
                <p class="text-white font-mono text-[11px]">{{ item.route_key || '—' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Asset Status</p>
                <p class="text-white">{{ item.asset_status || 'pending' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Card Path</p>
                <p class="text-white font-mono text-[11px] truncate">{{ item.card_path || 'not rendered' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Updated</p>
                <p class="text-white">{{ formatDate(item.updated_at) }}</p>
              </div>
              <div v-if="item.last_refreshed_at" class="col-span-2">
                <p class="text-slate-500">Last Refreshed</p>
                <p class="text-white">{{ formatDate(item.last_refreshed_at) }}</p>
              </div>
              <div v-if="item.telegram_message_id" class="col-span-2">
                <p class="text-slate-500">Telegram Message ID</p>
                <p class="text-white font-mono text-[11px]">{{ item.telegram_message_id }}</p>
              </div>
            </div>
          </details>

          <details v-if="item.caption" class="rounded-xl bg-slate-800/30 border border-slate-700/30">
            <summary class="px-4 py-3 text-xs font-medium text-slate-400 cursor-pointer hover:text-slate-300 select-none flex items-center gap-2">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" /></svg>
              Caption preview
            </summary>
            <div class="px-4 pb-4">
              <div class="bg-slate-900 rounded-lg p-3 text-white/80 text-xs whitespace-pre-wrap font-mono leading-relaxed">{{ item.caption }}</div>
            </div>
          </details>

          <details v-if="item.payload_hash" class="rounded-xl bg-slate-800/30 border border-slate-700/30">
            <summary class="px-4 py-3 text-xs font-medium text-slate-400 cursor-pointer hover:text-slate-300 select-none flex items-center gap-2">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" /></svg>
              Payload hash
            </summary>
            <div class="px-4 pb-4">
              <p class="text-white font-mono text-[11px] break-all bg-slate-900 rounded p-2">{{ item.payload_hash }}</p>
            </div>
          </details>

          <QueueAssetHealth :fixture-data="fixture" :asset-status="item.asset_status" :card-path="item.card_path" :sport-key="item.sport_key" />
        </div>

        <div class="sticky bottom-0 bg-slate-900/95 backdrop-blur-sm flex items-center gap-2 p-4 border-t border-slate-700">
          <button @click="$emit('render', item.id)" class="px-4 py-2 rounded-xl bg-amber-600 text-white hover:bg-amber-500 text-sm font-medium transition-colors">Re-Render</button>
          <button v-if="item.status === 'ready'" @click="$emit('send', item.id)" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 text-sm font-medium transition-colors">Publish Now</button>
          <button @click="$emit('close')" class="px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 text-sm font-medium transition-colors ml-auto">Close</button>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { computed } from 'vue'
import api from '@/services/api'
import QueueAssetHealth from './QueueAssetHealth.vue'

const cardBase = api.defaults.baseURL || '/api/v1'

const props = defineProps({
  item: { type: Object, default: null },
  sportConfigs: { type: Object, default: () => ({}) },
})

defineEmits(['close', 'render', 'send'])

const fixture = computed(() => props.item?.fixture_data || {})
const title = computed(() => {
  const d = fixture.value
  const home = d.home_team || ''
  const away = d.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return d.event_name || d.strEvent || `Event ${props.item.event_id}`
})
const emoji = computed(() => props.sportConfigs[props.item?.sport_key]?.emoji || '🏅')

const placeholderText = computed(() => {
  if (!props.item) return ''
  if (props.item.status === 'failed') return 'Render failed'
  if (props.item.status === 'draft') return 'Waiting for render'
  return 'Card not available'
})

function formatDate(value) {
  if (!value) return '-'
  return new Date(value).toLocaleString()
}
</script>
