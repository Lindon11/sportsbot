/**
 * Frontend Error Logging Plugin for PBBG Vault
 * Captures and logs frontend errors to the backend
 */
import axios, { type AxiosError, type AxiosInstance } from 'axios'
import type { App } from 'vue'

const API_BASE = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

/**
 * Error payload for logging
 */
interface ErrorPayload {
  message: string
  source?: string
  line?: number
  column?: number
  stack?: string | null
  url?: string
  user_agent?: string
  severity?: string
}

/**
 * API Error payload for logging
 */
interface ApiErrorPayload {
  endpoint: string
  method: string
  status_code: number
  error_message: string
  request_data?: unknown
  response_data?: unknown
}

/**
 * Vue Error payload for logging
 */
interface VueErrorPayload {
  error: string
  component: string
  hook?: string | null
  info?: string | null
}

/**
 * Error Logger class for managing error reporting
 */
class ErrorLogger {
  private isEnabled: boolean = true
  private maxErrorsPerMinute: number = 10
  private errorCount: number = 0
  private lastResetTime: number = Date.now()

  /**
   * Check if we should log this error (rate limiting)
   */
  private shouldLog(): boolean {
    const now = Date.now()

    // Reset counter every minute
    if (now - this.lastResetTime > 60000) {
      this.errorCount = 0
      this.lastResetTime = now
    }

    // Check if we're over the limit
    if (this.errorCount >= this.maxErrorsPerMinute) {
      console.warn('Error logging rate limit reached')
      return false
    }

    this.errorCount++
    return this.isEnabled
  }

  /**
   * Log JavaScript error
   */
  async logError(
    error: Error | string,
    source: string = 'unknown',
    line: number = 0,
    column: number = 0
  ): Promise<void> {
    if (!this.shouldLog()) return

    const payload: ErrorPayload = {
      message: typeof error === 'string' ? error : error.message || String(error),
      source,
      line,
      column,
      stack: typeof error === 'object' ? error.stack : null,
      url: window.location.href,
      user_agent: navigator.userAgent,
      severity: 'error'
    }

    try {
      await axios.post(`${API_BASE}/log-frontend-error`, payload, {
        headers: { 'Content-Type': 'application/json' }
      })
    } catch (err) {
      console.error('Failed to log error:', err)
    }
  }

  /**
   * Log API error
   */
  async logApiError(
    endpoint: string,
    method: string,
    statusCode: number,
    errorMessage: string,
    requestData: unknown = null,
    responseData: unknown = null
  ): Promise<void> {
    if (!this.shouldLog()) return

    const payload: ApiErrorPayload = {
      endpoint,
      method,
      status_code: statusCode,
      error_message: errorMessage,
      request_data: requestData,
      response_data: responseData
    }

    try {
      await axios.post(`${API_BASE}/log-api-error`, payload, {
        headers: { 'Content-Type': 'application/json' }
      })
    } catch (err) {
      console.error('Failed to log API error:', err)
    }
  }

  /**
   * Log Vue component error
   */
  async logVueError(
    error: Error | string,
    component: string,
    hook: string | null = null,
    info: string | null = null
  ): Promise<void> {
    if (!this.shouldLog()) return

    const payload: VueErrorPayload = {
      error: typeof error === 'string' ? error : error.message || String(error),
      component,
      hook,
      info
    }

    try {
      await axios.post(`${API_BASE}/log-vue-error`, payload, {
        headers: { 'Content-Type': 'application/json' }
      })
    } catch (err) {
      console.error('Failed to log Vue error:', err)
    }
  }
}

const errorLogger = new ErrorLogger()

/**
 * Vue Plugin for error logging
 */
export default {
  install(app: App) {
    // Global error handler for Vue
    app.config.errorHandler = (err: unknown, instance: unknown, info: string) => {
      console.error('Vue Error:', err, info)

      const component = (instance as { $options?: { name?: string; __name?: string } })?.$options
      const componentName = component?.name || component?.__name || 'UnknownComponent'

      errorLogger.logVueError(err as Error, componentName, null, info)
    }

    // Global warning handler
    app.config.warnHandler = (msg: string, _instance: unknown, _trace: string) => {
      console.warn('Vue Warning:', msg)
      // Don't log warnings to backend (too noisy)
    }

    // Make error logger available globally
    app.config.globalProperties.$errorLogger = errorLogger
  }
}

/**
 * Setup global error handlers for unhandled errors
 */
export function setupGlobalErrorHandlers(): void {
  // Catch unhandled errors
  window.addEventListener('error', (event: ErrorEvent) => {
    console.error('Unhandled Error:', event.error)

    errorLogger.logError(
      event.error || new Error(event.message),
      event.filename,
      event.lineno,
      event.colno
    )
  })

  // Catch unhandled promise rejections
  window.addEventListener('unhandledrejection', (event: PromiseRejectionEvent) => {
    console.error('Unhandled Promise Rejection:', event.reason)

    errorLogger.logError(
      new Error(`Unhandled Promise Rejection: ${event.reason}`),
      'promise',
      0,
      0
    )
  })
}

/**
 * Axios interceptor for API errors
 */
export function setupAxiosErrorInterceptor(axiosInstance: AxiosInstance): void {
  axiosInstance.interceptors.response.use(
    (response) => response,
    (error: AxiosError) => {
      // Only log actual errors, not auth failures
      if (error.response && error.response.status >= 500) {
        const config = error.config
        errorLogger.logApiError(
          config?.url || 'unknown',
          config?.method?.toUpperCase() || 'GET',
          error.response.status,
          (error.response.data as { message?: string })?.message || error.message,
          config?.data ? JSON.parse(config.data) : null,
          error.response?.data || null
        )
      }

      return Promise.reject(error)
    }
  )
}

export { errorLogger }
