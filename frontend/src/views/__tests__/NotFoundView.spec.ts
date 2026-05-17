import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createWebHistory } from 'vue-router'
import NotFoundView from '@/views/NotFoundView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', name: 'home', component: { template: '<div>Home</div>' } },
    { path: '/dashboard', name: 'dashboard', component: { template: '<div>Dashboard</div>' } }
  ]
})

describe('NotFoundView', () => {
  it('renders 404 error code', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    expect(wrapper.find('.error-code').text()).toBe('404')
  })

  it('renders error title', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    expect(wrapper.find('.error-title').text()).toBe('Page Not Found')
  })

  it('renders error message', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    expect(wrapper.find('.error-message').text()).toContain("doesn't exist")
  })

  it('renders dashboard link', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    const links = wrapper.findAllComponents({ name: 'RouterLink' })
    const dashboardLink = links.find(link => link.props('to') === '/dashboard')
    expect(dashboardLink?.exists()).toBe(true)
  })

  it('renders home link', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    const links = wrapper.findAllComponents({ name: 'RouterLink' })
    const homeLink = links.find(link => link.props('to') === '/')
    expect(homeLink?.exists()).toBe(true)
  })

  it('has correct container class', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    expect(wrapper.find('.not-found').exists()).toBe(true)
  })

  it('has primary and secondary buttons', () => {
    const wrapper = mount(NotFoundView, {
      global: {
        plugins: [router]
      }
    })

    expect(wrapper.find('.btn-primary').exists()).toBe(true)
    expect(wrapper.find('.btn-secondary').exists()).toBe(true)
  })
})
