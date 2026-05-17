<template>
  <div class="profile-container">
    <div class="header">
      <div class="header-content">
        <router-link to="/dashboard" class="back-link">← Back to Dashboard</router-link>
      </div>
    </div>

    <div class="content-wrapper">
      <div class="profile-banner">
        <div class="banner-content">
          <div class="avatar-section">
            <div class="avatar-wrapper">
              <img
                v-if="userStore.avatar"
                :src="userStore.avatar"
                :alt="userStore.displayName"
                class="avatar-image"
              />
              <div v-else class="avatar-placeholder">
                {{ userStore.displayName.charAt(0).toUpperCase() }}
              </div>
              <label class="avatar-upload">
                <input type="file" accept="image/*" @change="handleAvatarUpload" hidden />
                <span class="upload-icon">📷</span>
              </label>
            </div>
          </div>
          <div class="user-info">
            <h1 class="user-name">{{ userStore.displayName }}</h1>
            <p class="user-email">{{ userStore.email }}</p>
            <div class="user-meta">
              <span class="meta-item">
                <span class="meta-icon">👤</span>
                {{ userStore.username }}
              </span>
              <span v-if="userStore.isAdmin" class="meta-item admin-badge">
                <span class="meta-icon">🛡️</span>
                Admin
              </span>
            </div>
          </div>
        </div>
      </div>

      <div v-if="loading" class="loading-state">
        <div class="spinner"></div>
      </div>

      <div v-else class="profile-content">
        <!-- Basic Info -->
        <section class="profile-section">
          <h2 class="section-title">📋 Basic Information</h2>
          <div class="profile-form">
            <div class="form-row">
              <div class="form-group">
                <label>Display Name</label>
                <input
                  v-model="profileForm.name"
                  type="text"
                  class="form-input"
                  placeholder="Your display name"
                />
              </div>
              <div class="form-group">
                <label>Username</label>
                <input
                  v-model="profileForm.username"
                  type="text"
                  class="form-input"
                  disabled
                  placeholder="Username (cannot change)"
                />
              </div>
            </div>
            <div class="form-group">
              <label>Email</label>
              <input
                v-model="profileForm.email"
                type="email"
                class="form-input"
                disabled
                placeholder="Email address"
              />
            </div>
            <div class="form-group">
              <label>Bio</label>
              <textarea
                v-model="profileForm.bio"
                class="form-textarea"
                rows="4"
                placeholder="Tell us about yourself..."
              ></textarea>
            </div>
          </div>
        </section>

        <!-- Preferences -->
        <section class="profile-section">
          <h2 class="section-title">🌐 Preferences</h2>
          <div class="profile-form">
            <div class="form-row">
              <div class="form-group">
                <label>Timezone</label>
                <select v-model="profileForm.timezone" class="form-select">
                  <option value="">Select timezone</option>
                  <option value="America/New_York">Eastern Time (ET)</option>
                  <option value="America/Chicago">Central Time (CT)</option>
                  <option value="America/Denver">Mountain Time (MT)</option>
                  <option value="America/Los_Angeles">Pacific Time (PT)</option>
                  <option value="Europe/London">London (GMT)</option>
                  <option value="Europe/Paris">Paris (CET)</option>
                  <option value="Asia/Tokyo">Tokyo (JST)</option>
                  <option value="UTC">UTC</option>
                </select>
              </div>
              <div class="form-group">
                <label>Language</label>
                <select v-model="profileForm.locale" class="form-select">
                  <option value="en">English</option>
                  <option value="es">Spanish</option>
                  <option value="fr">French</option>
                  <option value="de">German</option>
                  <option value="ja">Japanese</option>
                </select>
              </div>
            </div>
          </div>
        </section>

        <!-- Save Button -->
        <div class="save-section">
          <div v-if="message" :class="['message', message.type]">
            {{ message.text }}
          </div>
          <button
            @click="saveProfile"
            :disabled="saving"
            class="btn btn-primary btn-large"
          >
            {{ saving ? 'Saving...' : 'Save Changes' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useUserStore } from '@/stores/user'
import { useToast } from '@/composables/useToast'

interface Message {
  type: 'success' | 'error'
  text: string
}

const userStore = useUserStore()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const message = ref<Message | null>(null)

const profileForm = ref({
  name: '',
  username: '',
  email: '',
  bio: '',
  timezone: '',
  locale: 'en',
})

const loadProfile = async () => {
  try {
    await userStore.fetchProfile()

    profileForm.value = {
      name: userStore.profile?.name || '',
      username: userStore.profile?.username || '',
      email: userStore.profile?.email || '',
      bio: userStore.profile?.bio || '',
      timezone: userStore.profile?.timezone || '',
      locale: userStore.profile?.locale || 'en',
    }
  } catch (err) {
    console.error('Failed to load profile:', err)
    toast.error('Failed to load profile')
  } finally {
    loading.value = false
  }
}

const saveProfile = async () => {
  saving.value = true
  message.value = null

  try {
    await userStore.updateProfile({
      name: profileForm.value.name,
      bio: profileForm.value.bio,
      timezone: profileForm.value.timezone,
      locale: profileForm.value.locale,
    })
    message.value = { type: 'success', text: 'Profile saved successfully!' }
    toast.success('Profile saved successfully!')
  } catch (err) {
    console.error('Failed to save profile:', err)
    message.value = { type: 'error', text: 'Failed to save profile' }
    toast.error('Failed to save profile')
  } finally {
    saving.value = false
  }
}

const handleAvatarUpload = async (event: Event) => {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (!file) return

  // Validate file type
  if (!file.type.startsWith('image/')) {
    toast.error('Please select an image file')
    return
  }

  // Validate file size (max 2MB)
  if (file.size > 2 * 1024 * 1024) {
    toast.error('Image must be less than 2MB')
    return
  }

  try {
    await userStore.updateAvatar(file)
    toast.success('Avatar updated successfully!')
  } catch (err) {
    console.error('Failed to upload avatar:', err)
    toast.error('Failed to upload avatar')
  }
}

onMounted(() => {
  loadProfile()
})
</script>

<style scoped>
.profile-container {
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

.profile-banner {
  background: linear-gradient(135deg, #1f2937 0%, #374151 100%);
  border-radius: 1rem;
  padding: 2rem;
  margin-bottom: 2rem;
  border: 1px solid rgba(75, 85, 99, 0.3);
}

.banner-content {
  display: flex;
  align-items: center;
  gap: 2rem;
}

.avatar-section {
  flex-shrink: 0;
}

.avatar-wrapper {
  position: relative;
  width: 100px;
  height: 100px;
}

.avatar-image {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid #00bcd4;
}

.avatar-placeholder {
  width: 100%;
  height: 100%;
  border-radius: 50%;
  background: linear-gradient(135deg, #00bcd4 0%, #0891b2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  font-weight: 700;
  color: white;
  border: 3px solid #00bcd4;
}

.avatar-upload {
  position: absolute;
  bottom: 0;
  right: 0;
  background: #1f2937;
  border: 2px solid #374151;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all 0.2s;
}

.avatar-upload:hover {
  background: #374151;
  border-color: #00bcd4;
}

.upload-icon {
  font-size: 0.875rem;
}

.user-info {
  flex: 1;
}

.user-name {
  font-size: 1.75rem;
  font-weight: 700;
  color: #f9fafb;
  margin: 0 0 0.25rem;
}

.user-email {
  color: #9ca3af;
  margin: 0 0 0.75rem;
}

.user-meta {
  display: flex;
  gap: 1rem;
}

.meta-item {
  display: flex;
  align-items: center;
  gap: 0.375rem;
  font-size: 0.875rem;
  color: #d1d5db;
}

.meta-icon {
  font-size: 1rem;
}

.admin-badge {
  background: rgba(0, 188, 212, 0.2);
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  color: #00bcd4;
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

.profile-section {
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

.profile-form {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.form-row {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
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
.form-textarea,
.form-select {
  background: rgba(17, 24, 39, 0.5);
  border: 1px solid rgba(75, 85, 99, 0.5);
  border-radius: 0.5rem;
  padding: 0.75rem 1rem;
  color: #f9fafb;
  font-size: 0.875rem;
  transition: border-color 0.2s;
}

.form-input:focus,
.form-textarea:focus,
.form-select:focus {
  outline: none;
  border-color: #00bcd4;
}

.form-input:disabled,
.form-select:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.form-textarea {
  resize: vertical;
  min-height: 100px;
}

.form-select {
  cursor: pointer;
}

.save-section {
  margin-top: 1rem;
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

.btn-large {
  width: 100%;
  padding: 1rem;
  font-size: 1rem;
}

@media (max-width: 640px) {
  .banner-content {
    flex-direction: column;
    text-align: center;
  }

  .user-meta {
    justify-content: center;
  }

  .form-row {
    grid-template-columns: 1fr;
  }
}
</style>
