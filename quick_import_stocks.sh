#!/bin/bash

echo "=========================================="
echo "⚡ 快速匯入 - 台股主要標的 (修正版)"
echo "=========================================="
echo ""

# 台股主要標的列表
MAJOR_STOCKS=(
    # 權值股 (市值前十)
    "2330:台積電"
    "2317:鴻海"
    "2454:聯發科"
    "2412:中華電"
    "2882:國泰金"
    "2881:富邦金"
    "2886:兆豐金"
    "2303:聯電"
    "2308:台達電"
    "1301:台塑"
    
    # 熱門電子股
    "2409:友達"
    "2357:華碩"
    "2353:宏碁"
    "3711:日月光投控"
    "2379:瑞昱"
    
    # 金融股
    "2884:玉山金"
    "2891:中信金"
    "2887:台新金"
    "2892:第一金"
    
    # 傳產股
    "2002:中鋼"
    "1303:南亞"
    "1326:台化"
    "2885:元大金"
)

# 設定抓取天數 (預設 7 天)
DAYS=${1:-7}

# ⚠️ 重要: 從 3 天前開始抓取,避免當天資料尚未更新
START_OFFSET=3

echo "📊 將匯入 ${#MAJOR_STOCKS[@]} 檔主要股票"
echo "📅 期間: 從 ${START_OFFSET} 天前開始,往前推 $DAYS 天"
echo ""
echo "⚠️  注意: 為確保資料可用性,將從 3 天前開始抓取"
echo ""

read -p "確定要繼續嗎? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ 已取消操作"
    exit 0
fi

echo ""
echo "開始匯入..."
echo ""

TOTAL=${#MAJOR_STOCKS[@]}
CURRENT=0
SUCCESS=0
FAILED=0

for stock_info in "${MAJOR_STOCKS[@]}"; do
    CURRENT=$((CURRENT + 1))
    
    # 分割股票代碼和名稱
    IFS=':' read -ra PARTS <<< "$stock_info"
    SYMBOL="${PARTS[0]}"
    NAME="${PARTS[1]}"
    
    echo "[$CURRENT/$TOTAL] 處理: $SYMBOL ($NAME)"
    echo "----------------------------------------"
    
    # 從 START_OFFSET 天前開始,抓取 DAYS 天的資料
    for (( i=START_OFFSET+DAYS-1; i>=START_OFFSET; i-- )); do
        DATE=$(date -d "$i days ago" +%Y-%m-%d 2>/dev/null)
        
        # 如果 date 命令不支援 -d,使用 Python
        if [ $? -ne 0 ]; then
            DATE=$(php -r "echo date('Y-m-d', strtotime('-$i days'));")
        fi
        
        DAY_OF_WEEK=$(date -d "$DATE" +%u 2>/dev/null)
        if [ $? -ne 0 ]; then
            DAY_OF_WEEK=$(php -r "echo date('N', strtotime('$DATE'));")
        fi
        
        # 跳過週末
        if [ $DAY_OF_WEEK -eq 6 ] || [ $DAY_OF_WEEK -eq 7 ]; then
            continue
        fi
        
        echo -n "  📅 $DATE ... "
        
        # 執行爬蟲
        RESULT=$(php artisan crawler:stocks --symbol="$SYMBOL" --date="$DATE" --sync 2>&1)
        
        if echo "$RESULT" | grep -q "成功"; then
            echo "✅"
            SUCCESS=$((SUCCESS + 1))
        elif echo "$RESULT" | grep -q "已存在"; then
            echo "⏭️  (已存在)"
            SUCCESS=$((SUCCESS + 1))
        else
            echo "⚠️  $(echo "$RESULT" | grep -oP '(?<=失敗:).*' | head -1 | xargs)"
            FAILED=$((FAILED + 1))
            
            # 顯示詳細錯誤 (可選)
            # echo "$RESULT" | tail -3
        fi
        
        # 避免 API 限制
        sleep 0.3
    done
    
    echo ""
done

echo "=========================================="
echo "✅ 匯入完成"
echo "=========================================="
echo "成功: $SUCCESS 筆"
echo "失敗: $FAILED 筆"
echo ""

# 顯示統計
php artisan tinker --execute="
echo '目前資料統計:' . PHP_EOL;
echo '----------------------------------------' . PHP_EOL;
echo '股票數量: ' . \App\Models\Stock::count() . PHP_EOL;
echo '股價記錄: ' . \App\Models\StockPrice::count() . PHP_EOL;
echo PHP_EOL;

// 檢查最新和最舊的資料日期
\$latest = \App\Models\StockPrice::max('trade_date');
\$earliest = \App\Models\StockPrice::min('trade_date');
echo '資料範圍: ' . \$earliest . ' 至 ' . \$latest . PHP_EOL;
echo PHP_EOL;

// 按股票分組統計
echo '各股票資料筆數 (前10名):' . PHP_EOL;
\$stats = \App\Models\Stock::withCount('prices')
    ->orderBy('prices_count', 'desc')
    ->limit(10)
    ->get();

foreach (\$stats as \$stock) {
    echo sprintf('  %s (%s): %d 筆' . PHP_EOL, 
        \$stock->name, 
        \$stock->symbol, 
        \$stock->prices_count
    );
}
"

echo ""
echo "💡 提示:"
echo "  • 查看詳細 log: tail -f storage/logs/laravel.log"
echo "  • 如果仍有失敗,可手動測試: php artisan crawler:stocks --symbol=2330 --date=2025-11-08"
echo "  • 確認 TWSE API 狀態: curl 'https://openapi.twse.com.tw/exchangeReport/STOCK_DAY_ALL?response=json&date=20251108'"
echo ""