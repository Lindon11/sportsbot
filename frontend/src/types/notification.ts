/**
 * Notification Type Definitions for Core Web APP OS
 */

/**
 * Notification types - generic types for any application
 */
export type NotificationType = 'info' | 'success' | 'warning' | 'error' | 'message' | 'system' | 'security' | 'update'

/**
 * Notification data payload for actions
 */
export interface NotificationData {
  action?: string
  [key: string]: unknown
}

/**
 * Notification interface
 */
export interface Notification {
  id: number
  type: NotificationType | string
  title: string
  message: string
  link?: string
  data?: NotificationData
  read_at: string | null
  created_at: string
}

/**
 * Notification response from API
 */
export interface NotificationsResponse {
  notifications: Notification[]
}

/**
 * Unread count response from API
 */
export interface NotificationUnreadCountResponse {
  count: number
  unread_count: number
}

/**
 * Notification creation payload (for system/admin use)
 */
export interface CreateNotificationRequest {
  user_id: number
  type: NotificationType
  title: string
  message: string
  link?: string
  data?: NotificationData
}

/**
 * Bulk notification request
 */
export interface BulkNotificationRequest {
  user_ids: number[]
  type: NotificationType
  title: string
  message: string
  link?: string
  data?: NotificationData
}

/**
 * Notification preferences
 */
export interface NotificationPreferences {
  enabled: boolean
  email: boolean
  push: boolean
  sound: boolean
  types: {
    system: boolean
    security: boolean
    updates: boolean
    messages: boolean
  }
}

/**
 * Toast notification for UI display
 */
export interface ToastNotification {
  id: string | number
  type: NotificationType
  title?: string
  message: string
  duration?: number
  dismissible?: boolean
}
