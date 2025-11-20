#!/bin/bash

echo "=========================================="
echo "📊 股票分析系統 - 真實資料批次匯入工具"
echo "=========================================="
echo ""
echo "⚠️  警告: 此腳本將匯入真實台股資料"
echo ""
read -p "確定要繼續嗎? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ 已取消操作"
    exit 0
fi

echo ""
echo "=========================================="
echo "第 1 步: 檢查環境"
echo "=========================================="

# 檢查是否在 Laravel 專案目錄
if [ ! -f "artisan" ]; then
    echo "❌ 錯誤: 請在 Laravel 專案根目錄執行此腳本"
    exit 1
fi

echo "✅ Laravel 環境檢查通過"

# 檢查資料庫連線
php artisan tinker --execute="
try {
    \DB::connection()->getPdo();
    echo '✅ 資料庫連線正常' . PHP_EOL;
} catch (\Exception \$e) {
    echo '❌ 資料庫連線失敗: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

echo ""
echo "=========================================="
echo "第 2 步: 清理測試資料 (可選)"
echo "=========================================="

read -p "是否清空現有股票資料? (yes/no): " clear_data

if [ "$clear_data" == "yes" ]; then
    echo "🗑️  清空股票資料..."
    php artisan tinker --execute="
        DB::table('stock_prices')->truncate();
        DB::table('stocks')->truncate();
        echo '✅ 股票資料已清空' . PHP_EOL;
    "
fi

echo ""
echo "=========================================="
echo "第 3 步: 設定抓取參數"
echo "=========================================="

# 預設抓取最近 30 天的資料
DEFAULT_DAYS=30
DEFAULT_STOCKS="2330,2317,2454,2412,2882,2303,2308,2886,2884,1301,1303,2002,3045,2881,2891"

read -p "要抓取最近幾天的資料? (預設: $DEFAULT_DAYS): " DAYS
DAYS=${DAYS:-$DEFAULT_DAYS}

read -p "要抓取的股票代碼 (逗號分隔, 預設: 15檔權值股): " STOCKS
STOCKS=${STOCKS:-$DEFAULT_STOCKS}

echo ""
echo "📋 抓取設定:"
echo "   期間: 最近 $DAYS 天"
echo "   股票: $STOCKS"
echo ""

echo "=========================================="
echo "第 4 步: 找出最近有資料的日期"
echo "=========================================="

echo "🔍 正在尋找 TWSE API 最近有資料的日期..."

# 使用 PHP 腳本找出最近有資料的日期
LATEST_DATE=$(php -r "
require 'vendor/autoload.php';
\$app = require_once 'bootstrap/app.php';
\$kernel = \$app->make(Illuminate\\Contracts\\Console\\Kernel::class);
\$kernel->bootstrap();

\$twseApi = app(App\\Services\\TwseApiService::class);

// 從今天往前找，最多找 10 天
for (\$i = 3; \$i <= 10; \$i++) {
    \$date = \\Carbon\\Carbon::now()->subDays(\$i);
    if (\$date->isWeekend()) continue;
    
    \$dateString = \$date->format('Ymd');
    try {
        \$data = \$twseApi->getStockDayAll(\$dateString);
        if (!\$data->isEmpty()) {
            echo \$date->format('Y-m-d');
            exit(0);
        }
    } catch (\\Exception \$e) {
        // 繼續嘗試
    }
}
echo date('Y-m-d', strtotime('-3 days'));
")

echo "✅ 找到最近有資料的日期: $LATEST_DATE"
echo ""

echo "=========================================="
echo "第 5 步: 批次抓取股票歷史資料"
echo "=========================================="

# 將股票代碼字串轉換為陣列
IFS=',' read -ra STOCK_ARRAY <<< "$STOCKS"

TOTAL_STOCKS=${#STOCK_ARRAY[@]}
CURRENT=0
SUCCESS_COUNT=0
FAIL_COUNT=0

echo "📊 開始抓取 $TOTAL_STOCKS 檔股票資料"
echo ""

# 建立 log 目錄
mkdir -p storage/logs/crawler

for symbol in "${STOCK_ARRAY[@]}"; do
    CURRENT=$((CURRENT + 1))
    symbol=$(echo $symbol | xargs) # 移除空白
    
    echo "=========================================="
    echo "[$CURRENT/$TOTAL_STOCKS] 處理股票: $symbol"
    echo "=========================================="
    
    # 抓取最近 N 天的資料
    FETCHED_DAYS=0
    for (( i=0; i<DAYS; i++ )); do
        # 使用 PHP 計算日期（相容性更好）
        DATE=$(php -r "echo date('Y-m-d', strtotime('$LATEST_DATE -$i days'));")
        DAY_OF_WEEK=$(php -r "echo date('N', strtotime('$DATE'));")
        
        # 跳過週末 (6=週六, 7=週日)
        if [ $DAY_OF_WEEK -eq 6 ] || [ $DAY_OF_WEEK -eq 7 ]; then
            continue
        fi
        
        echo -n "  📅 $DATE ... "
        
        # 執行爬蟲 (同步模式,直接執行不透過 Queue)
        OUTPUT=$(php artisan crawler:stocks --symbol="$symbol" --date="$DATE" --sync 2>&1)
        
        if echo "$OUTPUT" | grep -q "成功\|success\|完成"; then
            echo "✅"
            FETCHED_DAYS=$((FETCHED_DAYS + 1))
        else
            echo "⚠️"
            # 記錄錯誤到 log
            echo "[$DATE $symbol] $OUTPUT" >> storage/logs/crawler/errors.log
        fi
        
        # 避免 API 限制,稍作延遲
        sleep 0.5
    done
    
    if [ $FETCHED_DAYS -gt 0 ]; then
        echo "  ✅ 成功抓取 $FETCHED_DAYS 天的資料"
        SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
    else
        echo "  ❌ 沒有成功抓取任何資料"
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
    echo ""
done

echo "=========================================="
echo "第 6 步: 驗證資料"
echo "=========================================="

echo "📊 統計資料數量..."
php artisan tinker --execute="
echo '========================================' . PHP_EOL;
echo '📊 資料統計' . PHP_EOL;
echo '========================================' . PHP_EOL;
echo '股票數量: ' . \\App\\Models\\Stock::count() . PHP_EOL;
echo '股價記錄: ' . \\App\\Models\\StockPrice::count() . PHP_EOL;
echo PHP_EOL;

// 顯示每檔股票的資料狀況
echo '各股票資料狀況:' . PHP_EOL;
echo '----------------------------------------' . PHP_EOL;
\$stocks = \\App\\Models\\Stock::withCount('prices')->get();
foreach (\$stocks as \$stock) {
    \$latestPrice = \$stock->prices()->latest('trade_date')->first();
    echo sprintf(
        '%-6s %-10s: %3d 筆資料',
        \$stock->symbol,
        \$stock->name,
        \$stock->prices_count
    );
    if (\$latestPrice) {
        echo sprintf(' (最新: %s, 收盤: %s)', 
            \$latestPrice->trade_date,
            \$latestPrice->close
        );
    }
    echo PHP_EOL;
}

echo PHP_EOL;
echo '最新 5 筆股價記錄:' . PHP_EOL;
echo '----------------------------------------' . PHP_EOL;
\$prices = \\App\\Models\\StockPrice::with('stock')
    ->orderBy('trade_date', 'desc')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get();

foreach (\$prices as \$price) {
    echo sprintf(
        '%s %s (%s): 開 %s, 高 %s, 低 %s, 收 %s, 量 %s' . PHP_EOL,
        \$price->trade_date,
        \$price->stock->name,
        \$price->stock->symbol,
        \$price->open,
        \$price->high,
        \$price->low,
        \$price->close,
        number_format(\$price->volume)
    );
}
"

echo ""
echo "=========================================="
echo "第 7 步: 資料完整性檢查"
echo "=========================================="

php artisan data:validate

echo ""
echo "=========================================="
echo "✅ 批次資料匯入完成!"
echo "=========================================="
echo ""
echo "📋 執行摘要:"
echo "  • 處理股票數: $TOTAL_STOCKS"
echo "  • 成功: $SUCCESS_COUNT"
echo "  • 失敗: $FAIL_COUNT"
echo "  • 資料期間: 最近 $DAYS 天"
echo "  • 最新資料日期: $LATEST_DATE"
echo ""
echo "📝 後續步驟:"
echo "  1. 執行診斷工具檢查特定股票: php diagnose_crawler.php 2330"
echo "  2. 查看 API 狀態: php artisan stocks:status"
echo "  3. 檢查前端顯示是否正常"
echo "  4. 測試 API 端點: GET /api/stocks"
echo ""
echo "📖 查看詳細 log:"
echo "  成功記錄: tail -f storage/logs/laravel.log"
echo "  錯誤記錄: tail -f storage/logs/crawler/errors.log"
echo ""

# 顯示錯誤摘要（如果有）
if [ -f "storage/logs/crawler/errors.log" ]; then
    ERROR_COUNT=$(wc -l < storage/logs/crawler/errors.log)
    if [ $ERROR_COUNT -gt 0 ]; then
        echo "⚠️  發現 $ERROR_COUNT 個錯誤，查看最近 5 個:"
        tail -5 storage/logs/crawler/errors.log
        echo ""
    fi
fi