#!/bin/bash

echo "=========================================="
echo "🔧 股票資料快速修復工具"
echo "=========================================="
echo ""
echo "此工具會自動使用 3 天前開始的日期抓取資料"
echo "（避開 TWSE API 當天資料未更新的問題）"
echo ""

# 預設股票列表
DEFAULT_STOCKS="2330,2317,2454,2412,2882,2303,2308,2886,2884,1301"

echo "請選擇修復模式:"
echo "1. 快速修復（抓取10檔權值股，最近7天）"
echo "2. 標準修復（抓取15檔股票，最近14天）"
echo "3. 完整修復（抓取20檔股票，最近30天）"
echo "4. 自訂修復"
read -p "請選擇 (1-4): " mode

case $mode in
    1)
        STOCKS="2330,2317,2454,2412,2882"
        DAYS=7
        START_OFFSET=3
        echo "✅ 快速修復模式"
        ;;
    2)
        STOCKS="$DEFAULT_STOCKS,1303,2002,3045,2881,2891"
        DAYS=14
        START_OFFSET=3
        echo "✅ 標準修復模式"
        ;;
    3)
        STOCKS="$DEFAULT_STOCKS,1303,2002,3045,2881,2891,2892,6505,2379,1216,3008"
        DAYS=30
        START_OFFSET=3
        echo "✅ 完整修復模式"
        ;;
    4)
        read -p "輸入股票代碼（逗號分隔）: " STOCKS
        read -p "抓取天數: " DAYS
        read -p "從幾天前開始（預設3）: " START_OFFSET
        START_OFFSET=${START_OFFSET:-3}
        echo "✅ 自訂修復模式"
        ;;
    *)
        echo "❌ 無效選擇"
        exit 1
        ;;
esac

echo ""
echo "📋 修復設定:"
echo "   股票: $STOCKS"
echo "   天數: $DAYS"
echo "   起始: $START_OFFSET 天前"
echo ""

# 將股票代碼轉換為陣列
IFS=',' read -ra STOCK_ARRAY <<< "$STOCKS"
TOTAL_STOCKS=${#STOCK_ARRAY[@]}

echo "=========================================="
echo "開始修復資料..."
echo "=========================================="

# 計算開始日期
START_DATE=$(php -r "echo date('Y-m-d', strtotime('-$START_OFFSET days'));")
echo "📅 起始日期: $START_DATE"
echo ""

SUCCESS_COUNT=0
TOTAL_RECORDS=0

for symbol in "${STOCK_ARRAY[@]}"; do
    symbol=$(echo $symbol | xargs)
    echo "處理 $symbol..."
    
    STOCK_SUCCESS=0
    for (( i=START_OFFSET; i<$((START_OFFSET + DAYS)); i++ )); do
        DATE=$(php -r "echo date('Y-m-d', strtotime('-$i days'));")
        DAY_OF_WEEK=$(php -r "echo date('N', strtotime('$DATE'));")
        
        # 跳過週末
        if [ $DAY_OF_WEEK -eq 6 ] || [ $DAY_OF_WEEK -eq 7 ]; then
            continue
        fi
        
        # 執行爬蟲（不顯示詳細輸出）
        OUTPUT=$(php artisan crawler:stocks --symbol="$symbol" --date="$DATE" --sync 2>&1)
        
        if echo "$OUTPUT" | grep -q "成功\|success\|完成"; then
            STOCK_SUCCESS=$((STOCK_SUCCESS + 1))
            echo -n "✓"
        else
            echo -n "✗"
        fi
    done
    
    echo " ($STOCK_SUCCESS 天)"
    if [ $STOCK_SUCCESS -gt 0 ]; then
        SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
        TOTAL_RECORDS=$((TOTAL_RECORDS + STOCK_SUCCESS))
    fi
done

echo ""
echo "=========================================="
echo "驗證資料..."
echo "=========================================="

php artisan tinker --execute="
\$stockCount = \\App\\Models\\Stock::count();
\$priceCount = \\App\\Models\\StockPrice::count();
\$latestDate = \\App\\Models\\StockPrice::max('trade_date');

echo '✅ 修復完成！' . PHP_EOL;
echo '----------------------------------------' . PHP_EOL;
echo '股票數量: ' . \$stockCount . PHP_EOL;
echo '價格記錄: ' . \$priceCount . PHP_EOL;
echo '最新日期: ' . \$latestDate . PHP_EOL;
"

echo ""
echo "=========================================="
echo "✅ 修復完成"
echo "=========================================="
echo "成功股票: $SUCCESS_COUNT / $TOTAL_STOCKS"
echo "資料筆數: 約 $TOTAL_RECORDS 筆"
echo ""
echo "後續動作:"
echo "1. 執行 php artisan data:validate 驗證資料"
echo "2. 查看前端頁面確認資料顯示正常"
echo "3. 測試 API: curl http://localhost/api/stocks"
echo ""