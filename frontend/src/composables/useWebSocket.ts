/**
 * WebSocket Composable for Vue Components
 * Provides reactive WebSocket connection management
 */

import { ref, onMounted, onUnmounted, readonly } from 'vue'
import {
  websocketService,
  type ConnectionState,
  type WebSocketEvent,
  type WebSocketMessage
} from '@/services/websocket'

/**
 * Options for useWebSocket composable
 */
export interface UseWebSocketOptions {
  /** Auto-connect on mount */
  autoConnect?: boolean
  /** Auth token for private channels */
  authToken?: string
  /** Channels to subscribe to on connect */
  channels?: string[]
}

/**
 * Return type for useWebSocket composable
 */
export interface UseWebSocketReturn {
  /** Current connection state */
  connectionState: Readonly<ReturnType<typeof ref<ConnectionState>>>
  /** Whether WebSocket is connected */
  isConnected: Readonly<ReturnType<typeof ref<boolean>>>
  /** Connect to WebSocket */
  connect: () => Promise<void>
  /** Disconnect from WebSocket */
  disconnect: () => void
  /** Subscribe to a channel */
  subscribe: (channel: string) => void
  /** Unsubscribe from a channel */
  unsubscribe: (channel: string) => void
  /** Listen for an event */
  on: <T = unknown>(event: WebSocketEvent, callback: (data: T, message: WebSocketMessage<T>) => void) => () => void
  /** Send a message */
  send: <T = unknown>(message: WebSocketMessage<T>) => void
}

/**
 * Vue composable for WebSocket connection management
 */
export function useWebSocket(options: UseWebSocketOptions = {}): UseWebSocketReturn {
  const { autoConnect = false, authToken, channels = [] } = options

  // Reactive state
  const connectionState = ref<ConnectionState>(websocketService.getState())
  const isConnected = ref<boolean>(websocketService.isConnected())

  // Cleanup functions
  const cleanupFns: (() => void)[] = []

  /**
   * Update reactive state from service
   */
  const updateState = (state: ConnectionState) => {
    connectionState.value = state
    isConnected.value = state === 'connected'
  }

  /**
   * Connect to WebSocket
   */
  const connect = async (): Promise<void> => {
    await websocketService.connect(authToken)
    channels.forEach((channel) => {
      websocketService.subscribe(channel, authToken)
    })
  }

  /**
   * Disconnect from WebSocket
   */
  const disconnect = (): void => {
    websocketService.disconnect()
  }

  /**
   * Subscribe to a channel
   */
  const subscribe = (channel: string): void => {
    websocketService.subscribe(channel, authToken)
  }

  /**
   * Unsubscribe from a channel
   */
  const unsubscribe = (channel: string): void => {
    websocketService.unsubscribe(channel)
  }

  /**
   * Listen for an event
   */
  const on = <T = unknown>(
    event: WebSocketEvent,
    callback: (data: T, message: WebSocketMessage<T>) => void
  ): (() => void) => {
    const unsubscribe = websocketService.on<T>(event, callback)
    cleanupFns.push(unsubscribe)
    return unsubscribe
  }

  /**
   * Send a message
   */
  const send = <T = unknown>(message: WebSocketMessage<T>): void => {
    websocketService.send(message)
  }

  // Setup on mount
  onMounted(() => {
    // Subscribe to state changes
    const unsubscribeState = websocketService.onStateChange(updateState)
    cleanupFns.push(unsubscribeState)

    // Auto-connect if enabled
    if (autoConnect) {
      connect().catch(() => {
        // Connection error - state will be updated via onStateChange
      })
    }
  })

  // Cleanup on unmount
  onUnmounted(() => {
    cleanupFns.forEach((fn) => fn())
    cleanupFns.length = 0
  })

  return {
    connectionState: readonly(connectionState),
    isConnected: readonly(isConnected),
    connect,
    disconnect,
    subscribe,
    unsubscribe,
    on,
    send
  }
}

/**
 * Composable for subscribing to a specific channel
 */
export function useWebSocketChannel<T = unknown>(
  channelName: string,
  events: WebSocketEvent[] = []
): {
  data: { value: T | null | undefined }
  connectionState: Readonly<ReturnType<typeof ref<ConnectionState>>>
  isConnected: Readonly<ReturnType<typeof ref<boolean>>>
} {
  const data = ref<T | null | undefined>(undefined)
  const { connectionState, isConnected, subscribe, on } = useWebSocket({
    autoConnect: true,
    channels: [channelName]
  })

  onMounted(() => {
    subscribe(channelName)

    events.forEach((event) => {
      on<T>(event, (eventData) => {
        data.value = eventData as T
      })
    })
  })

  return {
    data: { value: data.value },
    connectionState,
    isConnected
  }
}

/**
 * Composable for real-time stats updates
 */
export function useRealtimeStats(userId: number) {
  const stats = ref({
    energy: 0,
    maxEnergy: 100,
    health: 0,
    maxHealth: 100,
    stamina: 0,
    maxStamina: 100,
    nerve: 0,
    maxNerve: 100,
    cash: 0,
    bank: 0,
    points: 0
  })

  const { connectionState, isConnected, on } = useWebSocket({
    autoConnect: true,
    channels: [`private-user.${userId}`]
  })

  onMounted(() => {
    on<{ stats: typeof stats.value }>('stats-updated', (data) => {
      if (data.stats) {
        stats.value = { ...stats.value, ...data.stats }
      }
    })
  })

  return {
    stats,
    connectionState,
    isConnected
  }
}

/**
 * Composable for real-time notifications
 */
export function useRealtimeNotifications(userId: number) {
  const unreadCount = ref(0)
  const latestNotification = ref<unknown>(null)

  const { on } = useWebSocket({
    autoConnect: true,
    channels: [`private-user.${userId}`]
  })

  onMounted(() => {
    on<{ count: number }>('unread-count', (data) => {
      unreadCount.value = data.count
    })

    on<{ notification: unknown }>('notification', (data) => {
      latestNotification.value = data.notification
      unreadCount.value++
    })
  })

  return {
    unreadCount,
    latestNotification
  }
}

export default useWebSocket
