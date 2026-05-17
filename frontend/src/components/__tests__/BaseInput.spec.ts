import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import BaseInput from '@/components/ui/BaseInput.vue'

describe('BaseInput', () => {
  // ─── Rendering ─────────────────────────────────────────────────────────────

  describe('rendering', () => {
    it('renders an input element', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('input').exists()).toBe(true)
    })

    it('renders with default type="text"', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('input').attributes('type')).toBe('text')
    })

    it('renders with specified type', () => {
      const wrapper = mount(BaseInput, {
        props: { type: 'password' }
      })
      expect(wrapper.find('input').attributes('type')).toBe('password')
    })

    it('renders with email type', () => {
      const wrapper = mount(BaseInput, {
        props: { type: 'email' }
      })
      expect(wrapper.find('input').attributes('type')).toBe('email')
    })

    it('renders with number type', () => {
      const wrapper = mount(BaseInput, {
        props: { type: 'number' }
      })
      expect(wrapper.find('input').attributes('type')).toBe('number')
    })
  })

  // ─── Label ─────────────────────────────────────────────────────────────────

  describe('label', () => {
    it('does not render label by default', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('label').exists()).toBe(false)
    })

    it('renders label when provided', () => {
      const wrapper = mount(BaseInput, {
        props: { label: 'Email Address' }
      })
      expect(wrapper.find('label').text()).toContain('Email Address')
    })

    it('associates label with input via for attribute', () => {
      const wrapper = mount(BaseInput, {
        props: { label: 'Email' }
      })
      const labelFor = wrapper.find('label').attributes('for')
      const inputId = wrapper.find('input').attributes('id')
      expect(labelFor).toBe(inputId)
    })
  })

  // ─── Required ──────────────────────────────────────────────────────────────

  describe('required', () => {
    it('does not show required marker by default', () => {
      const wrapper = mount(BaseInput, {
        props: { label: 'Field' }
      })
      expect(wrapper.find('.required').exists()).toBe(false)
    })

    it('shows required marker when required prop is true', () => {
      const wrapper = mount(BaseInput, {
        props: { label: 'Field', required: true }
      })
      expect(wrapper.find('.required').exists()).toBe(true)
      expect(wrapper.find('.required').text()).toBe('*')
    })
  })

  // ─── Placeholder ───────────────────────────────────────────────────────────

  describe('placeholder', () => {
    it('renders placeholder when provided', () => {
      const wrapper = mount(BaseInput, {
        props: { placeholder: 'Enter your email' }
      })
      expect(wrapper.find('input').attributes('placeholder')).toBe('Enter your email')
    })

    it('has empty placeholder by default', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('input').attributes('placeholder')).toBe('')
    })
  })

  // ─── Disabled State ────────────────────────────────────────────────────────

  describe('disabled state', () => {
    it('is not disabled by default', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('input').attributes('disabled')).toBeUndefined()
    })

    it('applies disabled attribute when disabled prop is true', () => {
      const wrapper = mount(BaseInput, {
        props: { disabled: true }
      })
      expect(wrapper.find('input').attributes('disabled')).toBeDefined()
    })
  })

  // ─── Readonly State ────────────────────────────────────────────────────────

  describe('readonly state', () => {
    it('is not readonly by default', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('input').attributes('readonly')).toBeUndefined()
    })

    it('applies readonly attribute when readonly prop is true', () => {
      const wrapper = mount(BaseInput, {
        props: { readonly: true }
      })
      expect(wrapper.find('input').attributes('readonly')).toBeDefined()
    })
  })

  // ─── Error State ───────────────────────────────────────────────────────────

  describe('error state', () => {
    it('does not show error by default', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('.input-error').exists()).toBe(false)
      expect(wrapper.find('input').classes()).not.toContain('has-error')
    })

    it('shows error message when error prop is provided', () => {
      const wrapper = mount(BaseInput, {
        props: { error: 'This field is required' }
      })
      expect(wrapper.find('.input-error').exists()).toBe(true)
      expect(wrapper.find('.input-error').text()).toBe('This field is required')
    })

    it('applies has-error class when error is present', () => {
      const wrapper = mount(BaseInput, {
        props: { error: 'Invalid value' }
      })
      expect(wrapper.find('input').classes()).toContain('has-error')
    })
  })

  // ─── Hint ──────────────────────────────────────────────────────────────────

  describe('hint', () => {
    it('does not show hint by default', () => {
      const wrapper = mount(BaseInput)
      expect(wrapper.find('.input-hint').exists()).toBe(false)
    })

    it('shows hint when provided and no error', () => {
      const wrapper = mount(BaseInput, {
        props: { hint: 'Enter a valid email address' }
      })
      expect(wrapper.find('.input-hint').exists()).toBe(true)
      expect(wrapper.find('.input-hint').text()).toBe('Enter a valid email address')
    })

    it('hides hint when error is present', () => {
      const wrapper = mount(BaseInput, {
        props: { hint: 'Helper text', error: 'Error message' }
      })
      expect(wrapper.find('.input-hint').exists()).toBe(false)
      expect(wrapper.find('.input-error').exists()).toBe(true)
    })
  })

  // ─── v-model Binding ───────────────────────────────────────────────────────

  describe('v-model binding', () => {
    it('displays the modelValue', () => {
      const wrapper = mount(BaseInput, {
        props: { modelValue: 'test@example.com' }
      })
      expect(wrapper.find('input').element.value).toBe('test@example.com')
    })

    it('emits update:modelValue on input', async () => {
      const wrapper = mount(BaseInput)
      const input = wrapper.find('input')
      await input.setValue('new value')
      expect(wrapper.emitted('update:modelValue')).toBeTruthy()
      expect(wrapper.emitted('update:modelValue')![0]).toEqual(['new value'])
    })

    it('works with number type', async () => {
      const wrapper = mount(BaseInput, {
        props: { type: 'number', modelValue: 0 }
      })
      const input = wrapper.find('input')
      await input.setValue(42)
      expect(wrapper.emitted('update:modelValue')).toBeTruthy()
    })
  })

  // ─── Events ────────────────────────────────────────────────────────────────

  describe('events', () => {
    it('emits focus event', async () => {
      const wrapper = mount(BaseInput)
      await wrapper.find('input').trigger('focus')
      expect(wrapper.emitted('focus')).toBeTruthy()
    })

    it('emits blur event', async () => {
      const wrapper = mount(BaseInput)
      await wrapper.find('input').trigger('blur')
      expect(wrapper.emitted('blur')).toBeTruthy()
    })
  })

  // ─── Slots ─────────────────────────────────────────────────────────────────

  describe('slots', () => {
    it('renders prefix slot', () => {
      const wrapper = mount(BaseInput, {
        slots: { prefix: '@' }
      })
      expect(wrapper.find('.input-prefix').exists()).toBe(true)
      expect(wrapper.find('.input-prefix').text()).toBe('@')
    })

    it('renders suffix slot', () => {
      const wrapper = mount(BaseInput, {
        slots: { suffix: 'USD' }
      })
      expect(wrapper.find('.input-suffix').exists()).toBe(true)
      expect(wrapper.find('.input-suffix').text()).toBe('USD')
    })

    it('applies has-prefix class when prefix slot is used', () => {
      const wrapper = mount(BaseInput, {
        slots: { prefix: '@' }
      })
      expect(wrapper.find('input').classes()).toContain('has-prefix')
    })

    it('applies has-suffix class when suffix slot is used', () => {
      const wrapper = mount(BaseInput, {
        slots: { suffix: 'USD' }
      })
      expect(wrapper.find('input').classes()).toContain('has-suffix')
    })
  })
})
