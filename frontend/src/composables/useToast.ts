import { ref } from 'vue'

export interface Toast {
  id: number
  message: string
  type: 'info' | 'success' | 'warning' | 'error'
  duration: number
}

const toasts = ref<Toast[]>([])
let nextId = 0

export function useToast() {
  const show = (message: string, type: Toast['type'] = 'info', duration: number = 3000): number => {
    const id = nextId++
    const toast: Toast = { id, message, type, duration }

    toasts.value.push(toast)

    setTimeout(() => {
      remove(id)
    }, duration)

    return id
  }

  const remove = (id: number): void => {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  const success = (message: string, duration?: number): number => show(message, 'success', duration)
  const error = (message: string, duration?: number): number => show(message, 'error', duration)
  const info = (message: string, duration?: number): number => show(message, 'info', duration)
  const warning = (message: string, duration?: number): number => show(message, 'warning', duration)

  return {
    toasts,
    show,
    remove,
    success,
    error,
    info,
    warning
  }
}
