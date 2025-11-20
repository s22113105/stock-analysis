#!/bin/bash

echo "=========================================="
echo "🔧 Stock Analysis - 系統自我修復工具"
echo "=========================================="
echo ""
echo "⚠️  警告: 此操作將會重建資料庫結構"
echo "     這能解決 'Unknown column' 等結構性錯誤"
echo ""

# 1. 檢查並刪除衝突的重複模型檔案
echo "步驟 1/4: 檢查檔案衝突..."
if [ -f "app/Models/Stock_Model.php" ]; then
    echo "  ⚠️  發現重複模型: app/Models/Stock_Model.php"
    rm "app/Models/Stock_Model.php"
    echo "  ✅ 已刪除衝突檔案 (保留標準的 Stock.php)"
else
    echo "  ✅ 無檔案衝突"
fi
echo ""

# 2. 重建資料庫
echo "步驟 2/4: 重建資料庫結構..."
echo "  ⏳ 正在執行 migrate:fresh..."

# 使用 PHP 執行，避免 Windows/Linux 路徑問題
php artisan migrate:fresh --seed

if [ $? -eq 0 ]; then
    echo "  ✅ 資料庫重建成功"
else
    echo "  ❌ 資料庫重建失敗"
    exit 1
fi
echo ""

# 3. 檢查資料表結構
echo "步驟 3/4: 驗證資料表結構..."
echo "  🔍 檢查 stock_prices 是否包含 stock_id..."

php artisan tinker --execute="
try {
    \$hasColumn = Schema::hasColumn('stock_prices', 'stock_id');
    if (\$hasColumn) {
        echo '  ✅ 驗證成功: stock_prices.stock_id 存在' . PHP_EOL;
    } else {
        echo '  ❌ 驗證失敗: stock_prices.stock_id 仍然缺失' . PHP_EOL;
        exit(1);
    }
} catch (\Exception \$e) {
    echo '  ❌ 檢查時發生錯誤: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"
echo ""

# 4. 執行資料驗證指令
echo "步驟 4/4: 執行系統資料驗證..."
echo ""

php artisan data:validate

echo ""
echo "=========================================="
echo "✅ 系統修復完成！"
echo "=========================================="
echo "現在您可以重新執行 ./fetch_real_data.sh 來抓取資料了"
echo ""
