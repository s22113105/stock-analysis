#!/bin/bash

echo "🚀 開始初始化選擇權交易系統..."

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 檢查 Docker 是否安裝
if ! command -v docker &> /dev/null; then
    echo -e "${RED}錯誤: Docker 未安裝${NC}"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo -e "${RED}錯誤: Docker Compose 未安裝${NC}"
    exit 1
fi

echo -e "${GREEN}✓ Docker 環境檢查完成${NC}"

# 創建必要的目錄結構
echo "📁 建立目錄結構..."
mkdir -p docker/nginx/conf.d
mkdir -p docker/mysql
mkdir -p docker/php
mkdir -p storage/app/public
mkdir -p storage/framework/{cache,sessions,testing,views}
mkdir -p storage/logs
mkdir -p bootstrap/cache
mkdir -p resources/js/{components,views,stores,utils}
mkdir -p resources/css

# 複製環境設定檔
if [ ! -f .env ]; then
    echo "📋 複製環境設定檔..."
    cp .env.example .env
    echo -e "${GREEN}✓ 環境設定檔已建立${NC}"
else
    echo -e "${YELLOW}! .env 檔案已存在，跳過複製${NC}"
fi

# 設定目錄權限
echo "🔐 設定目錄權限..."
chmod -R 755 storage
chmod -R 755 bootstrap/cache

# 建立 Nginx 設定
cat > docker/nginx/conf.d/app.conf << EOF
server {
    listen 80;
    index index.php index.html;
    error_log  /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
    root /var/www/public;
    
    location ~ \.php$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }
    
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
        gzip_static on;
    }
}
EOF

# 建立 MySQL 設定
cat > docker/mysql/my.cnf << EOF
[mysqld]
general_log = 1
general_log_file = /var/lib/mysql/general.log
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci

[client]
default-character-set=utf8mb4
EOF

# 建立 PHP 設定
cat > docker/php/local.ini << EOF
upload_max_filesize = 40M
post_max_size = 40M
memory_limit = 512M
max_execution_time = 600
EOF

echo -e "${GREEN}✓ Docker 設定檔案已建立${NC}"

# 啟動 Docker 容器
echo "🐳 啟動 Docker 容器..."
docker-compose down
docker-compose up -d --build

# 等待 MySQL 啟動
echo "⏳ 等待 MySQL 啟動..."
sleep 10

# 安裝 Composer 依賴
echo "📦 安裝 Composer 依賴..."
docker-compose exec app composer install

# 生成應用程式金鑰
echo "🔑 生成應用程式金鑰..."
docker-compose exec app php artisan key:generate

# 執行資料庫遷移
echo "🗄️ 執行資料庫遷移..."
docker-compose exec app php artisan migrate

# 建立儲存連結
echo "🔗 建立儲存連結..."
docker-compose exec app php artisan storage:link

# 安裝 NPM 依賴
echo "📦 安裝 NPM 依賴..."
docker-compose exec node npm install

# 建立初始種子資料
echo "🌱 建立測試資料..."
docker-compose exec app php artisan db:seed

# 清除快取
echo "🧹 清除快取..."
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
docker-compose exec app php artisan view:clear

# 顯示服務狀態
echo ""
echo "================================"
echo -e "${GREEN}✅ 系統初始化完成！${NC}"
echo "================================"
echo ""
echo "🌐 服務連結："
echo "   Laravel: http://localhost:8000"
echo "   phpMyAdmin: http://localhost:8080"
echo "   Vue Dev Server: http://localhost:5173"
echo ""
echo "📊 預設資料庫："
echo "   Database: options_trading"
echo "   Username: laravel"
echo "   Password: secret"
echo ""
echo "💡 常用指令："
echo "   啟動服務: docker-compose up -d"
echo "   停止服務: docker-compose down"
echo "   查看日誌: docker-compose logs -f"
echo "   進入容器: docker-compose exec app bash"
echo "   執行 Artisan: docker-compose exec app php artisan [command]"
echo "   執行 NPM: docker-compose exec node npm run dev"
echo ""
echo -e "${YELLOW}📝 記得更新 .env 檔案中的 API 金鑰！${NC}"
