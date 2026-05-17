/**
 * WebSocket Service for PBBG Vault
 * Manages WebSocket connections for real-time features
 */

import { config } from '@/config/env'

/**
 * WebSocket connection states
 */
export type ConnectionState = 'connecting' | 'connected' | 'disconnecting' | 'disconnected' | 'error'

/**
 * WebSocket event types
 */
export type WebSocketEvent =
  | 'stats-updated'
  | 'notification'
  | 'chat-message'
  | 'unread-count'
  | 'player-action'
  | 'system-alert'

/**
 * WebSocket message structure
 */
export interface WebSocketMessage<T = unknown> {
  event: WebSocketEvent | string
  channel?: string
  data: T
  timestamp?: string
}

/**
 * Event callback type
 */
type EventCallback<T = unknown> = (data: T, message: WebSocketMessage<T>) => void

/**
 * Subscription info
 */
interface Subscription {
  channel: string
  auth?: string
}

/**
 * WebSocket configuration
 */
interface WebSocketConfig {
  url: string
  key: string
  cluster: string
  reconnect: boolean
  reconnectInterval: number
  maxReconnectAttempts: number
  heartbeatInterval: number
}

/**
 * Default WebSocket configuration
 */
const defaultConfig: WebSocketConfig = {
  url: config.websocket.url || 'ws://localhost:6001',
  key: config.websocket.key || 'app-key',
  cluster: config.websocket.cluster || 'mt1',
  reconnect: true,
  reconnectInterval: 3000,
  maxReconnectAttempts: 10,
  heartbeatInterval: 30000
}

/**
 * WebSocket Service class
 * Manages connection, subscriptions, and event handling
 */
class WebSocketService {
  private ws: WebSocket | null = null
  private config: WebSocketConfig
  private connectionState: ConnectionState = 'disconnected'
  private reconnectAttempts = 0
  private heartbeatTimer: ReturnType<typeof setInterval> | null = null
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private subscriptions: Map<string, Subscription> = new Map()
  private eventListeners: Map<string, Set<EventCallback>> = new Map()
  private connectionListeners: Set<(state: ConnectionState) => void> = new Set()
  private messageQueue: string[] = []

  constructor(config: Partial<WebSocketConfig> = {}) {
    this.config = { ...defaultConfig, ...config }
  }

  /**
   * Get current connection state
   */
  getState(): ConnectionState {
    return this.connectionState
  }

  /**
   * Check if connected
   */
  isConnected(): boolean {
    return this.connectionState === 'connected' && this.ws?.readyState === WebSocket.OPEN
  }

  /**
   * Connect to WebSocket server
   */
  connect(authToken?: string): Promise<void> {
    return new Promise((resolve, reject) => {
      if (this.isConnected()) {
        resolve()
        return
      }

      this.setState('connecting')

      try {
        const wsUrl = this.buildWebSocketUrl()
        this.ws = new WebSocket(wsUrl)

        this.ws.onopen = () => {
          this.setState('connected')
          this.reconnectAttempts = 0
          this.startHeartbeat()
          this.flushMessageQueue()

          // Resubscribe to channels
          this.subscriptions.forEach((sub) => {
            this.sendSubscription(sub, authToken)
          })

          resolve()
        }

        this.ws.onmessage = (event) => {
          this.handleMessage(event.data)
        }

        this.ws.onclose = (event) => {
          this.handleClose(event.code, event.reason)
        }

        this.ws.onerror = () => {
          this.setState('error')
          reject(new Error('WebSocket connection error'))
        }
      } catch (error) {
        this.setState('error')
        reject(error)
      }
    })
  }

  /**
   * Disconnect from WebSocket server
   */
  disconnect(): void {
    if (!this.ws) return

    this.setState('disconnecting')
    this.stopHeartbeat()
    this.stopReconnectTimer()

    this.ws.close(1000, 'Client disconnect')
    this.ws = null
    this.setState('disconnected')
  }

  /**
   * Subscribe to a channel
   */
  subscribe(channel: string, authToken?: string): void {
    const subscription: Subscription = { channel, auth: authToken }
    this.subscriptions.set(channel, subscription)

    if (this.isConnected()) {
      this.sendSubscription(subscription, authToken)
    }
  }

  /**
   * Unsubscribe from a channel
   */
  unsubscribe(channel: string): void {
    this.subscriptions.delete(channel)

    if (this.isConnected()) {
      this.send({
        event: 'pusher:unsubscribe',
        data: { channel }
      })
    }
  }

  /**
   * Listen for events
   */
  on<T = unknown>(event: WebSocketEvent, callback: EventCallback<T>): () => void {
    if (!this.eventListeners.has(event)) {
      this.eventListeners.set(event, new Set())
    }

    this.eventListeners.get(event)!.add(callback as EventCallback)

    // Return unsubscribe function
    return () => {
      this.eventListeners.get(event)?.delete(callback as EventCallback)
    }
  }

  /**
   * Listen for connection state changes
   */
  onStateChange(callback: (state: ConnectionState) => void): () => void {
    this.connectionListeners.add(callback)
    return () => {
      this.connectionListeners.delete(callback)
    }
  }

  /**
   * Send a message
   */
  send<T = unknown>(message: WebSocketMessage<T> | Record<string, unknown>): void {
    const data = JSON.stringify(message)

    if (this.isConnected()) {
      this.ws!.send(data)
    } else {
      // Queue message for when connected
      this.messageQueue.push(data)
    }
  }

  /**
   * Build WebSocket URL
   */
  private buildWebSocketUrl(): string {
    const { url, key, cluster } = this.config
    const protocol = url.startsWith('wss') ? 'wss' : 'ws'
    const baseUrl = url.replace(/^wss?:\/\//, '')

    return `${protocol}://${baseUrl}/app/${key}?protocol=7&client=js&version=7.4.0&cluster=${cluster}`
  }

  /**
   * Set connection state and notify listeners
   */
  private setState(state: ConnectionState): void {
    this.connectionState = state
    this.connectionListeners.forEach((callback) => callback(state))
  }

  /**
   * Handle incoming message
   */
  private handleMessage(rawData: string): void {
    try {
      const message = JSON.parse(rawData) as WebSocketMessage

      // Handle Pusher protocol messages
      if (message.event === 'pusher:connection_established') {
        return
      }

      if (message.event === 'pusher:pong') {
        return // Heartbeat response
      }

      // Emit to event listeners
      const listeners = this.eventListeners.get(message.event)
      if (listeners) {
        listeners.forEach((callback) => {
          try {
            callback(message.data, message)
          } catch {
            // Listener error - continue processing
          }
        })
      }

      // Emit to wildcard listeners
      const wildcardListeners = this.eventListeners.get('*')
      if (wildcardListeners) {
        wildcardListeners.forEach((callback) => {
          try {
            callback(message.data, message)
          } catch {
            // Listener error - continue processing
          }
        })
      }
    } catch {
      // Invalid message format - ignore
    }
  }

  /**
   * Handle connection close
   */
  private handleClose(code: number, _reason: string): void {
    this.stopHeartbeat()
    this.ws = null

    if (code === 1000) {
      this.setState('disconnected')
    } else {
      this.setState('error')
      // Connection closed unexpectedly

      // Attempt reconnection
      if (this.config.reconnect && this.reconnectAttempts < this.config.maxReconnectAttempts) {
        this.scheduleReconnect()
      }
    }
  }

  /**
   * Schedule reconnection attempt
   */
  private scheduleReconnect(): void {
    this.reconnectAttempts++

    this.reconnectTimer = setTimeout(() => {
      this.connect()
    }, this.config.reconnectInterval)
  }

  /**
   * Stop reconnect timer
   */
  private stopReconnectTimer(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }

  /**
   * Start heartbeat
   */
  private startHeartbeat(): void {
    this.stopHeartbeat()

    this.heartbeatTimer = setInterval(() => {
      if (this.isConnected()) {
        this.send({ event: 'pusher:ping', data: {} })
      }
    }, this.config.heartbeatInterval)
  }

  /**
   * Stop heartbeat
   */
  private stopHeartbeat(): void {
    if (this.heartbeatTimer) {
      clearInterval(this.heartbeatTimer)
      this.heartbeatTimer = null
    }
  }

  /**
   * Send subscription message
   */
  private sendSubscription(subscription: Subscription, authToken?: string): void {
    this.send({
      event: 'pusher:subscribe',
      data: {
        auth: authToken || subscription.auth,
        channel: subscription.channel
      }
    })
  }

  /**
   * Flush queued messages
   */
  private flushMessageQueue(): void {
    while (this.messageQueue.length > 0 && this.isConnected()) {
      const message = this.messageQueue.shift()
      if (message) {
        this.ws!.send(message)
      }
    }
  }

  // ==========================================
  // Plugin Namespacing Methods
  // ==========================================

  /**
   * Subscribe to a plugin-specific channel.
   * Creates a namespaced subscription: pluginId:channel
   *
   * @param pluginName The plugin identifier (e.g., 'rpg', 'crimes')
   * @param channel The channel name within the plugin
   * @param callback Callback for messages
   * @returns Unsubscribe function
   */
  subscribeToPlugin(
    pluginName: string,
    channel: string,
    callback: EventCallback
  ): () => void {
    const namespacedChannel = `${pluginName}:${channel}`

    // Subscribe to the namespaced channel
    this.subscribe(namespacedChannel)

    // Listen for events on this channel with plugin prefix filtering
    const unsubscribe = this.on(namespacedChannel as WebSocketEvent, (data, message) => {
      // Route based on app_prefix if present
      const pluginPrefix = (data as { app_prefix?: string }).app_prefix
      if (!pluginPrefix || pluginPrefix === pluginName) {
        callback(data, message)
      }
    })

    // Return combined unsubscribe
    return () => {
      unsubscribe()
      this.unsubscribe(namespacedChannel)
    }
  }

  /**
   * Broadcast to a plugin-specific channel.
   *
   * @param pluginName The plugin identifier
   * @param event The event name
   * @param data The data to send
   */
  broadcastToPlugin(pluginName: string, event: string, data: unknown): void {
    this.send({
      event: `${pluginName}:${event}`,
      channel: `plugin-${pluginName}`,
      data: {
        ...(data as object),
        app_prefix: pluginName,
      },
    })
  }

  /**
   * Listen for all plugin events (for debugging/admin).
   *
   * @param callback Callback for any plugin event
   * @returns Unsubscribe function
   */
  onAnyPluginEvent(callback: EventCallback): () => void {
    return this.on('*' as WebSocketEvent, (data, message) => {
      const evt = message.event as string
      if (evt && evt.includes(':')) {
        callback(data, message)
      }
    })
  }

  /**
   * Get plugin name from a namespaced event.
   *
   * @param eventName The event name (e.g., 'rpg:gold_updated')
   * @returns The plugin name or null
   */
  static parsePluginFromEvent(eventName: string): string | null {
    const parts = eventName.split(':')
    return parts.length > 1 ? parts[0] ?? null : null
  }

  /**
   * Get event name without plugin prefix.
   *
   * @param eventName The event name (e.g., 'rpg:gold_updated')
   * @returns The event name without plugin prefix
   */
  static parseEventName(eventName: string): string {
    const parts = eventName.split(':')
    return parts.length > 1 ? parts.slice(1).join(':') : eventName
  }
}

// Export singleton instance
export const websocketService = new WebSocketService()

// Export class for testing
export { WebSocketService }
