/**
 * useServiceWorker Composable
 * Handles service worker registration and updates
 */

import { ref, onMounted, onUnmounted } from 'vue'

interface ServiceWorkerStatus {
  isRegistered: boolean
  isUpdateAvailable: boolean
  isOffline: boolean
  registration: ServiceWorkerRegistration | null
}

export function useServiceWorker() {
  const status = ref<ServiceWorkerStatus>({
    isRegistered: false,
    isUpdateAvailable: false,
    isOffline: !navigator.onLine,
    registration: null,
  })

  let registration: ServiceWorkerRegistration | null = null

  /**
   * Register the service worker
   */
  const register = async (): Promise<boolean> => {
    if (!('serviceWorker' in navigator)) {
      return false
    }

    try {
      registration = await navigator.serviceWorker.register('/sw.js', {
        scope: '/',
      })

      status.value.isRegistered = true
      status.value.registration = registration


      // Check for updates
      registration.addEventListener('updatefound', () => {
        const newWorker = registration?.installing
        if (!newWorker) return

        newWorker.addEventListener('statechange', () => {
          if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
            // New content is available
            status.value.isUpdateAvailable = true
          }
        })
      })

      return true
    } catch {
      return false
    }
  }

  /**
   * Update the service worker
   */
  const update = async (): Promise<void> => {
    if (!registration) return

    try {
      await registration.update()
    } catch {
      // Update failed - will retry on next registration
    }
  }

  /**
   * Activate the waiting service worker
   */
  const activateUpdate = async (): Promise<void> => {
    if (!registration?.waiting) return

    // Send skipWaiting message to the waiting worker
    registration.waiting.postMessage('skipWaiting')

    // Wait for the new worker to take control
    return new Promise((resolve) => {
      const checkController = () => {
        if (registration?.active === navigator.serviceWorker.controller) {
          resolve()
        } else {
          setTimeout(checkController, 100)
        }
      }
      checkController()
    })
  }

  /**
   * Clear all caches
   */
  const clearCache = async (): Promise<void> => {
    if (!navigator.serviceWorker.controller) return

    return new Promise((resolve) => {
      const messageChannel = new MessageChannel()
      messageChannel.port1.onmessage = () => {
        resolve()
      }
      navigator.serviceWorker.controller?.postMessage('clearCache', [messageChannel.port2])
    })
  }

  /**
   * Clear API cache only
   */
  const clearApiCache = async (): Promise<void> => {
    if (!navigator.serviceWorker.controller) return

    navigator.serviceWorker.controller.postMessage({ type: 'clearApiCache' })
  }

  // Handle online/offline status
  const handleOnline = () => {
    status.value.isOffline = false
  }

  const handleOffline = () => {
    status.value.isOffline = true
  }

  // Handle controller change (new SW activated)
  const handleControllerChange = () => {
    // Reload the page when a new service worker takes control
    window.location.reload()
  }

  onMounted(async () => {
    // Register service worker
    await register()

    // Listen for online/offline events
    window.addEventListener('online', handleOnline)
    window.addEventListener('offline', handleOffline)

    // Listen for controller changes
    navigator.serviceWorker?.addEventListener('controllerchange', handleControllerChange)
  })

  onUnmounted(() => {
    window.removeEventListener('online', handleOnline)
    window.removeEventListener('offline', handleOffline)
    navigator.serviceWorker?.removeEventListener('controllerchange', handleControllerChange)
  })

  return {
    status,
    register,
    update,
    activateUpdate,
    clearCache,
    clearApiCache,
  }
}

export default useServiceWorker
