/**
 * WebSocket Type Definitions for PBBG Vault
 */

/**
 * Player stats update event data
 */
export interface StatsUpdatedEvent {
  energy?: number
  maxEnergy?: number
  health?: number
  maxHealth?: number
  stamina?: number
  maxStamina?: number
  nerve?: number
  maxNerve?: number
  cash?: number
  bank?: number
  points?: number
  diamonds?: number
  experience?: number
  level?: number
}

/**
 * Notification event data
 */
export interface NotificationEvent {
  id: number
  type: 'info' | 'success' | 'warning' | 'error'
  title: string
  message: string
  link?: string
  created_at: string
}

/**
 * Chat message event data
 */
export interface ChatMessageEvent {
  id: number
  channel_id: number
  user_id: number
  username: string
  content: string
  created_at: string
}

/**
 * Unread count event data
 */
export interface UnreadCountEvent {
  type: 'chat' | 'notification' | 'message'
  count: number
  channel_id?: number
}

/**
 * Player action event data
 */
export interface PlayerActionEvent {
  action: string
  target_id?: number
  target_name?: string
  result: 'success' | 'failure'
  rewards?: {
    cash?: number
    experience?: number
    items?: Array<{ id: number; name: string; quantity: number }>
  }
  timestamp: string
}

/**
 * System alert event data
 */
export interface SystemAlertEvent {
  type: 'maintenance' | 'update' | 'warning' | 'event'
  title: string
  message: string
  link?: string
  dismissible: boolean
  start_time?: string
  end_time?: string
}

/**
 * Event data map for type-safe event handling
 */
export interface WebSocketEventDataMap {
  'stats-updated': StatsUpdatedEvent
  'notification': NotificationEvent
  'chat-message': ChatMessageEvent
  'unread-count': UnreadCountEvent
  'player-action': PlayerActionEvent
  'system-alert': SystemAlertEvent
}

/**
 * Channel types
 */
export type ChannelType = 'public' | 'private' | 'presence'

/**
 * Channel configuration
 */
export interface ChannelConfig {
  name: string
  type: ChannelType
  authRequired: boolean
}

/**
 * Predefined channels
 */
export const CHANNELS = {
  // Public channels
  GLOBAL_CHAT: 'chat.global',
  ANNOUNCEMENTS: 'announcements',

  // Private user channels (requires auth)
  userChannel: (userId: number) => `private-user.${userId}`,

  // Chat channels
  chatChannel: (channelId: number) => `chat.${channelId}`,

  // Gang channels
  gangChannel: (gangId: number) => `private-gang.${gangId}`,

  // Presence channels
  ONLINE_PLAYERS: 'presence-players',
} as const

/**
 * WebSocket configuration interface
 */
export interface WebSocketConfig {
  url: string
  key: string
  cluster: string
  reconnect: boolean
  reconnectInterval: number
  maxReconnectAttempts: number
  heartbeatInterval: number
}
