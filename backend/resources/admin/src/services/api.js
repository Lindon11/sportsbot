import axios from 'axios'

const api = axios.create({
  baseURL: '/api/v1',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})

// Add token to requests
api.interceptors.request.use(config => {
  const token = localStorage.getItem('admin_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Handle 401 and 423 responses
api.interceptors.response.use(
  response => response,
  error => {
    if (error.response?.status === 401) {
      localStorage.removeItem('admin_token')
      localStorage.removeItem('admin_user')
      window.location.href = '/admin/login'
    }
    // 423 Locked = no valid license — redirect to license activation gate
    if (error.response?.status === 423 && error.response?.data?.error === 'license_required') {
      if (!window.location.pathname.includes('/license-required')) {
        window.location.href = '/admin/license-required'
      }
    }
    return Promise.reject(error)
  }
)

export default api
