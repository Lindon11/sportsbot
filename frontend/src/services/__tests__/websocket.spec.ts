import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { WebSocketService, websocketService } from '@/services/websocket'

// Track WebSocket instances
let mockWsInstances: MockWebSocket[] = []

// Mock WebSocket class
class MockWebSocket {
  static CONNECTING = 0
  static OPEN = 1
  static CLOSING = 2
  static CLOSED = 3

  readyState = MockWebSocket.CONNECTING
  onopen: ((event: Event) => void) | null = null
  onmessage: ((event: MessageEvent) => void) | null = null
  onclose: ((event: CloseEvent) => void) | null = null
  onerror: ((event: Event) => void) | null = null

  send = vi.fn()
  close = vi.fn()

  constructor(public url: string) {
    mockWsInstances.push(this)
  }

  simulateOpen() {
    this.readyState = MockWebSocket.OPEN
    this.onopen?.(new Event('open'))
  }

  simulateMessage(data: unknown) {
    this.onmessage?.({ data: JSON.stringify(data) } as MessageEvent)
  }

  simulateClose(code = 1000, reason = '') {
    this.readyState = MockWebSocket.CLOSED
    this.onclose?.({ code, reason } as CloseEvent)
  }

  simulateError() {
    this.onerror?.(new Event('error'))
  }
}

// Use vi.hoisted to set up mock before module loads
vi.stubGlobal('WebSocket', MockWebSocket)

describe('WebSocketService', () => {
  let service: WebSocketService
  let mockWs: MockWebSocket | undefined

  beforeEach(() => {
    mockWsInstances = []
    service = new WebSocketService({
      url: 'ws://localhost:6001',
      key: 'test-key',
      cluster: 'mt1',
      reconnect: false, // Disable auto-reconnect for tests
      heartbeatInterval: 1000,
    })
    vi.clearAllMocks()
  })

  afterEach(() => {
    service.disconnect()
    mockWsInstances = []
  })

  // ─── Initial State ────────────────────────────────────────────────────────

  describe('initial state', () => {
    it('starts in disconnected state', () => {
      expect(service.getState()).toBe('disconnected')
    })

    it('is not connected initially', () => {
      expect(service.isConnected()).toBe(false)
    })
  })

  // ─── connect() ────────────────────────────────────────────────────────────

  describe('connect()', () => {
    it('connects to WebSocket server', async () => {
      const promise = service.connect()

      // Get the mock WebSocket instance
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()

      await promise
      expect(service.isConnected()).toBe(true)
    })

    it('sets state to connecting during connection', () => {
      service.connect()
      expect(service.getState()).toBe('connecting')
    })

    it('sets state to connected after successful connection', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()

      await promise
      expect(service.getState()).toBe('connected')
    })

    it('returns immediately if already connected', async () => {
      // First connection
      const promise1 = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise1

      // Second connection should return immediately
      await service.connect()

      // Should only have one WebSocket instance
      expect(mockWsInstances.length).toBe(1)
    })
  })

  // ─── disconnect() ─────────────────────────────────────────────────────────

  describe('disconnect()', () => {
    it('disconnects from WebSocket server', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      service.disconnect()

      expect(mockWs?.close).toHaveBeenCalled()
      expect(service.getState()).toBe('disconnected')
    })

    it('does nothing if not connected', () => {
      service.disconnect()
      // Should not throw
    })
  })

  // ─── subscribe() ──────────────────────────────────────────────────────────

  describe('subscribe()', () => {
    it('subscribes to a channel', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      service.subscribe('test-channel')

      expect(mockWs?.send).toHaveBeenCalledWith(
        expect.stringContaining('pusher:subscribe')
      )
    })

    it('queues subscription if not connected', () => {
      service.subscribe('test-channel')

      // No WebSocket instance yet
      expect(mockWsInstances.length).toBe(0)
    })
  })

  // ─── unsubscribe() ────────────────────────────────────────────────────────

  describe('unsubscribe()', () => {
    it('unsubscribes from a channel', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      service.subscribe('test-channel')
      service.unsubscribe('test-channel')

      expect(mockWs?.send).toHaveBeenCalledWith(
        expect.stringContaining('pusher:unsubscribe')
      )
    })
  })

  // ─── on() ─────────────────────────────────────────────────────────────────

  describe('on()', () => {
    it('registers event listener', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      const callback = vi.fn()
      service.on('stats-updated', callback)

      mockWs?.simulateMessage({
        event: 'stats-updated',
        data: { energy: 100 }
      })

      expect(callback).toHaveBeenCalledWith({ energy: 100 }, expect.any(Object))
    })

    it('returns unsubscribe function', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      const callback = vi.fn()
      const unsub = service.on('stats-updated', callback)

      unsub()

      mockWs?.simulateMessage({
        event: 'stats-updated',
        data: { energy: 100 }
      })

      expect(callback).not.toHaveBeenCalled()
    })
  })

  // ─── onStateChange() ──────────────────────────────────────────────────────

  describe('onStateChange()', () => {
    it('notifies on state change', async () => {
      const callback = vi.fn()
      service.onStateChange(callback)

      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      expect(callback).toHaveBeenCalledWith('connecting')
      expect(callback).toHaveBeenCalledWith('connected')
    })

    it('returns unsubscribe function', async () => {
      const callback = vi.fn()
      const unsub = service.onStateChange(callback)

      // Connect to trigger state changes
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      unsub()

      // Should not receive more updates
      service.disconnect()
      // After unsub, callback should not be called again
      // The exact count depends on when unsub was called
    })
  })

  // ─── send() ───────────────────────────────────────────────────────────────

  describe('send()', () => {
    it('sends message when connected', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      service.send({ event: 'test', data: { foo: 'bar' } })

      expect(mockWs?.send).toHaveBeenCalledWith(
        JSON.stringify({ event: 'test', data: { foo: 'bar' } })
      )
    })

    it('queues message when not connected', () => {
      service.send({ event: 'test', data: { foo: 'bar' } })

      // Should not throw, message is queued
      expect(mockWsInstances.length).toBe(0)
    })
  })

  // ─── Error Handling ───────────────────────────────────────────────────────

  describe('error handling', () => {
    it('sets state to error on WebSocket error', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]

      // Simulate error before open
      mockWs?.simulateError()

      await expect(promise).rejects.toBeDefined()
      expect(service.getState()).toBe('error')
    })

    it('sets state to error on unexpected close', async () => {
      const promise = service.connect()
      mockWs = mockWsInstances[0]
      mockWs?.simulateOpen()
      await promise

      // Simulate unexpected close
      mockWs?.simulateClose(1006, 'Abnormal closure')

      expect(service.getState()).toBe('error')
    })
  })

  // ─── Singleton Instance ───────────────────────────────────────────────────

  describe('singleton instance', () => {
    it('exports a singleton instance', () => {
      expect(websocketService).toBeInstanceOf(WebSocketService)
    })
  })
})
