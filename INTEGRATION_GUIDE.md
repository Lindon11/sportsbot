# LaravelCP Frontend-Backend Integration Guide

## Overview

This document describes how the Vue.js frontend integrates with the Laravel backend API.

---

## Architecture

### Frontend (Vue 3 + TypeScript + Vite)
- **Location**: `frontend/` directory
- **Dev Server Port**: 5175
- **Tech Stack**: Vue 3, Pinia (state), Vue Router, Axios, TypeScript
- **Testing**: Vitest (unit), Playwright (E2E)

### Backend (Laravel 11 + PHP 8.3)
- **Location**: `backend/` directory
- **API Port**: 8001
- **Tech Stack**: Laravel 11, Laravel Sanctum (auth), Laravel Reverb (WebSocket)
- **Testing**: PHPUnit

---

## Project Structure

```
LaravelCP/
├── frontend/                    # Vue 3 Frontend
│   ├── src/
│   │   ├── services/           # API & WebSocket services
│   │   ├── stores/             # Pinia state management
│   │   └── router/             # Vue Router
│   └── package.json
│
├── backend/                     # Laravel Backend
│   ├── app/
│   │   ├── Core/               # Core system
│   │   └── Plugins/            # Game plugins
│   ├── routes/api.php          # API routes
│   └── composer.json
│
└── docker-compose.yml          # Full-stack Docker
```

---

## API Endpoints

### Authentication

| Endpoint | Method | Description | Request Body | Response |
|----------|--------|-------------|--------------|----------|
| `/api/v1/login` | POST | Login user | `{ login, password }` | `{ user, token }` |
| `/api/v1/register` | POST | Register new user | `{ username, email, password, password_confirmation }` | `{ user, token }` |
| `/api/v1/logout` | POST | Logout user | - | `{ message }` |
| `/api/v1/user` | GET | Get current user | - | `UserResource` |

### Response Formats

#### Successful Login/Register Response
```json
{
  "user": {
    "id": 1,
    "username": "testuser",
    "email": "test@example.com"
  },
  "token": "1|abcdef123456..."
}
```

#### Validation Error Response
```json
{
  "message": "The provided credentials are incorrect.",
  "errors": {
    "login": ["The provided credentials are incorrect."]
  }
}
```

### Two-Factor Authentication

When 2FA is enabled for a user, the login response will be:
```json
{
  "two_factor_required": true,
  "challenge_token": "abc123...",
  "user": { ... }
}
```

---

## Frontend Configuration

### API Service (`frontend/src/services/api.ts`)
- Base URL is empty (relies on Vite proxy in development)
- Uses `withCredentials: true` for Sanctum cookie auth
- Includes request cancellation, deduplication, and caching

### Vite Proxy Configuration (`frontend/vite.config.ts`)
```typescript
server: {
  proxy: {
    '/api': {
      target: 'http://host.docker.internal:8001',
      changeOrigin: true,
    },
  },
}
```

### Environment Variables

Create a `.env` file in the frontend directory:
```env
VITE_API_URL=
VITE_WS_URL=ws://localhost:6001
VITE_WS_KEY=app-key
VITE_WS_CLUSTER=mt1
```

---

## Auth Store Usage

### Login Example
```typescript
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

// Login
const success = await authStore.login({
  email: 'user@example.com',
  password: 'password123'
})

if (success) {
  // Redirect to dashboard
  router.push('/dashboard')
} else {
  // Show error
  console.error(authStore.error)
}
```

### Register Example
```typescript
const success = await authStore.register({
  username: 'newuser',
  email: 'new@example.com',
  password: 'password123',
  password_confirmation: 'password123'
})
```

### Check Authentication
```typescript
// In a route guard or component
if (authStore.isAuthenticated) {
  // User is logged in
}
```

### Initialize Auth State
```typescript
// Call in main.ts or App.vue
await authStore.init()
```

---

## Running the Application

### Using Docker (Recommended)

1. Start all services from the project root:
```bash
docker compose up -d
```

2. Install dependencies:
```bash
# Frontend
docker compose exec frontend npm install

# Backend
docker compose exec backend composer install
```

3. Setup the backend:
```bash
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate
```

4. Access the application:
- Frontend: http://localhost:5175
- Backend API: http://localhost:8001

### Manual Development

#### Frontend Only
```bash
cd frontend
npm install
npm run dev
```

#### Backend Only
```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --port=8001
```

---

## Testing

### Frontend Unit Tests
```bash
cd frontend
npm run test:unit
```

### Frontend E2E Tests
```bash
cd frontend
npm run test:e2e
```

### Backend Tests
```bash
cd backend
php artisan test
```

---

## CORS Configuration

The backend CORS settings in `backend/.env`:
```env
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:5175,http://localhost:8000,http://localhost:8001
CORS_SUPPORTS_CREDENTIALS=true
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,localhost:5175,localhost:8000,localhost:8001
```

---

## Game Modules

The backend includes numerous game modules accessible via API:

| Module | Endpoint Prefix | Description |
|--------|-----------------|-------------|
| Crimes | `/api/v1/crimes` | Commit crimes for rewards |
| Gym | `/api/v1/gym` | Train stats |
| Hospital | `/api/v1/hospital` | Heal injuries |
| Bank | `/api/v1/bank` | Manage money |
| Inventory | `/api/v1/inventory` | Manage items |
| Combat | `/api/v1/combat` | Fight NPCs/players |
| Gang | `/api/v1/gangs` | Gang management |
| Chat | WebSocket | Real-time chat |

---

## WebSocket Integration

The frontend uses a custom WebSocket service for real-time features:

```typescript
import { websocketService } from '@/services/websocket'

// Connect
await websocketService.connect(authToken)

// Subscribe to channel
websocketService.subscribe('private-user.1')

// Listen for events
websocketService.on('notification', (data) => {
  console.log('New notification:', data)
})

// Disconnect
websocketService.disconnect()
```

---

## Troubleshooting

### CORS Errors
- Verify `CORS_ALLOWED_ORIGINS` includes the frontend URL
- Check `SANCTUM_STATEFUL_DOMAINS` is properly configured

### 401 Unauthorized
- Check if the auth token is stored in localStorage
- Verify the session is still active by calling `/api/v1/user`

### API 404 Errors
- Ensure the backend is running
- Check the API route prefix (`/api/v1/`)

### Connection Refused
- Verify Docker containers are running: `docker compose ps`
- Check if ports 5175 and 8001 are available

---

## Additional Resources

- [Main README](./README.md) - Project overview
- [Deployment Guide](./DEPLOYMENT.md) - Production deployment
- [Backend README](./backend/README.md) - Backend documentation
- [API Documentation](http://localhost:8001/docs) - Auto-generated API docs