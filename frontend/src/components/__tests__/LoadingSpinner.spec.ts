import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import LoadingSpinner from '@/components/ui/LoadingSpinner.vue'

describe('LoadingSpinner', () => {
  // ─── Rendering ─────────────────────────────────────────────────────────────

  describe('rendering', () => {
    it('renders a spinner element', () => {
      const wrapper = mount(LoadingSpinner)
      expect(wrapper.find('.loading-spinner').exists()).toBe(true)
    })

    it('renders the spinner ring', () => {
      const wrapper = mount(LoadingSpinner)
      expect(wrapper.find('.spinner-ring').exists()).toBe(true)
    })
  })

  // ─── Sizes ─────────────────────────────────────────────────────────────────

  describe('sizes', () => {
    it('applies md size by default', () => {
      const wrapper = mount(LoadingSpinner)
      expect(wrapper.find('.loading-spinner').classes()).toContain('md')
    })

    it('applies sm size', () => {
      const wrapper = mount(LoadingSpinner, {
        props: { size: 'sm' }
      })
      expect(wrapper.find('.loading-spinner').classes()).toContain('sm')
    })

    it('applies lg size', () => {
      const wrapper = mount(LoadingSpinner, {
        props: { size: 'lg' }
      })
      expect(wrapper.find('.loading-spinner').classes()).toContain('lg')
    })
  })

  // ─── Text ──────────────────────────────────────────────────────────────────

  describe('text', () => {
    it('does not show text by default', () => {
      const wrapper = mount(LoadingSpinner)
      expect(wrapper.find('.spinner-text').exists()).toBe(false)
    })

    it('shows text when provided', () => {
      const wrapper = mount(LoadingSpinner, {
        props: { text: 'Loading...' }
      })
      expect(wrapper.find('.spinner-text').exists()).toBe(true)
      expect(wrapper.find('.spinner-text').text()).toBe('Loading...')
    })

    it('displays custom loading message', () => {
      const wrapper = mount(LoadingSpinner, {
        props: { text: 'Saving changes...' }
      })
      expect(wrapper.text()).toContain('Saving changes...')
    })
  })

  // ─── Structure ─────────────────────────────────────────────────────────────

  describe('structure', () => {
    it('has correct DOM structure', () => {
      const wrapper = mount(LoadingSpinner)
      const spinner = wrapper.find('.loading-spinner')
      expect(spinner.find('.spinner-ring').exists()).toBe(true)
    })

    it('has correct DOM structure with text', () => {
      const wrapper = mount(LoadingSpinner, {
        props: { text: 'Loading...' }
      })
      const spinner = wrapper.find('.loading-spinner')
      expect(spinner.find('.spinner-ring').exists()).toBe(true)
      expect(spinner.find('.spinner-text').exists()).toBe(true)
    })
  })
})
