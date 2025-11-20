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
<<<<<<< HEAD
    # ✅ 新增: 安裝 gnupg 這是安裝 Node.js 所需的
    gnupg \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# ✅ 新增: 安裝 Node.js (版本 20.x) 和 NPM
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y nodejs
=======
    python3 \
    python3-pip \
    python3-venv \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# 安裝 Python 機器學習套件
RUN pip3 install --no-cache-dir --break-system-packages \
    numpy \
    pandas \
    scikit-learn \
    tensorflow \
    statsmodels \
    scipy \
    pmdarima

# 建立 python 符號連結（可選）
RUN ln -sf /usr/bin/python3 /usr/bin/python
>>>>>>> 155f5d1d1fdafab45b1b1afaff5b715ba1b2fa6a

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

# 複製應用程式 (注意：本機開發時通常透過 volume 掛載，這行主要是為了部署或 build image)
COPY . /var/www

# 設定權限
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage \
    && chmod -R 755 /var/www/bootstrap/cache

<<<<<<< HEAD
# (可選) 安裝 Composer 依賴 - 如果您希望在 build image 時就安裝好
# RUN composer install --no-dev --optimize-autoloader --no-interaction
=======
# 安裝 Composer 依賴（開發環境可以移除 --no-dev）
RUN composer install --optimize-autoloader --no-interaction
>>>>>>> 155f5d1d1fdafab45b1b1afaff5b715ba1b2fa6a

# 暴露端口
EXPOSE 9000

# 啟動 PHP-FPM
CMD ["php-fpm"]
