# LaravelCP - Production Deployment Guide

This guide covers deploying the LaravelCP monorepo (Vue 3 frontend + Laravel backend) to a production server.

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Server Requirements](#server-requirements)
3. [Deployment Methods](#deployment-methods)
4. [Web Server Configuration](#web-server-configuration)
5. [SSL Configuration](#ssl-configuration)
6. [Environment Configuration](#environment-configuration)
7. [CI/CD Pipeline](#cicd-pipeline)
8. [Docker Deployment](#docker-deployment)
9. [Post-Deployment Checklist](#post-deployment-checklist)
10. [Troubleshooting](#troubleshooting)

---

## Prerequisites

- Linux server (Ubuntu 22.04+ recommended)
- Root or sudo access
- Domain name configured
- SSL certificate (Let's Encrypt recommended)
- 2GB+ RAM, 20GB+ storage

---

## Server Requirements

### Frontend
- Node.js 18+ (for building)
- Nginx or Apache (for serving)

### Backend
- PHP 8.3+
- Composer 2.x
- MySQL 8.0+
- Nginx or Apache with PHP-FPM
- Redis (recommended for caching/queues)

---

## Deployment Methods

### Method 1: Docker Compose (Recommended)

#### Step 1: Install Docker

```bash
# Install Docker
curl -fsSL https://get.docker.com | sh

# Install Docker Compose
sudo apt install docker-compose-plugin

# Add user to docker group
sudo usermod -aG docker $USER
```

#### Step 2: Clone and Configure

```bash
# Clone repository
git clone https://github.com/Lindon11/LaravelCP.git
cd LaravelCP

# Create environment files
cp backend/.env.example backend/.env
```

#### Step 3: Configure Environment

Edit `backend/.env`:
```env
APP_NAME=LaravelCP
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=laravelcp
DB_USERNAME=laravelcp
DB_PASSWORD=your_secure_password

SANCTUM_STATEFUL_DOMAINS=yourdomain.com,www.yourdomain.com
CORS_ALLOWED_ORIGINS=https://yourdomain.com,https://www.yourdomain.com
```

Create `frontend/.env.production`:
```env
VITE_API_URL=https://api.yourdomain.com
VITE_WS_URL=wss://ws.yourdomain.com
```

#### Step 4: Deploy

```bash
# Build and start containers
docker compose up -d --build

# Install dependencies
docker compose exec backend composer install --no-dev --optimize-autoloader
docker compose exec frontend npm install
docker compose exec frontend npm run build

# Setup Laravel
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan config:cache
docker compose exec backend php artisan route:cache
```

---

### Method 2: Manual Deployment

#### Frontend Deployment

##### Step 1: Build Locally

```bash
cd frontend

# Install dependencies
npm ci

# Create production environment
echo "VITE_API_URL=https://api.yourdomain.com" > .env.production
echo "VITE_WS_URL=wss://ws.yourdomain.com" >> .env.production

# Build
npm run build
```

##### Step 2: Upload to Server

```bash
# Using rsync
rsync -avz --delete dist/ user@server:/var/www/frontend/
```

#### Backend Deployment

##### Step 1: Prepare Server

```bash
# Install PHP 8.3
sudo add-apt-repository ppa:ondrej/php
sudo apt update
sudo apt install php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath

# Install Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# Install Node.js (for admin panel)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

##### Step 2: Deploy Code

```bash
# Clone or upload
git clone https://github.com/Lindon11/LaravelCP.git /var/www/laravelcp
cd /var/www/laravelcp/backend

# Install dependencies
composer install --no-dev --optimize-autoloader

# Setup environment
cp .env.example .env
php artisan key:generate

# Configure .env for production
# Edit database, app URL, etc.

# Run migrations
php artisan migrate --force

# Cache configuration
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Set permissions
sudo chown -R www-data:www-data /var/www/laravelcp/backend
sudo chmod -R 775 /var/www/laravelcp/backend/storage
sudo chmod -R 775 /var/www/laravelcp/backend/bootstrap/cache
```

---

## Web Server Configuration

### Nginx Configuration

#### Frontend (SPA)

Create `/etc/nginx/sites-available/frontend`:

```nginx
server {
    listen 80;
    server_name yourdomain.com www.yourdomain.com;
    root /var/www/frontend;
    index index.html;

    # Gzip compression
    gzip on;
    gzip_vary on;
    gzip_min_length 1024;
    gzip_types text/plain text/css text/xml text/javascript application/javascript application/json application/xml+rss image/svg+xml;

    location / {
        try_files $uri $uri/ /index.html;
    }

    # Cache static assets
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
}
```

#### Backend (API)

Create `/etc/nginx/sites-available/backend`:

```nginx
server {
    listen 80;
    server_name api.yourdomain.com;
    root /var/www/laravelcp/backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable sites:

```bash
sudo ln -s /etc/nginx/sites-available/frontend /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/backend /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## SSL Configuration

### Using Let's Encrypt

```bash
# Install Certbot
sudo apt install certbot python3-certbot-nginx

# Obtain certificates
sudo certbot --nginx -d yourdomain.com -d www.yourdomain.com
sudo certbot --nginx -d api.yourdomain.com

# Auto-renewal test
sudo certbot renew --dry-run
```

---

## Environment Configuration

### Backend `.env` (Production)

```env
APP_NAME=LaravelCP
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://api.yourdomain.com

LOG_CHANNEL=stack
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravelcp
DB_USERNAME=laravelcp
DB_PASSWORD=secure_password

BROADCAST_DRIVER=redis
CACHE_DRIVER=redis
FILESYSTEM_DISK=local
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
SESSION_DOMAIN=yourdomain.com

SANCTUM_STATEFUL_DOMAINS=yourdomain.com,www.yourdomain.com
CORS_ALLOWED_ORIGINS=https://yourdomain.com
```

### Frontend `.env.production`

```env
VITE_API_URL=https://api.yourdomain.com
VITE_WS_URL=wss://ws.yourdomain.com
```

---

## CI/CD Pipeline

### GitHub Actions

Create `.github/workflows/deploy.yml`:

```yaml
name: Deploy to Production

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    
    steps:
    - uses: actions/checkout@v4
    
    - name: Setup Node.js
      uses: actions/setup-node@v4
      with:
        node-version: '20'
        cache: 'npm'
        cache-dependency-path: frontend/package-lock.json
    
    - name: Build Frontend
      run: |
        cd frontend
        npm ci
        npm run build
      env:
        VITE_API_URL: https://api.yourdomain.com
        VITE_WS_URL: wss://ws.yourdomain.com
    
    - name: Deploy to Server
      uses: easingthemes/ssh-deploy@main
      env:
        SSH_PRIVATE_KEY: ${{ secrets.SSH_PRIVATE_KEY }}
        REMOTE_HOST: ${{ secrets.REMOTE_HOST }}
        REMOTE_USER: ${{ secrets.REMOTE_USER }}
        TARGET: /var/www/laravelcp
        SCRIPT_AFTER: |
          cd /var/www/laravelcp/backend
          composer install --no-dev --optimize-autoloader
          php artisan config:cache
          php artisan route:cache
          php artisan migrate --force
          sudo systemctl reload php8.3-fpm
```

Add GitHub Secrets:
- `SSH_PRIVATE_KEY` - Your SSH private key
- `REMOTE_HOST` - Server IP or domain
- `REMOTE_USER` - SSH user

---

## Docker Deployment

### Production Docker Compose

Create `docker-compose.prod.yml`:

```yaml
services:
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
      target: production
    ports:
      - "80:80"
    depends_on:
      - backend
    restart: unless-stopped

  backend:
    build:
      context: ./backend
      dockerfile: Dockerfile
    ports:
      - "8000:80"
    environment:
      - APP_ENV=production
    volumes:
      - backend_storage:/srv/laravelcp/backend/storage
    depends_on:
      mysql:
        condition: service_healthy
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: laravelcp
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mysql_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 5s
      retries: 10
    restart: unless-stopped

  redis:
    image: redis:alpine
    restart: unless-stopped

volumes:
  mysql_data:
  backend_storage:
```

Deploy:
```bash
docker compose -f docker-compose.prod.yml up -d --build
```

---

## Post-Deployment Checklist

- [ ] Frontend accessible at your domain
- [ ] API responding at backend URL
- [ ] HTTPS working with valid certificate
- [ ] Database connections working
- [ ] User registration/login working
- [ ] WebSocket connections working
- [ ] All routes accessible (no 404s on refresh)
- [ ] Static assets loading correctly
- [ ] CORS configured properly
- [ ] Error logging configured (Sentry, etc.)
- [ ] Monitoring configured (UptimeRobot, etc.)
- [ ] Backups configured
- [ ] Queue workers running

---

## Troubleshooting

### Common Issues

#### 404 on Page Refresh (Frontend)
Ensure Nginx is configured with `try_files $uri $uri/ /index.html;`

#### CORS Errors
Check `CORS_ALLOWED_ORIGINS` and `SANCTUM_STATEFUL_DOMAINS` in backend `.env`

#### API 500 Errors
```bash
# Check Laravel logs
tail -f backend/storage/logs/laravel.log

# Check PHP-FPM logs
tail -f /var/log/php8.3-fpm.log
```

#### WebSocket Connection Failed
- Verify WebSocket server is running
- Check SSL certificate for WSS connections
- Verify `VITE_WS_URL` is correct

#### Permission Issues
```bash
sudo chown -R www-data:www-data backend/storage backend/bootstrap/cache
sudo chmod -R 775 backend/storage backend/bootstrap/cache
```

---

## Support

- 📖 [Main README](./README.md)
- 🐛 [Issue Tracker](https://github.com/Lindon11/LaravelCP/issues)

---

**Last Updated:** February 2026
