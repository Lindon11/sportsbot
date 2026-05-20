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
            <template v-if="item.card_path && ['ready', 'sent'].includes(item.status)">
              <img :src="`/sportsbot/fixture-queue/${item.id}/card`" :alt="title" class="w-full h-full object-contain bg-slate-900">
            </template>
            <template v-else>
              <div class="w-full h-full flex flex-col items-center justify-center bg-gradient-to-br from-slate-800 to-slate-900">
                <span class="text-5xl mb-3">{{ emoji }}</span>
                <p class="text-slate-500 text-sm font-medium">{{ placeholderText }}</p>
                <p v-if="item.error" class="text-red-400/70 text-xs mt-2 max-w-[280px] text-center">{{ item.error }}</p>
              </div>
            </template>
          </div>

          <div :class="proofBannerClass" class="rounded-xl border p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <p class="text-sm font-semibold">{{ proofTitle }}</p>
                <p class="mt-1 text-xs opacity-80">
                  Renderer: <strong>{{ renderProof.renderer_used || item.renderer_used || '-' }}</strong>
                  · Card: <strong>{{ renderProof.actual_card_version || item.card_version || '-' }}</strong>
                  · File: <strong class="font-mono">{{ renderProof.file_name || fileName || '-' }}</strong>
                </p>
              </div>
              <span :class="proofPillClass" class="self-start rounded-lg px-3 py-1 text-xs font-bold uppercase tracking-wide">
                {{ proofPill }}
              </span>
            </div>
            <p v-if="renderProof.fallback_reason" class="mt-2 text-xs text-amber-100">
              Fallback reason: {{ renderProof.fallback_reason }}
            </p>
            <p v-if="renderProof.browser_failure_reason" class="mt-1 text-xs text-red-100">
              Browser failure: {{ renderProof.browser_failure_reason }}
            </p>
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

          <QueueReadinessChecklist :item="item" />

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
                <p class="text-slate-500">Card Version</p>
                <p class="text-white font-semibold">{{ item.card_version || renderProof.actual_card_version || '-' }}</p>
              </div>
              <div>
                <p class="text-slate-500">V3 Proof</p>
                <p :class="isBrowserV3 ? 'text-emerald-300' : 'text-amber-300'" class="font-semibold">{{ proofTitle }}</p>
              </div>
              <div>
                <p class="text-slate-500">Card Path</p>
                <p class="text-white font-mono text-[11px] truncate">{{ item.card_path || 'not rendered' }}</p>
              </div>
              <div>
                <p class="text-slate-500">File Name</p>
                <p class="text-white font-mono text-[11px] truncate">{{ renderProof.file_name || fileName || '-' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Renderer</p>
                <p class="text-white">{{ item.renderer_used || '-' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Duration</p>
                <p class="text-white">{{ item.render_duration_ms ? `${item.render_duration_ms}ms` : '-' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Template</p>
                <p class="text-white">{{ item.template_used || '-' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Theme</p>
                <p class="text-white">{{ item.theme_used || '-' }}</p>
              </div>
              <div>
                <p class="text-slate-500">Browser v3 File</p>
                <p :class="renderProof.file_indicates_browser_v3 ? 'text-emerald-300' : 'text-amber-300'">
                  {{ renderProof.file_indicates_browser_v3 ? 'yes' : 'no' }}
                </p>
              </div>
              <div v-if="item.fallback_reason" class="col-span-2">
                <p class="text-slate-500">Fallback Reason</p>
                <p class="text-amber-300 break-words">{{ item.fallback_reason }}</p>
              </div>
              <div v-if="item.browser_failure_reason" class="col-span-2">
                <p class="text-slate-500">Browser Failure</p>
                <p class="text-red-300 break-words">{{ item.browser_failure_reason }}</p>
              </div>
              <div v-if="assetFailures.length" class="col-span-2">
                <p class="text-slate-500">Asset Failures</p>
                <div class="mt-1 bg-slate-950/70 rounded-lg p-2 space-y-1">
                  <p v-for="failure in assetFailures" :key="`${failure.field}-${failure.source_url}`" class="text-red-200 break-words">
                    {{ failure.field }}: {{ failure.reason }}
                  </p>
                </div>
              </div>
              <div v-if="Object.keys(renderDiagnostics).length" class="col-span-2">
                <p class="text-slate-500">Render Diagnostics</p>
                <pre class="mt-1 bg-slate-950/70 rounded-lg p-2 text-[11px] text-slate-300 overflow-x-auto">{{ JSON.stringify(renderDiagnostics, null, 2) }}</pre>
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

          <details class="rounded-xl bg-slate-800/30 border border-slate-700/30">
            <summary class="px-4 py-3 text-xs font-medium text-slate-400 cursor-pointer hover:text-slate-300 select-none flex items-center gap-2">
              Render controls
            </summary>
            <div class="px-4 pb-4 space-y-3 text-xs">
              <div class="grid grid-cols-2 gap-3">
                <label>
                  <span class="block text-slate-500 mb-1">Template</span>
                  <select v-model="renderForm.template" class="w-full rounded-lg bg-slate-950 border border-slate-700 text-white px-3 py-2">
                    <option value="">Default</option>
                    <option v-for="template in templateNames" :key="template" :value="template">{{ template }}</option>
                  </select>
                </label>
                <label>
                  <span class="block text-slate-500 mb-1">Theme</span>
                  <select v-model="renderForm.theme" class="w-full rounded-lg bg-slate-950 border border-slate-700 text-white px-3 py-2">
                    <option value="">Default</option>
                    <option v-for="theme in themeNames" :key="theme" :value="theme">{{ theme }}</option>
                  </select>
                </label>
              </div>
              <label class="block">
                <span class="block text-slate-500 mb-1">Manual text override</span>
                <textarea v-model="renderForm.manual_text" rows="2" class="w-full rounded-lg bg-slate-950 border border-slate-700 text-white px-3 py-2"></textarea>
              </label>
              <label class="block">
                <span class="block text-slate-500 mb-1">Custom poster URL</span>
                <input v-model="renderForm.custom_poster_url" class="w-full rounded-lg bg-slate-950 border border-slate-700 text-white px-3 py-2">
              </label>
              <label class="block">
                <span class="block text-slate-500 mb-1">Custom background URL</span>
                <input v-model="renderForm.custom_background_url" class="w-full rounded-lg bg-slate-950 border border-slate-700 text-white px-3 py-2">
              </label>
              <button @click="$emit('save-render-options', item.id, { ...renderForm })" class="px-3 py-2 rounded-lg bg-amber-600 text-white hover:bg-amber-500 text-xs font-medium">Save and Rerender</button>
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

          <details v-if="scraper" open class="rounded-xl bg-slate-800/30 border border-slate-700/30">
            <summary class="px-4 py-3 text-xs font-medium text-slate-400 cursor-pointer hover:text-slate-300 select-none flex items-center gap-2">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35m1.35-5.65a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
              Scraper enrichment
            </summary>
            <div class="px-4 pb-4 space-y-3 text-xs">
              <div class="grid grid-cols-2 gap-3">
                <div>
                  <p class="text-slate-500">Status</p>
                  <p class="text-white">{{ scraper.status || '-' }}</p>
                </div>
                <div>
                  <p class="text-slate-500">Confidence</p>
                  <p class="text-white">{{ scraperConfidence }}</p>
                </div>
                <div class="col-span-2">
                  <p class="text-slate-500">Last Checked</p>
                  <p class="text-white">{{ formatDate(scraper.last_checked_at) }}</p>
                </div>
              </div>

              <div v-if="Object.keys(scrapedFields).length" class="bg-slate-900 rounded-lg p-3 space-y-1">
                <p class="text-slate-400 font-medium mb-2">Fields Found</p>
                <div v-for="(value, key) in scrapedFields" :key="key" class="grid grid-cols-3 gap-2">
                  <span class="text-slate-500">{{ key }}</span>
                  <span class="col-span-2 text-white break-words">{{ printable(value) }}</span>
                </div>
              </div>

              <div v-if="sourceUrls.length" class="space-y-1">
                <p class="text-slate-400 font-medium">Sources</p>
                <a v-for="url in sourceUrls" :key="url" :href="url" target="_blank" rel="noreferrer" class="block text-sky-300 hover:text-sky-200 break-all">{{ url }}</a>
              </div>

              <div v-if="scraperLogs.length" class="bg-slate-950/70 rounded-lg p-3 max-h-40 overflow-y-auto space-y-2">
                <div v-for="(log, index) in scraperLogs" :key="index" class="border-b border-slate-800 last:border-b-0 pb-2 last:pb-0">
                  <p class="text-slate-300">{{ log.provider || 'scraper' }} · {{ log.status || 'log' }} · {{ formatDate(log.checked_at) }}</p>
                  <p v-if="log.source_url" class="text-slate-500 break-all">{{ log.source_url }}</p>
                  <p v-if="log.error" class="text-red-300">{{ log.error }}</p>
                  <p v-if="log.fields_found" class="text-slate-500">Fields: {{ printable(log.fields_found) }}</p>
                </div>
              </div>
            </div>
          </details>

        </div>

        <div class="sticky bottom-0 bg-slate-900/95 backdrop-blur-sm flex items-center gap-2 p-4 border-t border-slate-700">
          <button @click="$emit('render', item.id)" class="px-4 py-2 rounded-xl bg-amber-600 text-white hover:bg-amber-500 text-sm font-medium transition-colors">Re-Render</button>
          <button v-if="item.status === 'ready'" @click="$emit('send', item.id)" class="px-4 py-2 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500 text-sm font-medium transition-colors">Publish Now</button>
          <button v-if="item.status === 'sent'" @click="$emit('send', item.id, true)" class="px-4 py-2 rounded-xl bg-sky-600 text-white hover:bg-sky-500 text-sm font-medium transition-colors">Resend</button>
          <button @click="$emit('find-poster', item.id)" class="px-3 py-2 rounded-xl bg-fuchsia-700 text-white hover:bg-fuchsia-600 text-sm font-medium transition-colors">Find Poster</button>
          <button @click="$emit('find-tv-info', item.id)" class="px-3 py-2 rounded-xl bg-sky-700 text-white hover:bg-sky-600 text-sm font-medium transition-colors">Find TV Info</button>
          <button @click="$emit('refresh-scraped-data', item.id)" class="px-3 py-2 rounded-xl bg-violet-700 text-white hover:bg-violet-600 text-sm font-medium transition-colors">Refresh Scraped</button>
          <button v-if="Object.keys(scrapedFields).length" @click="$emit('accept-scraped-data', item.id)" class="px-3 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 text-sm font-medium transition-colors">Accept Scraped</button>
          <button v-if="Object.keys(scrapedFields).length || acceptedScrape" @click="$emit('reject-scraped-data', item.id)" class="px-3 py-2 rounded-xl bg-red-700 text-white hover:bg-red-600 text-sm font-medium transition-colors">Reject Scraped</button>
          <button @click="$emit('close')" class="px-4 py-2 rounded-xl bg-slate-700 text-white hover:bg-slate-600 text-sm font-medium transition-colors ml-auto">Close</button>
        </div>
      </div>
    </div>
  </teleport>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import QueueReadinessChecklist from './QueueReadinessChecklist.vue'

const props = defineProps({
  item: { type: Object, default: null },
  sportConfigs: { type: Object, default: () => ({}) },
  templates: { type: Object, default: () => ({}) },
})

defineEmits(['close', 'render', 'send', 'find-poster', 'find-tv-info', 'refresh-scraped-data', 'accept-scraped-data', 'reject-scraped-data', 'save-render-options'])

const fixture = computed(() => props.item?.fixture_data || {})
const payload = computed(() => props.item?.payload || {})
const assetFailures = computed(() => props.item?.asset_failures || [])
const renderDiagnostics = computed(() => props.item?.render_diagnostics || {})
const renderProof = computed(() => props.item?.render_proof || {})
const fileName = computed(() => {
  const path = props.item?.card_path || ''
  return path ? path.split('/').pop() : ''
})
const isBrowserV3 = computed(() => Boolean(renderProof.value.verified_browser_v3))
const proofTitle = computed(() => {
  if (isBrowserV3.value) return 'Verified Browser v3 render'
  if (renderProof.value.fallback_active || props.item?.renderer_used === 'gd_v3') return 'GD fallback render, not Browser v3'
  return 'Render proof incomplete'
})
const proofPill = computed(() => {
  if (isBrowserV3.value) return 'Browser v3'
  if (renderProof.value.fallback_active || props.item?.renderer_used === 'gd_v3') return 'GD fallback'
  return 'Unverified'
})
const proofBannerClass = computed(() => {
  if (isBrowserV3.value) return 'border-emerald-500/40 bg-emerald-500/10 text-emerald-100'
  if (renderProof.value.fallback_active || props.item?.renderer_used === 'gd_v3') return 'border-amber-500/50 bg-amber-500/10 text-amber-100'
  return 'border-slate-600 bg-slate-800/60 text-slate-200'
})
const proofPillClass = computed(() => {
  if (isBrowserV3.value) return 'bg-emerald-400 text-slate-950'
  if (renderProof.value.fallback_active || props.item?.renderer_used === 'gd_v3') return 'bg-amber-400 text-slate-950'
  return 'bg-slate-700 text-slate-200'
})
const templateNames = computed(() => Object.keys(props.templates?.templates || {}))
const themeNames = computed(() => Object.keys(props.templates?.themes || {}))
const renderForm = reactive({ template: '', theme: '', manual_text: '', custom_poster_url: '', custom_background_url: '' })
const scraper = computed(() => payload.value.scraper || null)
const scrapedFields = computed(() => scraper.value?.normalized?.fields || {})
const sourceUrls = computed(() => scraper.value?.normalized?.source_urls || [])
const scraperLogs = computed(() => scraper.value?.logs || [])
const acceptedScrape = computed(() => Boolean(payload.value.accepted_scraped_data))
const scraperConfidence = computed(() => {
  const value = scraper.value?.normalized?.confidence
  return typeof value === 'number' ? `${Math.round(value * 100)}%` : '-'
})
const title = computed(() => {
  const d = fixture.value
  const home = d.home_team || ''
  const away = d.away_team || ''
  if (home && away) return `${home} vs ${away}`
  return d.event_name || d.strEvent || `Event ${props.item.event_id}`
})
const emoji = computed(() => props.sportConfigs[props.item?.sport_key]?.emoji || '🏅')

watch(() => props.item?.id, () => {
  const options = props.item?.payload?.render_options || {}
  renderForm.template = options.template || ''
  renderForm.theme = options.theme || ''
  renderForm.manual_text = options.manual_text || ''
  renderForm.custom_poster_url = options.custom_poster_url || ''
  renderForm.custom_background_url = options.custom_background_url || ''
}, { immediate: true })

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

function printable(value) {
  if (Array.isArray(value) || (value && typeof value === 'object')) return JSON.stringify(value)
  return String(value ?? '')
}
</script>
