import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useNotificationsStore } from '@/stores/notifications'
import type { Notification, NotificationType } from '@/types/notification'

// Use vi.hoisted to define mocks before vi.mock is hoisted
const { mockGet, mockPost, mockDelete } = vi.hoisted(() => {
  return {
    mockGet: vi.fn(),
    mockPost: vi.fn(),
    mockDelete: vi.fn(),
  }
})

vi.mock('@/services/api', () => ({
  default: {
    get: mockGet,
    post: mockPost,
    delete: mockDelete,
  },
}))

vi.mock('@/services/websocket', () => ({
  websocketService: {
    connect: vi.fn().mockResolvedValue(undefined),
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    on: vi.fn().mockReturnValue(() => {}),
  },
}))

const makeNotification = (overrides: Partial<Notification> = {}): Notification => ({
  id: 1,
  type: 'system' as NotificationType,
  title: 'Test Notification',
  message: 'This is a test notification',
  link: undefined,
  read_at: null,
  created_at: new Date().toISOString(),
  ...overrides,
})

describe('Notifications Store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
  })

  afterEach(() => {
    vi.clearAllMocks()
    const notifications = useNotificationsStore()
    notifications.clearAll()
  })

  // ─── Initial State ────────────────────────────────────────────────────────

  describe('initial state', () => {
    it('starts with empty notifications', () => {
      const notifications = useNotificationsStore()
      expect(notifications.notifications).toEqual([])
    })

    it('starts with unreadCount = 0', () => {
      const notifications = useNotificationsStore()
      expect(notifications.unreadCount).toBe(0)
    })

    it('starts with loading = false', () => {
      const notifications = useNotificationsStore()
      expect(notifications.loading).toBe(false)
    })

    it('starts with error = null', () => {
      const notifications = useNotificationsStore()
      expect(notifications.error).toBeNull()
    })

    it('starts with connected = false', () => {
      const notifications = useNotificationsStore()
      expect(notifications.connected).toBe(false)
    })
  })

  // ─── Computed Properties ──────────────────────────────────────────────────

  describe('computed properties', () => {
    it('hasUnread is false when unreadCount is 0', () => {
      const notifications = useNotificationsStore()
      expect(notifications.hasUnread).toBe(false)
    })

    it('hasUnread is true when unreadCount > 0', () => {
      const notifications = useNotificationsStore()
      notifications.unreadCount = 5
      expect(notifications.hasUnread).toBe(true)
    })

    it('unreadNotifications returns only unread', () => {
      const notifications = useNotificationsStore()
      notifications.notifications = [
        makeNotification({ id: 1, read_at: null }),
        makeNotification({ id: 2, read_at: new Date().toISOString() }),
        makeNotification({ id: 3, read_at: null }),
      ]

      expect(notifications.unreadNotifications.length).toBe(2)
    })

    it('readNotifications returns only read', () => {
      const notifications = useNotificationsStore()
      notifications.notifications = [
        makeNotification({ id: 1, read_at: null }),
        makeNotification({ id: 2, read_at: new Date().toISOString() }),
        makeNotification({ id: 3, read_at: new Date().toISOString() }),
      ]

      expect(notifications.readNotifications.length).toBe(2)
    })
  })

  // ─── fetchNotifications() ─────────────────────────────────────────────────

  describe('fetchNotifications()', () => {
    it('fetches and sets notifications', async () => {
      const notificationList = [makeNotification(), makeNotification({ id: 2 })]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()

      expect(notifications.notifications.length).toBe(2)
    })

    it('calculates unread count', async () => {
      const notificationList = [
        makeNotification({ id: 1, read_at: null }),
        makeNotification({ id: 2, read_at: new Date().toISOString() }),
        makeNotification({ id: 3, read_at: null }),
      ]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()

      expect(notifications.unreadCount).toBe(2)
    })

    it('sets loading state during fetch', async () => {
      const notificationList = [makeNotification()]
      mockGet.mockImplementation(() => new Promise(resolve => setTimeout(() => resolve({ data: { notifications: notificationList } }), 10)))

      const notifications = useNotificationsStore()
      const promise = notifications.fetchNotifications()

      expect(notifications.loading).toBe(true)
      await promise
      expect(notifications.loading).toBe(false)
    })

    it('sets error on fetch failure', async () => {
      mockGet.mockRejectedValueOnce(new Error('Network error'))

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()

      expect(notifications.error).toBe('Failed to fetch notifications')
    })

    it('handles API response without notifications wrapper', async () => {
      const notificationList = [makeNotification()]
      mockGet.mockResolvedValueOnce({ data: notificationList })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()

      expect(notifications.notifications.length).toBe(1)
    })
  })

  // ─── fetchUnreadCount() ───────────────────────────────────────────────────

  describe('fetchUnreadCount()', () => {
    it('fetches and sets unread count', async () => {
      mockGet.mockResolvedValueOnce({ data: { count: 10 } })

      const notifications = useNotificationsStore()
      await notifications.fetchUnreadCount()

      expect(notifications.unreadCount).toBe(10)
    })

    it('handles unread_count field in response', async () => {
      mockGet.mockResolvedValueOnce({ data: { unread_count: 15 } })

      const notifications = useNotificationsStore()
      await notifications.fetchUnreadCount()

      expect(notifications.unreadCount).toBe(15)
    })
  })

  // ─── markAsRead() ─────────────────────────────────────────────────────────

  describe('markAsRead()', () => {
    it('marks a notification as read', async () => {
      mockPost.mockResolvedValueOnce({})
      const notificationList = [makeNotification({ id: 1 })]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      await notifications.markAsRead(1)

      expect(notifications.notifications[0]!.read_at).not.toBeNull()
    })

    it('decrements unread count', async () => {
      mockPost.mockResolvedValueOnce({})
      const notificationList = [makeNotification({ id: 1 })]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      expect(notifications.unreadCount).toBe(1)
      await notifications.markAsRead(1)
      expect(notifications.unreadCount).toBe(0)
    })

    it('does not decrement unread count if already read', async () => {
      mockPost.mockResolvedValueOnce({})
      const notificationList = [makeNotification({ id: 1, read_at: new Date().toISOString() })]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      const initialCount = notifications.unreadCount
      await notifications.markAsRead(1)
      expect(notifications.unreadCount).toBe(initialCount)
    })
  })

  // ─── markAllAsRead() ──────────────────────────────────────────────────────

  describe('markAllAsRead()', () => {
    it('marks all notifications as read', async () => {
      mockPost.mockResolvedValueOnce({})
      const notificationList = [
        makeNotification({ id: 1 }),
        makeNotification({ id: 2 }),
      ]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      await notifications.markAllAsRead()

      expect(notifications.unreadCount).toBe(0)
      notifications.notifications.forEach(n => {
        expect(n.read_at).not.toBeNull()
      })
    })
  })

  // ─── deleteNotification() ─────────────────────────────────────────────────

  describe('deleteNotification()', () => {
    it('deletes a notification', async () => {
      mockDelete.mockResolvedValueOnce({})
      const notificationList = [makeNotification({ id: 1 })]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      await notifications.deleteNotification(1)

      expect(notifications.notifications.length).toBe(0)
    })

    it('decrements unread count if notification was unread', async () => {
      mockDelete.mockResolvedValueOnce({})
      const notificationList = [makeNotification({ id: 1 })]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      expect(notifications.unreadCount).toBe(1)
      await notifications.deleteNotification(1)
      expect(notifications.unreadCount).toBe(0)
    })
  })

  // ─── addNotification() ────────────────────────────────────────────────────

  describe('addNotification()', () => {
    it('adds a notification to the list', () => {
      const notifications = useNotificationsStore()
      notifications.addNotification(makeNotification())

      expect(notifications.notifications.length).toBe(1)
    })

    it('adds to the beginning of the list', () => {
      const notifications = useNotificationsStore()
      notifications.addNotification(makeNotification({ id: 1 }))
      notifications.addNotification(makeNotification({ id: 2 }))

      expect(notifications.notifications[0]!.id).toBe(2)
    })

    it('increments unread count if unread', () => {
      const notifications = useNotificationsStore()
      notifications.addNotification(makeNotification({ read_at: null }))

      expect(notifications.unreadCount).toBe(1)
    })

    it('does not increment unread count if read', () => {
      const notifications = useNotificationsStore()
      notifications.addNotification(makeNotification({ read_at: new Date().toISOString() }))

      expect(notifications.unreadCount).toBe(0)
    })
  })

  // ─── clearAll() ───────────────────────────────────────────────────────────

  describe('clearAll()', () => {
    it('clears all state', async () => {
      const notificationList = [makeNotification()]
      mockGet.mockResolvedValueOnce({ data: { notifications: notificationList } })

      const notifications = useNotificationsStore()
      await notifications.fetchNotifications()
      notifications.unreadCount = 5

      notifications.clearAll()

      expect(notifications.notifications).toEqual([])
      expect(notifications.unreadCount).toBe(0)
      expect(notifications.error).toBeNull()
    })
  })
})
