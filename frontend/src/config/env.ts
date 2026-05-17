/**
 * Environment Configuration and Validation
 * Validates required environment variables at startup
 */

/**
 * Environment variable configuration interface
 */
interface EnvConfig {
  VITE_API_URL: string
  VITE_WS_URL: string
  VITE_WS_KEY: string
  VITE_WS_CLUSTER: string
  NODE_ENV: 'development' | 'production' | 'test'
}

/**
 * Required environment variables
 */
const requiredEnvVars: (keyof EnvConfig)[] = []

/**
 * Optional environment variables with defaults
 */
const defaultEnvValues: Partial<EnvConfig> = {
  VITE_API_URL: '',
  VITE_WS_URL: '',
  VITE_WS_KEY: '',
  VITE_WS_CLUSTER: 'mt1',
  NODE_ENV: 'development'
}

/**
 * Get environment variable with fallback
 */
function getEnvVar(key: keyof EnvConfig): string {
  const value = import.meta.env[key] ?? defaultEnvValues[key] ?? ''
  return String(value)
}

/**
 * Validate environment configuration
 * Logs warnings for missing required variables
 */
export function validateEnv(): { valid: boolean; errors: string[] } {
  const errors: string[] = []

  for (const key of requiredEnvVars) {
    const value = getEnvVar(key)
    if (!value) {
      errors.push(`Missing required environment variable: ${key}`)
    }
  }

  if (errors.length > 0) {
    console.warn('Environment validation warnings:', errors)
  }

  return {
    valid: errors.length === 0,
    errors
  }
}

/**
 * Get validated environment configuration
 */
export function getEnvConfig(): EnvConfig {
  return {
    VITE_API_URL: getEnvVar('VITE_API_URL'),
    VITE_WS_URL: getEnvVar('VITE_WS_URL'),
    VITE_WS_KEY: getEnvVar('VITE_WS_KEY'),
    VITE_WS_CLUSTER: getEnvVar('VITE_WS_CLUSTER'),
    NODE_ENV: (getEnvVar('NODE_ENV') as EnvConfig['NODE_ENV']) || 'development'
  }
}

/**
 * Check if running in development mode
 */
export function isDevelopment(): boolean {
  return import.meta.env.DEV
}

/**
 * Check if running in production mode
 */
export function isProduction(): boolean {
  return import.meta.env.PROD
}

/**
 * Application configuration derived from environment
 */
export const config = {
  api: {
    baseUrl: getEnvVar('VITE_API_URL')
  },
  websocket: {
    url: getEnvVar('VITE_WS_URL'),
    key: getEnvVar('VITE_WS_KEY'),
    cluster: getEnvVar('VITE_WS_CLUSTER')
  },
  app: {
    name: 'PBBG Vault',
    version: '1.0.0'
  }
}

export default config
