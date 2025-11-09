# Base pakai CLI supaya bisa php artisan serve (tanpa Nginx/FPM)
FROM php:8.2-alpine

# ----------------------------
# 🧩 System & PHP extensions
# ----------------------------
RUN apk add --no-cache \
    bash git zip unzip curl icu-dev libxml2-dev libzip-dev \
    libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev zlib-dev \
    nodejs npm netcat-openbsd shadow \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j$(nproc) gd intl pdo_mysql mbstring bcmath exif pcntl zip

# ----------------------------
# 🧰 Composer
# ----------------------------
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /var/www

# ----------------------------
# ⚡️ Layering: cache composer lebih efisien
# ----------------------------
COPY composer.json composer.lock* ./
RUN composer install --no-interaction --prefer-dist --no-scripts || true

# ----------------------------
# 📦 Copy project
# (akan ketimpa bind mount saat runtime utk live sync)
# ----------------------------
COPY . .

# ----------------------------
# 🔐 Permissions (storage & cache)
# ----------------------------
RUN mkdir -p storage/app/public bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rw storage bootstrap/cache

# ----------------------------
# 🚪 Expose port (artisan serve)
# ----------------------------
EXPOSE 8000

# ----------------------------
# ✅ Default CMD optional (boleh di-override di compose)
# ----------------------------
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
