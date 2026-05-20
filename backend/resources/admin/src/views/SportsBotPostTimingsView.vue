<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold text-white">SportsBot Post Timings</h1>
        <p class="text-slate-400 text-sm mt-1">Control automatic posting and queue processing times.</p>
      </div>
      <div class="flex gap-2">
        <button @click="load" :disabled="loading" class="px-4 py-2 rounded-xl bg-slate-800 border border-slate-700 text-white hover:bg-slate-700 disabled:opacity-60">
          {{ loading ? 'Refreshing...' : 'Refresh' }}
        </button>
        <button @click="save" :disabled="saving" class="px-4 py-2 rounded-xl bg-emerald-700 text-white hover:bg-emerald-600 disabled:opacity-60">
          {{ saving ? 'Saving...' : 'Save Timings' }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
      <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">Live Alerts</h2>
        <label class="flex items-center gap-2 text-sm text-slate-300">
          <input v-model="form.schedule_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
          Send score/status alerts automatically
        </label>
        <FrequencySelect v-model="form.schedule_frequency" :frequencies="frequencies" label="Alert frequency" />
      </section>

      <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-4">
        <h2 class="text-lg font-semibold text-white">Match Highlights</h2>
        <label class="flex items-center gap-2 text-sm text-slate-300">
          <input v-model="form.highlights_schedule_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
          Send highlights automatically
        </label>
        <FrequencySelect v-model="form.highlights_schedule_frequency" :frequencies="frequencies" label="Highlights frequency" />
      </section>
    </div>

    <section class="rounded-2xl bg-slate-800/50 border border-slate-700/50 p-5 space-y-5">
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 class="text-lg font-semibold text-white">Fixture Queue Autopilot</h2>
          <p class="text-xs mt-1" :class="form.fixture_queue_schedule_enabled ? 'text-emerald-300' : 'text-slate-500'">
            {{ form.fixture_queue_schedule_enabled ? 'Queue automation enabled' : 'Queue automation disabled' }}
          </p>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-300">
          <input v-model="form.fixture_queue_schedule_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
          Enable queue automation
        </label>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
        <div class="rounded-xl bg-slate-900 border border-slate-700 p-4 space-y-3">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.fixture_queue_prefetch_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Prefetch
          </label>
          <label class="block">
            <span class="block text-xs text-slate-400 mb-1">Daily time</span>
            <input v-model="form.fixture_queue_prefetch_time" type="time" class="w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2">
          </label>
        </div>

        <div class="rounded-xl bg-slate-900 border border-slate-700 p-4 space-y-3">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.fixture_queue_enrich_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Scrape enrich
          </label>
          <FrequencySelect v-model="form.fixture_queue_enrich_frequency" :frequencies="frequencies" label="Frequency" compact />
          <div class="grid grid-cols-2 gap-2">
            <NumberInput v-model="form.fixture_queue_enrich_days" label="Days" />
            <NumberInput v-model="form.fixture_queue_enrich_limit" label="Limit" />
          </div>
        </div>

        <div class="rounded-xl bg-slate-900 border border-slate-700 p-4 space-y-3">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.fixture_queue_render_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Render cards
          </label>
          <FrequencySelect v-model="form.fixture_queue_render_frequency" :frequencies="frequencies" label="Frequency" compact />
        </div>

        <div class="rounded-xl bg-slate-900 border border-slate-700 p-4 space-y-3">
          <label class="flex items-center gap-2 text-sm text-slate-300">
            <input v-model="form.fixture_queue_publish_enabled" type="checkbox" class="rounded border-slate-600 bg-slate-900 text-emerald-500">
            Publish cards
          </label>
          <FrequencySelect v-model="form.fixture_queue_publish_frequency" :frequencies="frequencies" label="Frequency" compact />
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { defineComponent, h, onMounted, reactive, ref } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const frequencies = ref([])

const form = reactive({
  schedule_enabled: false,
  schedule_frequency: 'everyTwoMinutes',
  fixture_queue_schedule_enabled: false,
  fixture_queue_prefetch_enabled: true,
  fixture_queue_prefetch_time: '05:00',
  fixture_queue_enrich_enabled: true,
  fixture_queue_enrich_frequency: 'everyThirtyMinutes',
  fixture_queue_enrich_days: 2,
  fixture_queue_enrich_limit: 30,
  fixture_queue_render_enabled: true,
  fixture_queue_render_frequency: 'everyTenMinutes',
  fixture_queue_publish_enabled: true,
  fixture_queue_publish_frequency: 'everyFiveMinutes',
  highlights_schedule_enabled: true,
  highlights_schedule_frequency: 'everyThirtyMinutes',
})

const FrequencySelect = defineComponent({
  props: {
    modelValue: { type: String, required: true },
    frequencies: { type: Array, required: true },
    label: { type: String, required: true },
    compact: { type: Boolean, default: false },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () => h('label', { class: 'block' }, [
      h('span', { class: 'block text-xs text-slate-400 mb-1' }, props.label),
      h('select', {
        value: props.modelValue,
        class: 'w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2',
        onChange: event => emit('update:modelValue', event.target.value),
      }, props.frequencies.map(item => h('option', { value: item.value }, item.label))),
    ])
  },
})

const TimeToggle = defineComponent({
  props: {
    enabled: { type: Boolean, required: true },
    time: { type: String, required: true },
    title: { type: String, required: true },
  },
  emits: ['update:enabled', 'update:time'],
  setup(props, { emit }) {
    return () => h('div', { class: 'rounded-xl bg-slate-900 border border-slate-700 p-4 space-y-3' }, [
      h('label', { class: 'flex items-center gap-2 text-sm text-slate-300' }, [
        h('input', {
          checked: props.enabled,
          type: 'checkbox',
          class: 'rounded border-slate-600 bg-slate-900 text-emerald-500',
          onChange: event => emit('update:enabled', event.target.checked),
        }),
        props.title,
      ]),
      h('input', {
        value: props.time,
        type: 'time',
        class: 'w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2',
        onInput: event => emit('update:time', event.target.value),
      }),
    ])
  },
})

const NumberInput = defineComponent({
  props: {
    modelValue: { type: Number, required: true },
    label: { type: String, required: true },
  },
  emits: ['update:modelValue'],
  setup(props, { emit }) {
    return () => h('label', { class: 'block' }, [
      h('span', { class: 'block text-xs text-slate-400 mb-1' }, props.label),
      h('input', {
        value: props.modelValue,
        type: 'number',
        min: '0',
        class: 'w-full rounded-xl bg-slate-950 border border-slate-700 text-white px-3 py-2',
        onInput: event => emit('update:modelValue', Number(event.target.value)),
      }),
    ])
  },
})

function flattenSettings(settings) {
  return {
    schedule_enabled: settings.live_alerts?.enabled ?? false,
    schedule_frequency: settings.live_alerts?.frequency || 'everyTwoMinutes',
    fixture_queue_schedule_enabled: settings.fixture_queue?.enabled ?? false,
    fixture_queue_prefetch_enabled: settings.fixture_queue?.prefetch_enabled ?? true,
    fixture_queue_prefetch_time: settings.fixture_queue?.prefetch_time || '05:00',
    fixture_queue_enrich_enabled: settings.fixture_queue?.enrich_enabled ?? true,
    fixture_queue_enrich_frequency: settings.fixture_queue?.enrich_frequency || 'everyThirtyMinutes',
    fixture_queue_enrich_days: settings.fixture_queue?.enrich_days ?? 2,
    fixture_queue_enrich_limit: settings.fixture_queue?.enrich_limit ?? 30,
    fixture_queue_render_enabled: settings.fixture_queue?.render_enabled ?? true,
    fixture_queue_render_frequency: settings.fixture_queue?.render_frequency || 'everyTenMinutes',
    fixture_queue_publish_enabled: settings.fixture_queue?.publish_enabled ?? true,
    fixture_queue_publish_frequency: settings.fixture_queue?.publish_frequency || 'everyFiveMinutes',
    highlights_schedule_enabled: settings.highlights?.enabled ?? true,
    highlights_schedule_frequency: settings.highlights?.frequency || 'everyThirtyMinutes',
  }
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/sportsbot/post-timings')
    frequencies.value = data.frequencies || []
    Object.assign(form, flattenSettings(data.settings || {}))
  } catch (error) {
    toast.error(error?.response?.data?.message || 'Failed to load post timings')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const { data } = await api.post('/admin/sportsbot/post-timings', form)
    Object.assign(form, flattenSettings(data.settings || {}))
    toast.success('Post timings saved')
  } catch (error) {
    toast.error(error?.response?.data?.error || error?.response?.data?.message || 'Failed to save post timings')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
