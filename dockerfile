FROM php:8.2-fpm

# 設定工作目錄
WORKDIR /var/www

# 安裝系統依賴（包含 Python3）
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
    python3 \
    python3-pip \
    python3-venv \
    # ✅ 新增: 安裝 gnupg (安裝 Node.js 前置需求)
    gnupg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# ✅ 新增: 安裝 Node.js (版本 20.x) 和 NPM
# 使用 NodeSource 官方腳本安裝
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs

# 安裝 Python 機器學習套件
RUN pip3 install --no-cache-dir --break-system-packages \
    numpy \
    pandas \
    scikit-learn \
    tensorflow \
    statsmodels \
    scipy \
    pmdarima \
    arch

# 建立 python 符號連結（可選）
RUN ln -sf /usr/bin/python3 /usr/bin/python

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
# 注意：如果在 build 階段就要安裝依賴，請確保 composer.json 已被 COPY 進來
# 為了避免快取問題，建議加上 --no-scripts
RUN composer install --optimize-autoloader --no-interaction --no-scripts

# 暴露端口
EXPOSE 9000

# 啟動 PHP-FPM
CMD ["php-fpm"]
