<template>
  <div class="settings-container">
    <div class="header">
      <div class="header-content">
        <router-link to="/dashboard" class="back-link">← Back</router-link>
      </div>
    </div>

    <div class="content-wrapper">
      <div class="settings-banner">
        <div class="banner-content">
          <div>
            <h1 class="banner-title">⚙️ Settings</h1>
            <p class="banner-subtitle">Manage your account preferences</p>
          </div>
          <div class="banner-icon">🔧</div>
        </div>
      </div>

      <div v-if="loading" class="loading-state">
        <div class="spinner"></div>
      </div>

      <div v-else class="settings-content">
        <!-- Profile Settings -->
        <section class="settings-section">
          <h2 class="section-title">👤 Profile</h2>
          <div class="settings-form">
            <div class="form-group">
              <label>Display Name</label>
              <input v-model="settings.name" type="text" class="form-input" placeholder="Your display name">
            </div>
            <div class="form-group">
              <label>Bio</label>
              <textarea v-model="settings.bio" class="form-textarea" rows="3" placeholder="Tell us about yourself..."></textarea>
            </div>
          </div>
        </section>

        <!-- Notification Settings -->
        <section class="settings-section">
          <h2 class="section-title">🔔 Notifications</h2>
          <div class="settings-form">
            <div class="toggle-group">
              <div class="toggle-item">
                <div class="toggle-info">
                  <span class="toggle-label">Email Notifications</span>
                  <span class="toggle-description">Receive important updates via email</span>
                </div>
                <label class="toggle-switch">
                  <input v-model="settings.email_notifications" type="checkbox">
                  <span class="slider"></span>
                </label>
              </div>
              <div class="toggle-item">
                <div class="toggle-info">
                  <span class="toggle-label">Push Notifications</span>
                  <span class="toggle-description">Get browser push notifications</span>
                </div>
                <label class="toggle-switch">
                  <input v-model="settings.push_notifications" type="checkbox">
                  <span class="slider"></span>
                </label>
              </div>
              <div class="toggle-item">
                <div class="toggle-info">
                  <span class="toggle-label">Sound Effects</span>
                  <span class="toggle-description">Play sounds for notifications and alerts</span>
                </div>
                <label class="toggle-switch">
                  <input v-model="settings.sound_enabled" type="checkbox">
                  <span class="slider"></span>
                </label>
              </div>
            </div>
          </div>
        </section>

        <!-- Privacy Settings -->
        <section class="settings-section">
          <h2 class="section-title">🔒 Privacy</h2>
          <div class="settings-form">
            <div class="toggle-group">
              <div class="toggle-item">
                <div class="toggle-info">
                  <span class="toggle-label">Show Online Status</span>
                  <span class="toggle-description">Let others see when you're online</span>
                </div>
                <label class="toggle-switch">
                  <input v-model="settings.show_online" type="checkbox">
                  <span class="slider"></span>
                </label>
              </div>
              <div class="toggle-item">
                <div class="toggle-info">
                  <span class="toggle-label">Public Profile</span>
                  <span class="toggle-description">Allow others to view your profile</span>
                </div>
                <label class="toggle-switch">
                  <input v-model="settings.public_profile" type="checkbox">
                  <span class="slider"></span>
                </label>
              </div>
            </div>
          </div>
        </section>

        <!-- Security Settings -->
        <section class="settings-section">
          <h2 class="section-title">🛡️ Security</h2>
          <div class="settings-form">
            <div class="form-group">
              <label>Change Password</label>
              <div class="password-form">
                <input v-model="passwordForm.current" type="password" class="form-input" placeholder="Current password">
                <input v-model="passwordForm.new_password" type="password" class="form-input" placeholder="New password">
                <input v-model="passwordForm.confirm" type="password" class="form-input" placeholder="Confirm new password">
                <button @click="changePassword" :disabled="changingPassword" class="btn btn-primary">
                  {{ changingPassword ? 'Changing...' : 'Change Password' }}
                </button>
              </div>
            </div>
            <div class="toggle-group" style="margin-top: 1.5rem;">
              <div class="toggle-item">
                <div class="toggle-info">
                  <span class="toggle-label">Two-Factor Authentication</span>
                  <span class="toggle-description">Add extra security to your account</span>
                </div>
                <button @click="toggle2FA" class="btn btn-secondary">
                  {{ settings.two_factor_enabled ? 'Disable' : 'Enable' }}
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- Save Button -->
        <div class="save-section">
          <div v-if="message" :class="['message', message.type]">
            {{ message.text }}
          </div>
          <button @click="saveSettings" :disabled="saving" class="btn btn-primary btn-large">
            {{ saving ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import api from '@/services/api'
import { useToast } from '@/composables/useToast'

interface Message {
  type: 'success' | 'error'
  text: string
}

const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const changingPassword = ref(false)
const message = ref<Message | null>(null)

const settings = ref({
  name: '',
  bio: '',
  email_notifications: true,
  push_notifications: true,
  sound_enabled: true,
  show_online: true,
  public_profile: true,
  two_factor_enabled: false,
})

const passwordForm = ref({
  current: '',
  new_password: '',
  confirm: '',
})

interface UserResponse {
  user?: {
    name?: string
    bio?: string
    email_notifications?: boolean
    push_notifications?: boolean
    sound_enabled?: boolean
    show_online?: boolean
    public_profile?: boolean
    two_factor_enabled?: boolean
  }
  name?: string
  bio?: string
  email_notifications?: boolean
  push_notifications?: boolean
  sound_enabled?: boolean
  show_online?: boolean
  public_profile?: boolean
  two_factor_enabled?: boolean
}

const loadSettings = async () => {
  try {
    const response = await api.get<UserResponse>('/api/v1/user')
    const user = response.data.user || response.data

    settings.value = {
      name: user.name || '',
      bio: user.bio || '',
      email_notifications: user.email_notifications ?? true,
      push_notifications: user.push_notifications ?? true,
      sound_enabled: user.sound_enabled ?? true,
      show_online: user.show_online ?? true,
      public_profile: user.public_profile ?? true,
      two_factor_enabled: user.two_factor_enabled ?? false,
    }
  } catch (err) {
    console.error('Failed to load settings:', err)
    toast.error('Failed to load settings')
  } finally {
    loading.value = false
  }
}

const saveSettings = async () => {
  saving.value = true
  message.value = null

  try {
    await api.patch('/api/v1/user', settings.value)
    toast.success('Settings saved successfully!')
  } catch (err: unknown) {
    const error = err as { response?: { data?: { message?: string } } }
    toast.error(error.response?.data?.message || 'Failed to save settings')
  } finally {
    saving.value = false
  }
}

const changePassword = async () => {
  if (passwordForm.value.new_password !== passwordForm.value.confirm) {
    toast.error('Passwords do not match')
    return
  }

  if (passwordForm.value.new_password.length < 8) {
    toast.error('Password must be at least 8 characters')
    return
  }

  changingPassword.value = true

  try {
    await api.post('/api/v1/user/change-password', {
      current_password: passwordForm.value.current,
      new_password: passwordForm.value.new_password,
      new_password_confirmation: passwordForm.value.confirm,
    })

    toast.success('Password changed successfully!')
    passwordForm.value = { current: '', new_password: '', confirm: '' }
  } catch (err: unknown) {
    const error = err as { response?: { data?: { message?: string } } }
    toast.error(error.response?.data?.message || 'Failed to change password')
  } finally {
    changingPassword.value = false
  }
}

const toggle2FA = async () => {
  // TODO: Implement 2FA toggle flow
  toast.info('Two-factor authentication setup coming soon!')
}

onMounted(() => {
  loadSettings()
})
</script>

<style scoped>
.settings-container {
  min-height: 100vh;
  background: linear-gradient(to bottom right, #111827, #1f2937, #111827);
}

.header {
  background-color: rgba(31, 41, 55, 0.5);
  padding: 1rem 1.5rem;
}

.header-content {
  max-width: 800px;
  margin: 0 auto;
}

.back-link {
  color: #9ca3af;
  text-decoration: none;
  font-size: 0.875rem;
  transition: color 0.2s;
}

.back-link:hover {
  color: #00bcd4;
}

.content-wrapper {
  max-width: 800px;
  margin: 0 auto;
  padding: 2rem 1rem;
}

.settings-banner {
  background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
  border-radius: 1rem;
  padding: 2rem;
  margin-bottom: 2rem;
  border: 1px solid rgba(75, 85, 99, 0.3);
}

.banner-content {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.banner-title {
  font-size: 2rem;
  font-weight: 700;
  color: #f9fafb;
  margin: 0 0 0.5rem;
}

.banner-subtitle {
  color: #9ca3af;
  margin: 0;
}

.banner-icon {
  font-size: 4rem;
  opacity: 0.5;
}

.loading-state {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 300px;
}

.spinner {
  width: 3rem;
  height: 3rem;
  border: 3px solid rgba(0, 188, 212, 0.2);
  border-top-color: #00bcd4;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.settings-section {
  background: rgba(31, 41, 55, 0.5);
  border-radius: 1rem;
  padding: 1.5rem;
  margin-bottom: 1.5rem;
  border: 1px solid rgba(75, 85, 99, 0.3);
}

.section-title {
  font-size: 1.25rem;
  font-weight: 600;
  color: #f9fafb;
  margin: 0 0 1.5rem;
}

.settings-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.form-group label {
  font-size: 0.875rem;
  font-weight: 500;
  color: #d1d5db;
}

.form-input,
.form-textarea {
  background: rgba(17, 24, 39, 0.5);
  border: 1px solid rgba(75, 85, 99, 0.5);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
  color: #f9fafb;
  font-size: 0.875rem;
  transition: border-color 0.2s;
}

.form-input:focus,
.form-textarea:focus {
  outline: none;
  border-color: #00bcd4;
}

.form-textarea {
  resize: vertical;
  min-height: 80px;
}

.toggle-group {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.toggle-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 1rem;
  background: rgba(17, 24, 39, 0.3);
  border-radius: 0.5rem;
}

.toggle-info {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.toggle-label {
  font-weight: 500;
  color: #f9fafb;
}

.toggle-description {
  font-size: 0.75rem;
  color: #9ca3af;
}

.toggle-switch {
  position: relative;
  width: 48px;
  height: 24px;
  cursor: pointer;
}

.toggle-switch input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: #374151;
  border-radius: 24px;
  transition: 0.3s;
}

.slider:before {
  position: absolute;
  content: '';
  height: 18px;
  width: 18px;
  left: 3px;
  bottom: 3px;
  background: white;
  border-radius: 50%;
  transition: 0.3s;
}

.toggle-switch input:checked + .slider {
  background: #00bcd4;
}

.toggle-switch input:checked + .slider:before {
  transform: translateX(24px);
}

.password-form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.btn {
  padding: 0.75rem 1.5rem;
  border-radius: 0.5rem;
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
  transition: all 0.2s;
  border: none;
}

.btn-primary {
  background: linear-gradient(135deg, #00bcd4 0%, #0891b2 100%);
  color: white;
}

.btn-primary:hover:not(:disabled) {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0, 188, 212, 0.4);
}

.btn-primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn-secondary {
  background: rgba(55, 65, 81, 0.5);
  color: #d1d5db;
  border: 1px solid rgba(75, 85, 99, 0.5);
}

.btn-secondary:hover {
  background: rgba(55, 65, 81, 0.8);
}

.btn-large {
  width: 100%;
  padding: 1rem;
  font-size: 1rem;
}

.save-section {
  margin-top: 2rem;
}

.message {
  padding: 1rem;
  border-radius: 0.5rem;
  margin-bottom: 1rem;
  font-size: 0.875rem;
}

.message.success {
  background: rgba(16, 185, 129, 0.1);
  border: 1px solid rgba(16, 185, 129, 0.3);
  color: #10b981;
}

.message.error {
  background: rgba(239, 68, 68, 0.1);
  border: 1px solid rgba(239, 68, 68, 0.3);
  color: #ef4444;
}

@media (max-width: 640px) {
  .banner-icon {
    display: none;
  }

  .toggle-item {
    flex-direction: column;
    align-items: flex-start;
    gap: 1rem;
  }
}
</style>
