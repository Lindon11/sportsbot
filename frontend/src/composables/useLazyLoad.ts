/**
 * useLazyLoad Composable
 * Provides lazy loading functionality for images and other assets
 */

import { ref, onMounted, onUnmounted, type Ref } from 'vue'

/**
 * Intersection Observer options
 */
interface LazyLoadOptions {
  /** Root element for intersection */
  root?: Element | null
  /** Margin around the root */
  rootMargin?: string
  /** Threshold for triggering */
  threshold?: number | number[]
}

/**
 * Lazy load an element using Intersection Observer
 */
export function useLazyLoad(
  elementRef: Ref<HTMLElement | null>,
  options: LazyLoadOptions = {}
) {
  const isLoaded = ref(false)
  const isVisible = ref(false)
  const error = ref<Error | null>(null)
  let observer: IntersectionObserver | null = null

  const defaultOptions: IntersectionObserverInit = {
    root: options.root ?? null,
    rootMargin: options.rootMargin ?? '100px',
    threshold: options.threshold ?? 0.1,
  }

  const load = () => {
    isLoaded.value = true
  }

  onMounted(() => {
    if (!elementRef.value) return

    observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          isVisible.value = true
          load()
          // Stop observing once loaded
          if (elementRef.value && observer) {
            observer.unobserve(elementRef.value)
          }
        }
      })
    }, defaultOptions)

    observer.observe(elementRef.value)
  })

  onUnmounted(() => {
    if (observer) {
      observer.disconnect()
      observer = null
    }
  })

  return {
    isLoaded,
    isVisible,
    error,
    load,
  }
}

/**
 * Lazy load an image with preload and error handling
 */
export function useLazyImage(
  imageRef: Ref<HTMLImageElement | null>,
  src: string,
  placeholder?: string
) {
  const isLoaded = ref(false)
  const isLoading = ref(false)
  const error = ref<Error | null>(null)
  const currentSrc = ref(placeholder || '')
  let observer: IntersectionObserver | null = null

  const loadImage = () => {
    if (isLoading.value || isLoaded.value) return

    isLoading.value = true
    const img = new Image()

    img.onload = () => {
      currentSrc.value = src
      isLoaded.value = true
      isLoading.value = false
    }

    img.onerror = () => {
      error.value = new Error(`Failed to load image: ${src}`)
      isLoading.value = false
    }

    img.src = src
  }

  onMounted(() => {
    if (!imageRef.value) {
      // If no ref, load immediately
      loadImage()
      return
    }

    observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            loadImage()
            if (imageRef.value && observer) {
              observer.unobserve(imageRef.value)
            }
          }
        })
      },
      {
        rootMargin: '100px',
        threshold: 0.1,
      }
    )

    observer.observe(imageRef.value)
  })

  onUnmounted(() => {
    if (observer) {
      observer.disconnect()
      observer = null
    }
  })

  return {
    isLoaded,
    isLoading,
    error,
    currentSrc,
    loadImage,
  }
}

/**
 * Preload images for better performance
 */
export function useImagePreloader() {
  const loadedImages = ref<Set<string>>(new Set())
  const loadingImages = ref<Set<string>>(new Set())
  const errors = ref<Map<string, Error>>(new Map())

  const preload = (urls: string | string[]): Promise<void[]> => {
    const urlArray = Array.isArray(urls) ? urls : [urls]
    const promises = urlArray.map((url) => {
      // Skip if already loaded or loading
      if (loadedImages.value.has(url)) {
        return Promise.resolve<void>(undefined)
      }
      if (loadingImages.value.has(url)) {
        return new Promise<void>((resolve) => {
          // Wait for existing load to complete
          const checkLoaded = () => {
            if (loadedImages.value.has(url)) {
              resolve()
            } else if (errors.value.has(url)) {
              resolve() // Resolve anyway, error is tracked
            } else {
              requestAnimationFrame(checkLoaded)
            }
          }
          checkLoaded()
        })
      }

      loadingImages.value.add(url)

      return new Promise<void>((resolve) => {
        const img = new Image()
        img.onload = () => {
          loadedImages.value.add(url)
          loadingImages.value.delete(url)
          resolve()
        }
        img.onerror = () => {
          errors.value.set(url, new Error(`Failed to preload: ${url}`))
          loadingImages.value.delete(url)
          resolve() // Resolve anyway to not block other preloads
        }
        img.src = url
      })
    })

    return Promise.all(promises)
  }

  const isLoaded = (url: string): boolean => loadedImages.value.has(url)
  const isLoading = (url: string): boolean => loadingImages.value.has(url)
  const hasError = (url: string): boolean => errors.value.has(url)

  return {
    loadedImages,
    loadingImages,
    errors,
    preload,
    isLoaded,
    isLoading,
    hasError,
  }
}

/**
 * Create a lazy loading directive for images
 */
export const vLazyImage = {
  mounted(el: HTMLImageElement, binding: { value: string }) {
    const src = binding.value
    const placeholder = el.dataset.placeholder || ''

    // Set placeholder initially
    if (placeholder) {
      el.src = placeholder
    }

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            el.src = src
            observer.unobserve(el)
          }
        })
      },
      {
        rootMargin: '100px',
        threshold: 0.1,
      }
    )

    observer.observe(el)

    // Store observer for cleanup
    ;(el as HTMLImageElement & { _lazyObserver?: IntersectionObserver })._lazyObserver = observer
  },
  unmounted(el: HTMLImageElement) {
    const observer = (el as HTMLImageElement & { _lazyObserver?: IntersectionObserver })._lazyObserver
    if (observer) {
      observer.disconnect()
    }
  },
}

export default {
  useLazyLoad,
  useLazyImage,
  useImagePreloader,
  vLazyImage,
}
