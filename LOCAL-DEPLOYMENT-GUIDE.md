# Local Deployment Guide

This guide provides step-by-step instructions for doing a fresh local installation of LaravelCP using Docker.

---

## Prerequisites

- **Docker Desktop** installed and running
- **Git** for cloning the repository
- **PowerShell** or **Command Prompt** (Windows) / **Terminal** (macOS/Linux)

### Verify Docker is Running

```bash
docker ps
```

If you see an error about the Docker daemon, start Docker Desktop and wait for it to fully initialize.

---

## Quick Start (One Command)

For a complete fresh install in one go:

```bash
# Windows PowerShell
docker compose down -v
Remove-Item backend\.env -Force -ErrorAction SilentlyContinue
Copy-Item backend\.env.example backend\.env
docker compose up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan app:install --force --admin-username=admin --admin-email=admin@example.com --admin-password=admin123
docker compose exec backend php artisan license:generate --domain="*" --tier=standard --customer="Local Development" --email="admin@example.com" --expires=lifetime
```

---

## Step-by-Step Installation

### Step 1: Clone the Repository

```bash
git clone https://github.com/Lindon11/LaravelCP.git
cd LaravelCP
```

### Step 2: Clean Up Previous Installations (if any)

If you've previously run the project locally, clean up old data:

```bash
# Stop all containers and remove volumes (deletes database data)
docker compose down -v

# Remove old environment file
# Windows PowerShell:
Remove-Item backend\.env -Force -ErrorAction SilentlyContinue
# Linux/macOS:
rm -f backend/.env
```

### Step 3: Create Environment File

```bash
# Windows PowerShell:
Copy-Item backend\.env.example backend\.env
# Linux/macOS:
cp backend/.env.example backend/.env
```

### Step 4: Build and Start Containers

```bash
docker compose up -d --build
```

This will:
- Build the frontend (Node.js/Vue 3) container
- Build the backend (PHP 8.3/Laravel 11) container
- Start MySQL 8.0 database
- Start phpMyAdmin

Wait for all containers to show "Up" status:

```bash
docker compose ps
```

Expected output:
```
NAME                 STATUS
laravelcp_backend    Up X minutes
laravelcp_db         Up X minutes (healthy)
laravelcp_frontend   Up X minutes
laravelcp_pma        Up X minutes
```

### Step 5: Generate Application Key

```bash
docker compose exec backend php artisan key:generate
```

Expected output:
```
INFO  Application key set successfully.
```

### Step 6: Run Database Migrations

```bash
docker compose exec backend php artisan migrate --force
```

This creates all database tables (114+ tables).

### Step 7: Run the Installer

The installer creates the admin account and seeds initial game data:

```bash
docker compose exec backend php artisan app:install --force --admin-username=admin --admin-email=admin@example.com --admin-password=admin123
```

Expected output:
```
╔═══════════════════════════════════════╗
║     DEFAULT ADMIN CREDENTIALS         ║
╠═══════════════════════════════════════╣
║  Username: admin                      ║
║  Email:    admin@example.com          ║
║  Password: admin123                   ║
╠═══════════════════════════════════════╣
║  ⚠ CHANGE PASSWORD ON FIRST LOGIN!   ║
╚═══════════════════════════════════════╝

✅ LaravelCP installed successfully!
```

### Step 8: Generate License Key

Generate a development license for local testing:

```bash
docker compose exec backend php artisan license:generate --domain="*" --tier=standard --customer="Local Development" --email="admin@example.com" --expires=lifetime
```

Save the outputted license key - it's also stored automatically in `backend/storage/license_key`.

### Step 9: Verify Installation

Test the admin login:

```bash
# Windows PowerShell:
$body = '{"login":"admin","password":"admin123"}'
Invoke-WebRequest -Uri http://localhost:8001/api/v1/login -Method POST -ContentType 'application/json' -Body $body -UseBasicParsing

# Linux/macOS (with curl):
curl -X POST http://localhost:8001/api/v1/login -H "Content-Type: application/json" -d '{"login":"admin","password":"admin123"}'
```

You should receive a JSON response with user data and an API token.

---

## Access Points

| Service | URL | Description |
|---------|-----|-------------|
| **Frontend** | http://localhost:5175 | Vue 3 development server |
| **Backend API** | http://localhost:8001 | Laravel REST API |
| **Admin Panel** | http://localhost:8001/admin | Admin dashboard |
| **API Docs** | http://localhost:8001/docs | Scribe API documentation |
| **phpMyAdmin** | http://localhost:8082 | Database management |

---

## Default Credentials

### Admin Account

| Field | Value |
|-------|-------|
| Username | `admin` |
| Email | `admin@example.com` |
| Password | `admin123` |

⚠️ **You will be prompted to change the password on first login.**

### MySQL Database

| Field | Value |
|-------|-------|
| Host | `localhost` (or `mysql` from containers) |
| Port | `3307` |
| Database | `laravelcp` |
| Username | `laravelcp` |
| Password | `laravelcp` |
| Root Password | `root` |

---

## Common Commands

### Container Management

```bash
# Start all services
docker compose up -d

# Stop all services
docker compose down

# Stop and remove volumes (fresh database)
docker compose down -v

# View logs
docker compose logs -f backend
docker compose logs -f frontend

# Rebuild containers
docker compose up -d --build
```

### Backend Commands

```bash
# Run artisan commands
docker compose exec backend php artisan [command]

# Common artisan commands
docker compose exec backend php artisan migrate           # Run migrations
docker compose exec backend php artisan migrate:fresh     # Reset database
docker compose exec backend php artisan db:seed           # Run seeders
docker compose exec backend php artisan cache:clear       # Clear cache
docker compose exec backend php artisan config:clear      # Clear config

# License commands
docker compose exec backend php artisan license:validate  # Check license
docker compose exec backend php artisan license:generate  # Generate license

# Installer
docker compose exec backend php artisan app:install --force --admin-username=admin --admin-email=admin@example.com --admin-password=admin123
```

### Frontend Commands

```bash
# Install dependencies
docker compose exec frontend npm install

# Build for production
docker compose exec frontend npm run build

# Run linting
docker compose exec frontend npm run lint
```

---

## Troubleshooting

### Database Connection Issues

**Symptom:** Backend shows "Waiting for database..." or connection errors.

**Solution:**
1. Check MySQL container is healthy:
   ```bash
   docker compose ps
   ```
2. Verify `.env` has correct database settings:
   ```env
   DB_HOST=mysql
   DB_PORT=3306
   DB_DATABASE=laravelcp
   DB_USERNAME=laravelcp
   DB_PASSWORD=laravelcp
   ```
3. Restart containers:
   ```bash
   docker compose restart backend
   ```

### Port Conflicts

**Symptom:** Container fails to start with port binding error.

**Solution:** Check if another application is using the port:
- Frontend: 5175
- Backend: 8001
- MySQL: 3307
- phpMyAdmin: 8082

Stop the conflicting application or modify ports in `docker-compose.yml`.

### Permission Issues (Linux/macOS)

**Symptom:** Storage or cache permission errors.

**Solution:**
```bash
docker compose exec backend chmod -R 775 storage bootstrap/cache
docker compose exec backend chown -R www-data:www-data storage bootstrap/cache
```

### License Validation Failed

**Symptom:** Admin panel redirects to license activation page.

**Solution:**
1. Generate a new license:
   ```bash
   docker compose exec backend php artisan license:generate --domain="*" --tier=standard --customer="Local Development" --email="admin@example.com" --expires=lifetime
   ```
2. Verify license:
   ```bash
   docker compose exec backend php artisan license:validate
   ```

### Old Data Persisting

**Symptom:** Old users or data still present after reinstall.

**Solution:** Remove volumes when stopping:
```bash
docker compose down -v
```
This deletes the database volume for a truly fresh start.

---

## Development Workflow

### Running Tests

```bash
# Backend tests
docker compose exec backend php artisan test

# Frontend tests
docker compose exec frontend npm run test:unit
docker compose exec frontend npm run test:e2e
```

### Generating API Documentation

```bash
docker compose exec backend php artisan scribe:generate
```

Documentation will be available at http://localhost:8001/docs

### Watching Frontend Changes

The frontend container automatically hot-reloads when files change. Just edit files in `frontend/src/` and changes will appear immediately.

---

## Reset to Factory State

To completely reset the project to a fresh state:

```bash
# Stop everything and remove volumes
docker compose down -v

# Remove environment file
Remove-Item backend\.env -Force -ErrorAction SilentlyContinue

# Remove license key
Remove-Item backend\storage\license_key -Force -ErrorAction SilentlyContinue

# Start fresh
Copy-Item backend\.env.example backend\.env
docker compose up -d --build
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan app:install --force --admin-username=admin --admin-email=admin@example.com --admin-password=admin123
docker compose exec backend php artisan license:generate --domain="*" --tier=standard --customer="Local Development" --email="admin@example.com" --expires=lifetime
```

---

## System Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| Docker Desktop | 4.x | Latest |
| RAM | 8 GB | 16 GB |
| Disk Space | 5 GB | 10 GB |
| CPU | 2 cores | 4+ cores |

---

## Project Structure

```
LaravelCP/
├── frontend/                # Vue 3 + TypeScript + Vite
│   ├── src/                 # Source code
│   │   ├── components/      # Vue components
│   │   ├── views/           # Page views
│   │   ├── stores/          # Pinia state management
│   │   ├── services/        # API & WebSocket services
│   │   └── router/          # Vue Router configuration
│   └── package.json
│
├── backend/                 # Laravel 11 + PHP 8.3
│   ├── app/
│   │   ├── Core/            # Core system components
│   │   └── Plugins/         # Game feature plugins (28+ built-in)
│   ├── database/            # Migrations & seeders
│   ├── routes/              # API routes
│   └── composer.json
│
├── docker-compose.yml       # Docker configuration
└── README.md
```

---

## Getting Help

- 📖 [API Documentation](http://localhost:8001/docs) (after starting)
- 🐛 [Issue Tracker](https://github.com/Lindon11/LaravelCP/issues)
- 💬 [Discussions](https://github.com/Lindon11/LaravelCP/discussions)

---

## License

This project is open-sourced software licensed under the [MIT license](./backend/LICENSE).