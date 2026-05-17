/**
 * OpenPBBG Service Worker
 * Handles caching for static assets and API responses
 */

const CACHE_VERSION = 'v1'
const STATIC_CACHE = `openpbbg-static-${CACHE_VERSION}`
const API_CACHE = `openpbbg-api-${CACHE_VERSION}`
const IMAGE_CACHE = `openpbbg-images-${CACHE_VERSION}`

// Static assets to cache on install
const STATIC_ASSETS = [
  '/',
  '/index.html',
  '/favicon.ico',
]

// Cache durations (in milliseconds)
const API_CACHE_DURATION = 5 * 60 * 1000 // 5 minutes
const IMAGE_CACHE_DURATION = 24 * 60 * 60 * 1000 // 24 hours

// API routes to cache
const CACHEABLE_API_ROUTES = [
  '/api/user',
  '/api/player',
  '/api/settings',
  '/api/game-data',
]

/**
 * Install event - cache static assets
 */
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => {
      console.log('[SW] Caching static assets')
      return cache.addAll(STATIC_ASSETS)
    })
  )
  // Activate immediately
  self.skipWaiting()
})

/**
 * Activate event - clean up old caches
 */
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames
          .filter((name) => {
            return (
              name.startsWith('openpbbg-') &&
              name !== STATIC_CACHE &&
              name !== API_CACHE &&
              name !== IMAGE_CACHE
            )
          })
          .map((name) => {
            console.log('[SW] Deleting old cache:', name)
            return caches.delete(name)
          })
      )
    })
  )
  // Take control of all pages immediately
  self.clients.claim()
})

/**
 * Check if a request is cacheable API
 */
function isCacheableApiRequest(url) {
  const pathname = new URL(url).pathname
  return CACHEABLE_API_ROUTES.some((route) => pathname.startsWith(route))
}

/**
 * Check if cached response is still valid
 */
function isCacheValid(response, maxAge) {
  if (!response) return false

  const dateHeader = response.headers.get('sw-cache-date')
  if (!dateHeader) return false

  const cacheDate = parseInt(dateHeader, 10)
  return Date.now() - cacheDate < maxAge
}

/**
 * Fetch event - serve from cache or network
 */
self.addEventListener('fetch', (event) => {
  const { request } = event
  const url = new URL(request.url)

  // Skip non-GET requests for caching
  if (request.method !== 'GET') {
    return
  }

  // Handle different request types
  if (url.pathname.startsWith('/api/')) {
    // API requests - network first with cache fallback
    event.respondWith(handleApiRequest(request))
  } else if (/\.(png|jpe?g|gif|svg|webp|avif|ico)$/i.test(url.pathname)) {
    // Image requests - cache first
    event.respondWith(handleImageRequest(request))
  } else if (
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.woff2') ||
    url.pathname.endsWith('.woff')
  ) {
    // Static assets - cache first
    event.respondWith(handleStaticRequest(request))
  }
  // Let other requests pass through
})

/**
 * Handle API requests - network first with cache fallback
 */
async function handleApiRequest(request) {
  const cache = await caches.open(API_CACHE)

  // Only cache specific API routes
  if (!isCacheableApiRequest(request.url)) {
    return fetch(request)
  }

  try {
    // Try network first
    const networkResponse = await fetch(request)

    // Cache successful responses
    if (networkResponse.ok) {
      const responseToCache = networkResponse.clone()
      // Add cache date header
      const headers = new Headers(responseToCache.headers)
      headers.set('sw-cache-date', Date.now().toString())

      const cachedResponse = new Response(await responseToCache.blob(), {
        status: responseToCache.status,
        statusText: responseToCache.statusText,
        headers,
      })

      cache.put(request, cachedResponse)
    }

    return networkResponse
  } catch {
    // Network failed, try cache
    const cachedResponse = await cache.match(request)

    if (cachedResponse && isCacheValid(cachedResponse, API_CACHE_DURATION)) {
      console.log('[SW] Serving API from cache:', request.url)
      return cachedResponse
    }

    // Return error response
    return new Response(JSON.stringify({ error: 'Network error' }), {
      status: 503,
      headers: { 'Content-Type': 'application/json' },
    })
  }
}

/**
 * Handle image requests - cache first
 */
async function handleImageRequest(request) {
  const cache = await caches.open(IMAGE_CACHE)

  // Check cache first
  const cachedResponse = await cache.match(request)

  if (cachedResponse && isCacheValid(cachedResponse, IMAGE_CACHE_DURATION)) {
    return cachedResponse
  }

  try {
    // Fetch from network
    const networkResponse = await fetch(request)

    if (networkResponse.ok) {
      // Cache the image
      const responseToCache = networkResponse.clone()
      const headers = new Headers(responseToCache.headers)
      headers.set('sw-cache-date', Date.now().toString())

      const cachedResponse = new Response(await responseToCache.blob(), {
        status: networkResponse.status,
        statusText: networkResponse.statusText,
        headers,
      })

      cache.put(request, cachedResponse)
    }

    return networkResponse
  } catch {
    // Return cached version if available (even if expired)
    if (cachedResponse) {
      return cachedResponse
    }

    // Return placeholder or error
    return new Response('', { status: 404 })
  }
}

/**
 * Handle static asset requests - cache first
 */
async function handleStaticRequest(request) {
  const cache = await caches.open(STATIC_CACHE)

  // Check cache first
  const cachedResponse = await cache.match(request)

  if (cachedResponse) {
    // Update cache in background (stale-while-revalidate)
    fetch(request)
      .then((networkResponse) => {
        if (networkResponse.ok) {
          cache.put(request, networkResponse)
        }
      })
      .catch(() => {
        // Ignore network errors
      })

    return cachedResponse
  }

  try {
    // Fetch from network
    const networkResponse = await fetch(request)

    if (networkResponse.ok) {
      cache.put(request, networkResponse.clone())
    }

    return networkResponse
  } catch {
    return new Response('', { status: 404 })
  }
}

/**
 * Handle messages from the main thread
 */
self.addEventListener('message', (event) => {
  if (event.data === 'skipWaiting') {
    self.skipWaiting()
  }

  if (event.data === 'clearCache') {
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((name) => caches.delete(name))
      )
    })
  }

  if (event.data?.type === 'clearApiCache') {
    caches.delete(API_CACHE)
  }
})

console.log('[SW] Service Worker loaded')
