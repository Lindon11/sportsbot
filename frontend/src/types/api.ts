/**
 * API Type Definitions for PBBG Vault
 * Central location for all API-related types
 */

/**
 * Generic API response wrapper
 */
export interface ApiResponse<T = unknown> {
  success: boolean
  data?: T
  message?: string
  alerts?: ApiAlert[]
}

/**
 * API alert/notification message
 */
export interface ApiAlert {
  text: string
  type?: 'info' | 'success' | 'warning' | 'error'
}

/**
 * Paginated API response
 */
export interface PaginatedResponse<T> {
  data: T[]
  current_page: number
  per_page: number
  total: number
  last_page: number
  from: number
  to: number
  has_more_pages: boolean
}

/**
 * API error response
 */
export interface ApiError {
  message: string
  errors?: Record<string, string[]>
  code?: string | number
  status?: number
}

/**
 * Generic API request options
 */
export interface ApiRequestOptions {
  params?: Record<string, unknown>
  headers?: Record<string, string>
  signal?: AbortSignal
}

/**
 * Login request payload
 */
export interface LoginRequest {
  email: string
  password: string
  remember?: boolean
}

/**
 * Login response data
 */
export interface LoginResponse {
  user: User
  token?: string
}

/**
 * Registration request payload
 */
export interface RegisterRequest {
  username: string
  email: string
  password: string
  password_confirmation: string
}

/**
 * Password reset request payload
 */
export interface ForgotPasswordRequest {
  email: string
}

/**
 * Password reset confirmation payload
 */
export interface ResetPasswordRequest {
  token: string
  email: string
  password: string
  password_confirmation: string
}

/**
 * User data returned from API
 */
export interface User {
  id: number
  username: string
  name?: string
  email: string
  avatar?: string
  roles?: string[]
  permissions?: string[]
}

/**
 * Generic single item response
 */
export interface SingleItemResponse<T> {
  item: T
}

/**
 * Generic list response
 */
export interface ListResponse<T> {
  items: T[]
  total?: number
}

/**
 * Generic count response
 */
export interface CountResponse {
  count: number
  total?: number
}

/**
 * Success response with message
 */
export interface SuccessResponse {
  success: true
  message: string
}

/**
 * Delete response
 */
export interface DeleteResponse {
  success: boolean
  message?: string
}

/**
 * Email settings response from API
 */
export interface EmailSettings {
  id?: number
  mailer: string
  host: string
  port: number
  username: string
  password: string
  encryption: string
  from_address: string
  from_name: string
  mailgun_domain: string
  mailgun_secret: string
  mailgun_endpoint: string
  is_active: boolean
  has_password: boolean
  has_mailgun_secret: boolean
  last_tested_at?: string
  test_successful?: boolean
}

/**
 * Email template response from API
 */
export interface EmailTemplate {
  id: number
  name: string
  slug: string
  subject: string
  body: string
  is_active: boolean
  created_at?: string
  updated_at?: string
}

/**
 * Player stats API response
 */
export interface PlayerStatsResponse {
  energy: number
  max_energy: number
  health: number
  max_health: number
  stamina: number
  max_stamina: number
  nerve: number
  max_nerve: number
  cash: number
  bank: number
  points: number
  diamonds: number
  level: number
  experience: number
  timers?: {
    energy: string
    health: string
    stamina: string
    nerve: string
    jail: string | null
    travel: string | null
  }
}

/**
 * User API response
 */
export interface UserApiResponse {
  id: number
  username: string
  name?: string
  email: string
  avatar?: string
  roles?: string[]
  permissions?: string[]
  created_at?: string
  updated_at?: string
}

/**
 * Notification API response
 */
export interface NotificationApiResponse {
  id: number
  type: 'info' | 'success' | 'warning' | 'error'
  title: string
  message: string
  read_at: string | null
  created_at: string
  data?: Record<string, unknown>
}

/**
 * Unread count response
 */
export interface UnreadCountResponse {
  count: number
  unread_count?: number
}

/**
 * Notifications list response
 */
export interface NotificationsListResponse {
  notifications: NotificationApiResponse[]
  unread_count?: number
}

/**
 * Chat message API response
 */
export interface ChatMessageApiResponse {
  id: number
  conversation_id: number
  sender_id: number
  sender_name: string
  content: string
  created_at: string
  read_at?: string | null
}

/**
 * Conversation API response
 */
export interface ConversationApiResponse {
  id: number
  name?: string
  type: 'private' | 'group'
  participants: Array<{
    id: number
    username: string
    avatar?: string
  }>
  last_message?: ChatMessageApiResponse
  unread_count: number
  updated_at: string
}

/**
 * Auth check response
 */
export interface AuthCheckResponse {
  authenticated: boolean
  user?: User
}

/**
 * Login response
 */
export interface LoginApiResponse {
  user: User
  token?: string
  two_factor_required?: boolean
  challenge_token?: string
}

/**
 * Register response
 */
export interface RegisterApiResponse {
  user: User
  token: string
}

/**
 * Re-export user type for convenience
 */
export type { User as ApiUser }
