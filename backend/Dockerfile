FROM php:8.3-apache

# ── System dependencies ──────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev \
        libcurl4-openssl-dev libsqlite3-dev fonts-dejavu-core \
        libzip-dev libonig-dev libxml2-dev \
        zip unzip git curl chromium \
    && rm -rf /var/lib/apt/lists/*

# ── Node.js 20 (for admin panel / Vite builds) ──────────────────────
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

# ── PHP extensions ───────────────────────────────────────────────────
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo pdo_mysql mbstring exif pcntl bcmath gd zip

# ── Composer ─────────────────────────────────────────────────────────
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# ── Apache configuration ─────────────────────────────────────────────
RUN a2enmod rewrite \
    && sed -i 's|DocumentRoot .*|DocumentRoot /srv/laravelcp/backend/public|g' \
        /etc/apache2/sites-available/000-default.conf \
    && sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' \
        /etc/apache2/apache2.conf \
    && printf '<Directory /srv/laravelcp/backend/public>\n    Options Indexes FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/laravelcp.conf \
    && a2enconf laravelcp

WORKDIR /srv/laravelcp/backend

# ── Copy application code ────────────────────────────────────────────
COPY . .

# ── Install PHP dependencies ─────────────────────────────────────────
RUN if [ -f composer.json ]; then \
        composer install --no-interaction --optimize-autoloader; \
    fi

# ── Install Node dependencies used by SportsBot browser card rendering ─
RUN if [ -f package.json ]; then \
        PUPPETEER_SKIP_DOWNLOAD=true npm install --omit=dev --no-audit --no-fund; \
    fi

# ── File permissions ─────────────────────────────────────────────────
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# ── Entrypoint ────────────────────────────────────────────────────────
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
