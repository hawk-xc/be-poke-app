FROM php:8.2-fpm-alpine

# ----------------------------
# 🧩 Install dependencies
# ----------------------------
RUN apk add --no-cache \
    bash \
    git \
    zip \
    unzip \
    curl \
    icu-dev \
    libxml2-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    zlib-dev \
    build-base \
    nodejs \
    npm \
 && docker-php-ext-configure gd --with-jpeg --with-freetype \
 && docker-php-ext-install -j$(nproc) \
    gd intl pdo_mysql mbstring bcmath exif pcntl zip \
 && apk del build-base

# ----------------------------
# 🧰 Composer
# ----------------------------
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www

# ----------------------------
# 🧩 Copy Project
# ----------------------------
COPY . .

# ----------------------------
# ⚙️ Composer install
# ----------------------------
ENV COMPOSER_ALLOW_SUPERUSER=1
ENV COMPOSER_MEMORY_LIMIT=-1
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
 && composer require fakerphp/faker --no-interaction --prefer-dist

# ----------------------------
# 🔐 Permissions
# ----------------------------
RUN mkdir -p storage/app/public && chmod -R 777 storage bootstrap/cache

EXPOSE 8000

CMD ["php-fpm"]