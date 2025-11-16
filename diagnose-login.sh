#!/bin/bash

echo "🔍 開始診斷登入系統問題..."
echo ""

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# ==========================================
# Step 1: 檢查 AuthController 檔案位置
# ==========================================
echo -e "${BLUE}Step 1/8: 檢查 AuthController 檔案...${NC}"

if [ -f "app/Http/Controllers/Api/AuthController.php" ]; then
    echo -e "${GREEN}✓ AuthController 存在於正確位置${NC}"
else
    echo -e "${RED}✗ AuthController 不存在，需要建立${NC}"
    echo "請確認檔案位於: app/Http/Controllers/Api/AuthController.php"
fi
echo ""

# ==========================================
# Step 2: 檢查 Sanctum 是否已安裝
# ==========================================
echo -e "${BLUE}Step 2/8: 檢查 Laravel Sanctum...${NC}"

if grep -q "laravel/sanctum" composer.json; then
    echo -e "${GREEN}✓ Sanctum 已在 composer.json 中${NC}"
else
    echo -e "${YELLOW}! Sanctum 尚未安裝${NC}"
    echo "執行: composer require laravel/sanctum"
fi
echo ""

# ==========================================
# Step 3: 檢查資料庫連線
# ==========================================
echo -e "${BLUE}Step 3/8: 檢查資料庫連線...${NC}"

php artisan db:show 2>/dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ 資料庫連線正常${NC}"
else
    echo -e "${RED}✗ 資料庫連線失敗${NC}"
    echo "請檢查 .env 檔案中的資料庫設定"
fi
echo ""

# ==========================================
# Step 4: 檢查 users 資料表
# ==========================================
echo -e "${BLUE}Step 4/8: 檢查 users 資料表...${NC}"

php artisan tinker --execute="echo 'Users count: ' . App\Models\User::count();" 2>/dev/null
echo ""

# ==========================================
# Step 5: 執行 Sanctum 遷移
# ==========================================
echo -e "${BLUE}Step 5/8: 確保 Sanctum 資料表存在...${NC}"

php artisan migrate 2>/dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ 遷移執行成功${NC}"
else
    echo -e "${RED}✗ 遷移執行失敗${NC}"
fi
echo ""

# ==========================================
# Step 6: 建立測試帳號
# ==========================================
echo -e "${BLUE}Step 6/8: 建立測試帳號...${NC}"

php artisan tinker --execute="
\$email = 'demo@stock.com';
\$user = App\Models\User::where('email', \$email)->first();
if (!\$user) {
    \$user = App\Models\User::create([
        'name' => 'Demo User',
        'email' => \$email,
        'password' => Hash::make('demo1234'),
        'email_verified_at' => now(),
    ]);
    echo '✓ 測試帳號建立成功: ' . \$email . PHP_EOL;
} else {
    echo '✓ 測試帳號已存在: ' . \$email . PHP_EOL;
}
" 2>/dev/null

echo ""

# ==========================================
# Step 7: 測試登入 API
# ==========================================
echo -e "${BLUE}Step 7/8: 測試登入 API...${NC}"

RESPONSE=$(curl -s -X POST http://127.0.0.1:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "email": "demo@stock.com",
    "password": "demo1234"
  }')

echo "API 回應:"
echo "$RESPONSE" | python -m json.tool 2>/dev/null || echo "$RESPONSE"
echo ""

# ==========================================
# Step 8: 檢查路由
# ==========================================
echo -e "${BLUE}Step 8/8: 檢查認證路由...${NC}"

php artisan route:list --name=auth 2>/dev/null
echo ""

# ==========================================
# 總結
# ==========================================
echo "================================"
echo -e "${GREEN}診斷完成！${NC}"
echo "================================"
echo ""
echo "📋 檢查項目:"
echo "  1. AuthController 檔案位置"
echo "  2. Sanctum 安裝狀態"
echo "  3. 資料庫連線"
echo "  4. Users 資料表"
echo "  5. Sanctum 遷移"
echo "  6. 測試帳號"
echo "  7. 登入 API 測試"
echo "  8. 路由設定"
echo ""
echo "🔑 測試帳號:"
echo "   Email: demo@stock.com"
echo "   密碼: demo1234"
echo ""
echo "📖 如果仍有問題，請查看詳細錯誤訊息"