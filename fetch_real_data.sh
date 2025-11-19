#!/bin/bash

echo "=========================================="
echo "📊 股票分析系統 - 真實資料匯入工具"
echo "=========================================="
echo ""
echo "⚠️  警告: 此腳本將清除假資料並匯入真實台股資料"
echo ""
read -p "確定要繼續嗎? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ 已取消操作"
    exit 0
fi

echo ""
echo "=========================================="
echo "第 1 步: 備份現有資料"
echo "=========================================="

BACKUP_DIR="storage/backups/$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

echo "📦 備份資料庫..."
php artisan db:backup --path="$BACKUP_DIR/database.sql"

if [ $? -eq 0 ]; then
    echo "✅ 資料庫已備份至: $BACKUP_DIR"
else
    echo "❌ 備份失敗,請檢查"
    exit 1
fi

echo ""
echo "=========================================="
echo "第 2 步: 清除測試資料"
echo "=========================================="

read -p "是否清空 stocks 和 stock_prices 資料表? (yes/no): " clear_stocks
read -p "是否清空 options 和 option_prices 資料表? (yes/no): " clear_options

if [ "$clear_stocks" == "yes" ]; then
    echo "🗑️  清空股票資料..."
    php artisan tinker --execute="
        DB::table('stock_prices')->truncate();
        DB::table('stocks')->truncate();
        echo '✅ 股票資料已清空\n';
    "
fi

if [ "$clear_options" == "yes" ]; then
    echo "🗑️  清空選擇權資料..."
    php artisan tinker --execute="
        DB::table('option_prices')->truncate();
        DB::table('options')->truncate();
        echo '✅ 選擇權資料已清空\n';
    "
fi

echo ""
echo "=========================================="
echo "第 3 步: 設定抓取參數"
echo "=========================================="

# 預設抓取最近 30 天的資料
DEFAULT_DAYS=30
DEFAULT_STOCKS="2330,2317,2454,2412,2882,2303,2308,2886,2884,1301"

read -p "要抓取最近幾天的資料? (預設: $DEFAULT_DAYS): " DAYS
DAYS=${DAYS:-$DEFAULT_DAYS}

read -p "要抓取的股票代碼 (逗號分隔, 預設: 十檔權值股): " STOCKS
STOCKS=${STOCKS:-$DEFAULT_STOCKS}

echo ""
echo "📋 抓取設定:"
echo "   期間: 最近 $DAYS 天"
echo "   股票: $STOCKS"
echo ""

echo "=========================================="
echo "第 4 步: 批次抓取股票歷史資料"
echo "=========================================="

# 將股票代碼字串轉換為陣列
IFS=',' read -ra STOCK_ARRAY <<< "$STOCKS"

TOTAL_STOCKS=${#STOCK_ARRAY[@]}
CURRENT=0

for symbol in "${STOCK_ARRAY[@]}"; do
    CURRENT=$((CURRENT + 1))
    symbol=$(echo $symbol | xargs) # 移除空白
    
    echo ""
    echo "[$CURRENT/$TOTAL_STOCKS] 處理股票: $symbol"
    echo "----------------------------------------"
    
    # 抓取最近 N 天的資料
    for (( i=DAYS-1; i>=0; i-- )); do
        DATE=$(date -d "$i days ago" +%Y-%m-%d)
        DAY_OF_WEEK=$(date -d "$i days ago" +%u)
        
        # 跳過週末 (6=週六, 7=週日)
        if [ $DAY_OF_WEEK -eq 6 ] || [ $DAY_OF_WEEK -eq 7 ]; then
            continue
        fi
        
        echo -n "  📅 $DATE ... "
        
        # 執行爬蟲 (同步模式,直接執行不透過 Queue)
        php artisan crawler:stocks --symbol="$symbol" --date="$DATE" --sync 2>&1 | grep -q "成功"
        
        if [ $? -eq 0 ]; then
            echo "✅"
        else
            echo "⚠️ "
        fi
        
        # 避免 API 限制,稍作延遲
        sleep 0.5
    done
done

echo ""
echo "=========================================="
echo "第 5 步: 抓取選擇權資料 (可選)"
echo "=========================================="

read -p "是否抓取選擇權資料? (yes/no): " fetch_options

if [ "$fetch_options" == "yes" ]; then
    echo "📊 抓取最近 $DAYS 天的選擇權資料..."
    
    for (( i=DAYS-1; i>=0; i-- )); do
        DATE=$(date -d "$i days ago" +%Y-%m-%d)
        DAY_OF_WEEK=$(date -d "$i days ago" +%u)
        
        # 跳過週末
        if [ $DAY_OF_WEEK -eq 6 ] || [ $DAY_OF_WEEK -eq 7 ]; then
            continue
        fi
        
        echo -n "  📅 $DATE ... "
        
        php artisan crawler:options --date="$DATE" --sync 2>&1 | grep -q "成功"
        
        if [ $? -eq 0 ]; then
            echo "✅"
        else
            echo "⚠️ "
        fi
        
        sleep 1
    done
fi

echo ""
echo "=========================================="
echo "第 6 步: 驗證資料"
echo "=========================================="

echo "📊 統計資料數量..."
php artisan tinker --execute="
echo '股票數量: ' . \App\Models\Stock::count() . PHP_EOL;
echo '股價記錄: ' . \App\Models\StockPrice::count() . PHP_EOL;
echo '選擇權合約: ' . \App\Models\Option::count() . PHP_EOL;
echo '選擇權價格: ' . \App\Models\OptionPrice::count() . PHP_EOL;
echo PHP_EOL;

// 顯示最新的 5 筆股價記錄
echo '最新股價記錄:' . PHP_EOL;
echo '----------------------------------------' . PHP_EOL;
\$prices = \App\Models\StockPrice::with('stock')
    ->orderBy('trade_date', 'desc')
    ->limit(5)
    ->get();

foreach (\$prices as \$price) {
    echo sprintf(
        '%s (%s) %s: 收盤 %s, 成交量 %s' . PHP_EOL,
        \$price->stock->name,
        \$price->stock->symbol,
        \$price->trade_date,
        \$price->close,
        number_format(\$price->volume)
    );
}
"

echo ""
echo "=========================================="
echo "第 7 步: 設定自動更新排程"
echo "=========================================="

echo "📅 檢查 Laravel Scheduler 設定..."
echo ""
echo "目前排程設定 (app/Console/Kernel.php):"
echo "  • 每天 13:30 - 股票資料爬蟲"
echo "  • 每天 13:45 - 選擇權資料爬蟲"
echo "  • 每天 14:00 - 股票資料爬蟲 (備用)"
echo ""

read -p "是否啟用 Laravel Scheduler? (yes/no): " enable_scheduler

if [ "$enable_scheduler" == "yes" ]; then
    echo ""
    echo "請將以下行加入 crontab (執行: crontab -e):"
    echo ""
    echo "* * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
    echo ""
    echo "或在 Docker 中,確保 scheduler 容器正常運行:"
    echo "docker-compose up -d scheduler"
fi

echo ""
echo "=========================================="
echo "✅ 真實資料匯入完成!"
echo "=========================================="
echo ""
echo "📋 摘要:"
echo "  • 備份位置: $BACKUP_DIR"
echo "  • 抓取天數: $DAYS 天"
echo "  • 抓取股票: $STOCKS"
echo ""
echo "📝 後續步驟:"
echo "  1. 檢查前端顯示是否正常"
echo "  2. 測試 API 端點回應"
echo "  3. 驗證圖表資料正確性"
echo "  4. 設定每日自動更新"
echo ""
echo "📖 查看詳細 log:"
echo "  tail -f storage/logs/laravel.log"
echo ""
