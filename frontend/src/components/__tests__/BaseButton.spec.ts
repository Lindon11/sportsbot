import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseButton from '@/components/ui/BaseButton.vue'

describe('BaseButton', () => {
  // ─── Rendering ─────────────────────────────────────────────────────────────

  describe('rendering', () => {
    it('renders a button element', () => {
      const wrapper = mount(BaseButton)
      expect(wrapper.find('button').exists()).toBe(true)
    })

    it('renders slot content', () => {
      const wrapper = mount(BaseButton, {
        slots: { default: 'Click me' }
      })
      expect(wrapper.text()).toContain('Click me')
    })

    it('renders with default type="button"', () => {
      const wrapper = mount(BaseButton)
      expect(wrapper.find('button').attributes('type')).toBe('button')
    })

    it('renders with specified type', () => {
      const wrapper = mount(BaseButton, {
        props: { type: 'submit' }
      })
      expect(wrapper.find('button').attributes('type')).toBe('submit')
    })
  })

  // ─── Variants ──────────────────────────────────────────────────────────────

  describe('variants', () => {
    it('applies primary variant by default', () => {
      const wrapper = mount(BaseButton)
      expect(wrapper.find('button').classes()).toContain('primary')
    })

    it('applies secondary variant', () => {
      const wrapper = mount(BaseButton, {
        props: { variant: 'secondary' }
      })
      expect(wrapper.find('button').classes()).toContain('secondary')
    })

    it('applies danger variant', () => {
      const wrapper = mount(BaseButton, {
        props: { variant: 'danger' }
      })
      expect(wrapper.find('button').classes()).toContain('danger')
    })

    it('applies ghost variant', () => {
      const wrapper = mount(BaseButton, {
        props: { variant: 'ghost' }
      })
      expect(wrapper.find('button').classes()).toContain('ghost')
    })
  })

  // ─── Sizes ─────────────────────────────────────────────────────────────────

  describe('sizes', () => {
    it('applies md size by default', () => {
      const wrapper = mount(BaseButton)
      expect(wrapper.find('button').classes()).toContain('md')
    })

    it('applies sm size', () => {
      const wrapper = mount(BaseButton, {
        props: { size: 'sm' }
      })
      expect(wrapper.find('button').classes()).toContain('sm')
    })

    it('applies lg size', () => {
      const wrapper = mount(BaseButton, {
        props: { size: 'lg' }
      })
      expect(wrapper.find('button').classes()).toContain('lg')
    })
  })

  // ─── Disabled State ────────────────────────────────────────────────────────

  describe('disabled state', () => {
    it('is not disabled by default', () => {
      const wrapper = mount(BaseButton)
      expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
    })

    it('applies disabled attribute when disabled prop is true', () => {
      const wrapper = mount(BaseButton, {
        props: { disabled: true }
      })
      expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    })

    it('applies disabled class when disabled prop is true', () => {
      const wrapper = mount(BaseButton, {
        props: { disabled: true }
      })
      expect(wrapper.find('button').classes()).toContain('disabled')
    })
  })

  // ─── Loading State ─────────────────────────────────────────────────────────

  describe('loading state', () => {
    it('is not loading by default', () => {
      const wrapper = mount(BaseButton)
      expect(wrapper.find('button').classes()).not.toContain('loading')
    })

    it('applies loading class when loading prop is true', () => {
      const wrapper = mount(BaseButton, {
        props: { loading: true }
      })
      expect(wrapper.find('button').classes()).toContain('loading')
    })

    it('disables button when loading', () => {
      const wrapper = mount(BaseButton, {
        props: { loading: true }
      })
      expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    })

    it('shows spinner when loading', () => {
      const wrapper = mount(BaseButton, {
        props: { loading: true }
      })
      expect(wrapper.find('.spinner').exists()).toBe(true)
    })

    it('hides slot content when loading', () => {
      const wrapper = mount(BaseButton, {
        props: { loading: true },
        slots: { default: 'Click me' }
      })
      expect(wrapper.text()).not.toContain('Click me')
    })

    it('shows slot content when not loading', () => {
      const wrapper = mount(BaseButton, {
        props: { loading: false },
        slots: { default: 'Click me' }
      })
      expect(wrapper.text()).toContain('Click me')
    })
  })

  // ─── Combined States ───────────────────────────────────────────────────────

  describe('combined states', () => {
    it('handles loading and disabled both true', () => {
      const wrapper = mount(BaseButton, {
        props: { loading: true, disabled: true }
      })
      expect(wrapper.find('button').attributes('disabled')).toBeDefined()
      expect(wrapper.find('button').classes()).toContain('loading')
      expect(wrapper.find('button').classes()).toContain('disabled')
    })

    it('applies multiple classes correctly', () => {
      const wrapper = mount(BaseButton, {
        props: { variant: 'danger', size: 'lg', loading: true }
      })
      const classes = wrapper.find('button').classes()
      expect(classes).toContain('danger')
      expect(classes).toContain('lg')
      expect(classes).toContain('loading')
    })
  })

  // ─── Interactions ──────────────────────────────────────────────────────────

  describe('interactions', () => {
    it('emits click event when clicked', async () => {
      const wrapper = mount(BaseButton)
      await wrapper.find('button').trigger('click')
      expect(wrapper.emitted('click')).toBeTruthy()
    })

    it('does not emit click when disabled', async () => {
      const wrapper = mount(BaseButton, {
        props: { disabled: true }
      })
      await wrapper.find('button').trigger('click')
      expect(wrapper.emitted('click')).toBeFalsy()
    })

    it('does not emit click when loading', async () => {
      const wrapper = mount(BaseButton, {
        props: { loading: true }
      })
      await wrapper.find('button').trigger('click')
      expect(wrapper.emitted('click')).toBeFalsy()
    })
  })
})
