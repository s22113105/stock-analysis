FROM php:8.2-fpm

# 設定工作目錄
WORKDIR /var/www

# 安裝系統依賴
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# 安裝 PHP 擴展
RUN docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    zip \
    intl \
    opcache

# 安裝 Redis 擴展
RUN pecl install redis \
    && docker-php-ext-enable redis

# 安裝 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 複製應用程式
COPY . /var/www

# 設定權限
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

# 安裝 Composer 依賴
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 暴露端口
EXPOSE 9000

# 啟動 PHP-FPM
CMD ["php-fpm"]