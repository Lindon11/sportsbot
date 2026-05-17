/**
 * Plugin Bus Service
 *
 * A frontend hook system that allows plugins to register components,
 * menu items, and handlers without directly modifying core files.
 *
 * Similar to the backend Hook system but for frontend UI elements.
 */


/**
 * Menu item for navigation
 */
export interface MenuItem {
  id: string
  title: string
  icon?: string
  color?: string
  to?: string
  href?: string
  onClick?: () => void
  badge?: string | number
  children?: MenuItem[]
  order?: number
  section?: string
}

/**
 * Widget definition
 */
export interface Widget {
  id: string
  pluginId: string
  title: string
  component: string
  order?: number
  width?: 'small' | 'medium' | 'large' | 'full'
}

/**
 * Event callback type
 */
type EventCallback<T = unknown> = (data: T) => void

/**
 * Plugin Bus Class
 *
 * Singleton that manages frontend plugin registrations.
 */
class PluginBus {
  /**
   * Registered header links
   */
  headerLinks: MenuItem[] = []

  /**
   * Registered sidebar widgets
   */
  sidebarWidgets: Widget[] = []

  /**
   * Registered dashboard widgets
   */
  dashboardWidgets: Widget[] = []

  /**
   * Event listeners
   */
  private eventListeners: Map<string, Set<EventCallback>> = new Map()

  /**
   * Registered settings tabs
   */
  settingsTabs: { id: string; title: string; component: string; order: number }[] = []

  /**
   * Register a header link (navigation item)
   */
  registerHeaderLink(link: MenuItem): () => void {
    // Set default order
    if (link.order === undefined) {
      link.order = this.headerLinks.length * 10
    }

    this.headerLinks.push(link)

    // Sort by order
    this.headerLinks.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))

    // Return unregister function
    return () => {
      const index = this.headerLinks.findIndex(l => l.id === link.id)
      if (index !== -1) {
        this.headerLinks.splice(index, 1)
      }
    }
  }

  /**
   * Register multiple header links
   */
  registerHeaderLinks(links: MenuItem[]): () => void {
    const unregisterFns = links.map(link => this.registerHeaderLink(link))
    return () => unregisterFns.forEach(fn => fn())
  }

  /**
   * Register a sidebar widget
   */
  registerSidebarWidget(widget: Widget): () => void {
    if (widget.order === undefined) {
      widget.order = this.sidebarWidgets.length * 10
    }

    this.sidebarWidgets.push(widget)
    this.sidebarWidgets.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))

    return () => {
      const index = this.sidebarWidgets.findIndex(w => w.id === widget.id)
      if (index !== -1) {
        this.sidebarWidgets.splice(index, 1)
      }
    }
  }

  /**
   * Register a dashboard widget
   */
  registerDashboardWidget(widget: Widget): () => void {
    if (widget.order === undefined) {
      widget.order = this.dashboardWidgets.length * 10
    }

    this.dashboardWidgets.push(widget)
    this.dashboardWidgets.sort((a, b) => (a.order ?? 0) - (b.order ?? 0))

    return () => {
      const index = this.dashboardWidgets.findIndex(w => w.id === widget.id)
      if (index !== -1) {
        this.dashboardWidgets.splice(index, 1)
      }
    }
  }

  /**
   * Register a settings tab
   */
  registerSettingsTab(tab: { id: string; title: string; component: string; order?: number }): () => void {
    if (tab.order === undefined) {
      tab.order = this.settingsTabs.length * 10
    }

    this.settingsTabs.push(tab as typeof this.settingsTabs[0])
    this.settingsTabs.sort((a, b) => a.order - b.order)

    return () => {
      const index = this.settingsTabs.findIndex(t => t.id === tab.id)
      if (index !== -1) {
        this.settingsTabs.splice(index, 1)
      }
    }
  }

  /**
   * Subscribe to a plugin event
   */
  on<T = unknown>(event: string, callback: EventCallback<T>): () => void {
    if (!this.eventListeners.has(event)) {
      this.eventListeners.set(event, new Set())
    }

    this.eventListeners.get(event)!.add(callback as EventCallback)

    return () => {
      this.eventListeners.get(event)?.delete(callback as EventCallback)
    }
  }

  /**
   * Emit a plugin event
   */
  emit<T = unknown>(event: string, data: T): void {
    const listeners = this.eventListeners.get(event)
    if (listeners) {
      listeners.forEach(callback => {
        try {
          callback(data)
        } catch (error) {
          console.error(`[PluginBus] Error in event listener for "${event}":`, error)
        }
      })
    }
  }

  /**
   * Clear all registrations (for testing/reset)
   */
  clear(): void {
    this.headerLinks = []
    this.sidebarWidgets = []
    this.dashboardWidgets = []
    this.settingsTabs = []
    this.eventListeners.clear()
  }

  /**
   * Get all registered items for debugging
   */
  getDebugInfo(): {
    headerLinks: number
    sidebarWidgets: number
    dashboardWidgets: number
    settingsTabs: number
    events: string[]
  } {
    return {
      headerLinks: this.headerLinks.length,
      sidebarWidgets: this.sidebarWidgets.length,
      dashboardWidgets: this.dashboardWidgets.length,
      settingsTabs: this.settingsTabs.length,
      events: Array.from(this.eventListeners.keys()),
    }
  }
}

// Export singleton instance
export const PluginBusService = new PluginBus()

// Export class for testing
export { PluginBus }

// Convenience exports
export const registerHeaderLink = PluginBusService.registerHeaderLink.bind(PluginBusService)
export const registerHeaderLinks = PluginBusService.registerHeaderLinks.bind(PluginBusService)
export const registerSidebarWidget = PluginBusService.registerSidebarWidget.bind(PluginBusService)
export const registerDashboardWidget = PluginBusService.registerDashboardWidget.bind(PluginBusService)
export const registerSettingsTab = PluginBusService.registerSettingsTab.bind(PluginBusService)
export const onPluginEvent = PluginBusService.on.bind(PluginBusService)
export const emitPluginEvent = PluginBusService.emit.bind(PluginBusService)
