<template>
  <div class="register-container">
    <!-- Left Side - Branding -->
    <div class="branding-side">
      <div class="pattern-overlay">
        <div class="pattern-bg" style="background-image: url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmYiIGZpbGwtb3BhY2l0eT0iMC4xIj48cGF0aCBkPSJNMzYgMzRjMC0yLjIxLTEuNzktNC00LTRzLTQgMS43OS00IDQgMS43OSA0IDQgNCA0LTEuNzkgNC00em0wLTEwYzAtMi4yMS0xLjc5LTQtNC00cy00IDEuNzktNCA0IDEuNzkgNCA0IDQgNC0xLjc5IDQtNHptMC0xMGMwLTIuMjEtMS43OS00LTQtNHMtNCAxLjc5LTQgNCAxLjc5IDQgNCA0IDQtMS43OSA0LTR6TTI2IDM0YzAtMi4yMS0xLjc5LTQtNC00cy00IDEuNzktNCA0IDEuNzkgNCA0IDQgNC0xLjc5IDQtNHptMC0xMGMwLTIuMjEtMS43OS00LTQtNHMtNCAxLjc5LTQgNCAxLjc5IDQgNCA0IDQtMS43OSA0LTR6bTAtMTBjMC0yLjIxLTEuNzktNC00LTRzLTQgMS43OS00IDQgMS43OSA0IDQgNCA0LTEuNzkgNC00ek00NiAzNGMwLTIuMjEtMS43OS00LTQtNHMtNCAxLjc5LTQgNCAxLjc5IDQgNCA0IDQtMS43OSA0LTR6bTAtMTBjMC0yLjIxLTEuNzktNC00LTRzLTQgMS43OS00IDQgMS43OSA0IDQgNCA0LTEuNzkgNC00em0wLTEwYzAtMi4yMS0xLjc5LTQtNC00cy00IDEuNzktNCA0IDEuNzkgNCA0IDQgNC0xLjc5IDQtNHoiLz48L2c+PC9nPjwvc3ZnPg==')"></div>
      </div>
      <div class="branding-content">
        <h1 class="branding-title">🔐 PBBG Vault</h1>
        <p class="branding-subtitle">The PBBG Development Platform</p>
        <div class="features">
          <div class="feature-item">
            <span class="feature-icon">🔌</span>
            <div>
              <h3 class="feature-title">Plugin System</h3>
              <p class="feature-desc">Extend functionality with modular plugins</p>
            </div>
          </div>
          <div class="feature-item">
            <span class="feature-icon">🎨</span>
            <div>
              <h3 class="feature-title">Theme Engine</h3>
              <p class="feature-desc">Customize the look and feel of your game</p>
            </div>
          </div>
          <div class="feature-item">
            <span class="feature-icon">📦</span>
            <div>
              <h3 class="feature-title">Plugin Bundles</h3>
              <p class="feature-desc">Package and distribute your creations</p>
            </div>
          </div>
          <div class="feature-item">
            <span class="feature-icon">⚡</span>
            <div>
              <h3 class="feature-title">Real-time Updates</h3>
              <p class="feature-desc">WebSocket-powered live features</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Side - Register Form -->
    <div class="form-side">
      <div class="register-card">
        <!-- Mobile Logo -->
        <div class="mobile-logo">
          <h1 class="mobile-title">🔐 PBBG Vault</h1>
          <p class="mobile-subtitle">The PBBG Development Platform</p>
        </div>

        <div class="register-header">
          <h2 class="register-title">Create Account</h2>
          <p class="register-subtitle">Join the developer community</p>
        </div>

        <form class="register-form" @submit.prevent="handleRegister">
          <div v-if="authStore.error" class="error-message">
            {{ authStore.error }}
          </div>

          <div class="form-fields">
            <div>
              <input
                v-model="form.username"
                type="text"
                required
                class="form-input"
                placeholder="Username"
              />
            </div>
            <div>
              <input
                v-model="form.email"
                type="email"
                required
                class="form-input"
                placeholder="Email"
              />
            </div>
            <div>
              <input
                v-model="form.password"
                type="password"
                required
                minlength="8"
                class="form-input"
                placeholder="Password (min 8 characters)"
              />
            </div>
            <div>
              <input
                v-model="form.password_confirmation"
                type="password"
                required
                class="form-input"
                placeholder="Confirm Password"
              />
            </div>
          </div>

          <div>
            <button
              type="submit"
              :disabled="authStore.loading"
              class="submit-btn"
            >
              <span v-if="authStore.loading">Creating account...</span>
              <span v-else>Create Account</span>
            </button>
          </div>

          <div class="login-link">
            <router-link to="/login">
              Already have an account? Sign in
            </router-link>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUserStore } from '@/stores/user'

const router = useRouter()
const authStore = useAuthStore()
const userStore = useUserStore()

const form = ref({
  username: '',
  email: '',
  password: '',
  password_confirmation: ''
})

async function handleRegister() {
  const success = await authStore.register(form.value)
  if (success) {
    // Fetch user profile data after successful registration
    await userStore.fetchProfile()
    router.push('/dashboard')
  }
}
</script>

<style scoped>
.register-container {
  min-height: 100vh;
  display: flex;
}

/* Left Side - Branding */
.branding-side {
  display: none;
  width: 50%;
  background: linear-gradient(to bottom right, #78350f, #1f2937, #000000);
  position: relative;
  overflow: hidden;
}

@media (min-width: 1024px) {
  .branding-side {
    display: flex;
  }
}

.pattern-overlay {
  position: absolute;
  inset: 0;
  opacity: 0.1;
}

.pattern-bg {
  position: absolute;
  inset: 0;
  background-repeat: repeat;
}

.branding-content {
  position: relative;
  z-index: 10;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 0 3rem;
  color: white;
}

.branding-title {
  font-size: 3rem;
  font-weight: bold;
  margin-bottom: 1rem;
}

.branding-subtitle {
  font-size: 1.5rem;
  margin-bottom: 2rem;
  color: #d1d5db;
}

.features {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  font-size: 1.125rem;
}

.feature-item {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.feature-icon {
  color: #f59e0b;
  font-size: 1.5rem;
}

.feature-title {
  font-weight: 600;
}

.feature-desc {
  color: #9ca3af;
}

/* Right Side - Form */
.form-side {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #111827;
  padding: 1rem 1.5rem;
}

@media (min-width: 640px) {
  .form-side {
    padding: 1.5rem;
  }
}

@media (min-width: 1024px) {
  .form-side {
    padding: 2rem;
  }
}

.register-card {
  max-width: 28rem;
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.mobile-logo {
  text-align: center;
}

@media (min-width: 1024px) {
  .mobile-logo {
    display: none;
  }
}

.mobile-title {
  font-size: 2.25rem;
  font-weight: bold;
  color: white;
}

.mobile-subtitle {
  margin-top: 0.5rem;
  color: #9ca3af;
}

.register-header {
  text-align: center;
}

.register-title {
  margin-top: 1.5rem;
  font-size: 1.875rem;
  font-weight: 800;
  color: white;
}

.register-subtitle {
  margin-top: 0.5rem;
  font-size: 0.875rem;
  color: #9ca3af;
}

.register-form {
  margin-top: 2rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.error-message {
  background-color: rgba(127, 29, 29, 0.5);
  border: 1px solid #ef4444;
  color: #fecaca;
  padding: 0.75rem 1rem;
  border-radius: 0.375rem;
}

.form-fields {
  border-radius: 0.375rem;
  box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.form-input {
  appearance: none;
  border-radius: 0.5rem;
  position: relative;
  display: block;
  width: 100%;
  padding: 0.5rem 0.75rem;
  border: 1px solid #374151;
  background-color: #1f2937;
  color: white;
  font-size: 0.875rem;
}

.form-input::placeholder {
  color: #9ca3af;
}

.form-input:focus {
  outline: none;
  ring: 1px solid #d97706;
  border-color: #d97706;
}

.submit-btn {
  position: relative;
  width: 100%;
  display: flex;
  justify-content: center;
  padding: 0.5rem 1rem;
  border: 1px solid transparent;
  font-size: 0.875rem;
  font-weight: 500;
  border-radius: 0.375rem;
  color: white;
  background: linear-gradient(to right, #d97706, #b45309);
  cursor: pointer;
  transition: all 0.2s;
}

.submit-btn:hover:not(:disabled) {
  background: linear-gradient(to right, #b45309, #92400e);
}

.submit-btn:focus {
  outline: none;
  ring: 2px solid #d97706;
  ring-offset: 2px;
}

.submit-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.login-link {
  text-align: center;
}

.login-link a {
  color: #d97706;
  font-size: 0.875rem;
  text-decoration: none;
  transition: color 0.2s;
}

.login-link a:hover {
  color: #f59e0b;
}
</style>
