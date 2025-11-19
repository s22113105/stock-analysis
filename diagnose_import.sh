#!/bin/bash

echo "=========================================="
echo "🔍 爬蟲問題診斷工具"
echo "=========================================="
echo ""

# 測試股票代碼
SYMBOL=${1:-2330}

# 測試多個日期
DATES=(
    "2025-11-18"  # 今天
    "2025-11-15"  # 上週五
    "2025-11-14"  # 上週四
    "2025-11-08"  # 上上週五
    "2025-11-01"  # 更早的日期
)

echo "測試股票: $SYMBOL"
echo ""

for DATE in "${DATES[@]}"; do
    echo "=========================================="
    echo "測試日期: $DATE"
    echo "=========================================="
    
    # 檢查是否為週末
    DAY_OF_WEEK=$(date -d "$DATE" +%u 2>/dev/null)
    if [ $? -ne 0 ]; then
        DAY_OF_WEEK=$(php -r "echo date('N', strtotime('$DATE'));")
    fi
    
    DAY_NAME=$(date -d "$DATE" +%A 2>/dev/null)
    if [ $? -ne 0 ]; then
        DAY_NAME=$(php -r "echo date('l', strtotime('$DATE'));")
    fi
    
    echo "日期資訊: $DAY_NAME (週$DAY_OF_WEEK)"
    
    if [ $DAY_OF_WEEK -eq 6 ] || [ $DAY_OF_WEEK -eq 7 ]; then
        echo "❌ 這是週末,不是交易日"
        echo ""
        continue
    fi
    
    # 測試 TWSE API
    echo ""
    echo "1️⃣ 測試 TWSE API 連線..."
    DATE_STRING=$(echo $DATE | tr -d '-')
    API_URL="https://openapi.twse.com.tw/exchangeReport/STOCK_DAY_ALL?response=json&date=$DATE_STRING"
    
    echo "API URL: $API_URL"
    
    API_RESPONSE=$(curl -s "$API_URL")
    
    if [ -z "$API_RESPONSE" ]; then
        echo "❌ API 無回應"
    elif echo "$API_RESPONSE" | grep -q "error"; then
        echo "❌ API 回傳錯誤"
        echo "$API_RESPONSE" | head -5
    elif echo "$API_RESPONSE" | grep -q "$SYMBOL"; then
        echo "✅ API 有 $SYMBOL 的資料"
        # 顯示該股票的資料片段
        echo "$API_RESPONSE" | grep -o "\"Code\":\"$SYMBOL\"[^}]*" | head -1
    else
        echo "⚠️  API 有回應但沒有 $SYMBOL 的資料"
        # 顯示 API 回應的資料筆數
        RECORD_COUNT=$(echo "$API_RESPONSE" | grep -o "\"Code\":" | wc -l)
        echo "API 回傳 $RECORD_COUNT 筆股票資料"
    fi
    
    # 測試 Laravel 爬蟲
    echo ""
    echo "2️⃣ 測試 Laravel 爬蟲..."
    
    CRAWLER_OUTPUT=$(php artisan crawler:stocks --symbol=$SYMBOL --date=$DATE --sync 2>&1)
    
    if echo "$CRAWLER_OUTPUT" | grep -q "成功"; then
        echo "✅ 爬蟲執行成功"
    else
        echo "❌ 爬蟲執行失敗"
        echo "錯誤訊息:"
        echo "$CRAWLER_OUTPUT" | tail -10
    fi
    
    # 檢查資料庫
    echo ""
    echo "3️⃣ 檢查資料庫..."
    
    php artisan tinker --execute="
    \$stock = \App\Models\Stock::where('symbol', '$SYMBOL')->first();
    if (\$stock) {
        echo '✅ 股票存在: ' . \$stock->name . PHP_EOL;
        \$price = \$stock->prices()->where('trade_date', '$DATE')->first();
        if (\$price) {
            echo '✅ 找到 $DATE 的價格資料' . PHP_EOL;
            echo '   收盤價: ' . \$price->close . PHP_EOL;
            echo '   成交量: ' . number_format(\$price->volume) . PHP_EOL;
        } else {
            echo '❌ 沒有 $DATE 的價格資料' . PHP_EOL;
        }
    } else {
        echo '❌ 股票不存在' . PHP_EOL;
    }
    " 2>&1 | grep -v "^>"
    
    echo ""
    echo "----------------------------------------"
    echo ""
done

echo "=========================================="
echo "📋 診斷總結"
echo "=========================================="
echo ""
echo "建議操作:"
echo "1. 使用有資料的日期 (通常是 3 天前或更早)"
echo "2. 避免使用今天和週末的日期"
echo "3. 如果 API 有資料但爬蟲失敗,查看 log:"
echo "   tail -f storage/logs/laravel.log"
echo ""
echo "4. 手動測試指令:"
echo "   php artisan crawler:stocks --symbol=$SYMBOL --date=2025-11-08 --sync"
echo ""
