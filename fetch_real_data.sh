#!/bin/bash

# 強制設定編碼，避免 Windows Git Bash 亂碼
export LANG=C.UTF-8

echo "=========================================="
echo "📊 股票分析系統 - Docker 專用爬蟲工具 (v10)"
echo "=========================================="
echo ""
echo "⚠️  警告: 此腳本將透過 Docker 容器執行爬蟲"
echo "ℹ️  說明: 解決 'Connection refused' 資料庫連線問題"
echo ""
read -p "確定要繼續嗎? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "❌ 已取消操作"
    exit 0
fi

# 檢查 docker-compose 是否可用
if ! command -v docker-compose &> /dev/null; then
    echo "❌ 錯誤: 找不到 docker-compose 指令"
    exit 1
fi

echo ""
echo "=========================================="
echo "第 1 步: 檢查 Docker 環境與資料庫"
echo "=========================================="

# 檢查容器是否在執行
if [ -z "$(docker-compose ps -q app)" ]; then
    echo "⚠️  App 容器未啟動，正在啟動..."
    docker-compose up -d
    echo "⏳ 等待服務啟動 (10秒)..."
    sleep 10
fi

# 檢查資料庫連線 (在容器內執行)
echo "🔍 測試容器內資料庫連線..."
docker-compose exec -T app php artisan tinker --execute="
try {
    \DB::connection()->getPdo();
    echo '✅ 資料庫連線正常' . PHP_EOL;
} catch (\Exception \$e) {
    echo '❌ 資料庫連線失敗: ' . \$e->getMessage() . PHP_EOL;
    exit(1);
}
"

if [ $? -ne 0 ]; then
    echo "❌ 無法連線到資料庫，請檢查 .env 設定或 Docker 狀態"
    exit 1
fi

echo ""
echo "=========================================="
echo "第 2 步: 清理舊資料 (可選)"
echo "=========================================="

read -p "是否清空現有股票資料? (yes/no): " clear_data

if [ "$clear_data" == "yes" ]; then
    echo "🗑️  正在清空所有相關資料..."
    docker-compose exec -T app php artisan tinker --execute="
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('backtest_results')->truncate();
            DB::table('predictions')->truncate();
            DB::table('option_prices')->truncate();
            DB::table('options')->truncate();
            DB::table('stock_prices')->truncate();
            DB::table('stocks')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            echo '✅ 所有關聯資料已清空' . PHP_EOL;
        } catch (\Exception \$e) {
            echo '❌ 清空失敗: ' . \$e->getMessage() . PHP_EOL;
        }
    "
else
    echo "⏩ 跳過清理步驟"
fi

echo ""
echo "=========================================="
echo "第 3 步: 設定抓取參數"
echo "=========================================="

# 預設抓取最近 180 天
DEFAULT_DAYS=180
DEFAULT_STOCKS="2330,2317,2454,2412,2882,2303,2308,2886,2884,1301,1303,2002,3045,2881,2891"

read -p "要抓取最近幾天的資料? (預設: $DEFAULT_DAYS): " DAYS
DAYS=${DAYS:-$DEFAULT_DAYS}

read -p "要抓取的股票代碼 (逗號分隔, 預設: 15檔權值股): " STOCKS
STOCKS=${STOCKS:-$DEFAULT_STOCKS}

# 使用今天作為基準
LATEST_DATE=$(date +%Y-%m-%d)

echo ""
echo "📋 抓取設定:"
echo "   期間: 最近 $DAYS 天"
echo "   股票: $STOCKS"
echo ""

echo "=========================================="
echo "第 4 步: 批次抓取股票歷史資料"
echo "=========================================="

IFS=',' read -ra STOCK_ARRAY <<< "$STOCKS"
TOTAL_STOCKS=${#STOCK_ARRAY[@]}
CURRENT=0
SUCCESS_COUNT=0
FAIL_COUNT=0

# 在容器內建立 log 目錄 (確保權限)
docker-compose exec -T app mkdir -p storage/logs/crawler
# 定義暫存檔路徑 (容器內路徑)
TMP_FILE="storage/logs/crawler/last_run.tmp"

for symbol in "${STOCK_ARRAY[@]}"; do
    CURRENT=$((CURRENT + 1))
    symbol=$(echo $symbol | xargs) # 去除空白
    
    echo "=========================================="
    echo "[$CURRENT/$TOTAL_STOCKS] 處理股票: $symbol"
    echo "=========================================="
    
    PROCESSED_MONTHS="|"
    STOCK_FETCH_COUNT=0
    CONSECUTIVE_FAILURES=0
    
    for (( i=0; i<DAYS; i++ )); do
        # 日期計算
        if date -d "today" &>/dev/null; then
             DATE=$(date -d "$LATEST_DATE -$i days" +%Y-%m-%d)
        else
             # 使用 docker 內的 php 來計算日期，確保跨平台兼容
             DATE=$(docker-compose exec -T app php -r "echo date('Y-m-d', strtotime('$LATEST_DATE -$i days'));")
        fi

        if [ -z "$DATE" ]; then continue; fi
        
        YM=${DATE:0:7} # 取得 YYYY-MM
        
        # 檢查月份是否重複
        if [[ "$PROCESSED_MONTHS" == *"|$YM|"* ]]; then
            continue
        fi
        
        PROCESSED_MONTHS="${PROCESSED_MONTHS}${YM}|"
        
        echo -n "  📅 正在抓取 $YM 資料 (基準日: $DATE) ... "
        
        # ✅ 核心修正: 使用 docker-compose exec -T 執行爬蟲
        # 將輸出導向容器內的暫存檔，然後再讀出來
        docker-compose exec -T app bash -c "php artisan crawler:stocks --symbol='$symbol' --date='$DATE' --sync > $TMP_FILE 2>&1"
        EXIT_CODE=$?
        
        # 讀取容器內的暫存檔內容
        OUTPUT=$(docker-compose exec -T app cat $TMP_FILE)
        
        # 判斷邏輯
        if [ $EXIT_CODE -eq 0 ] && echo "$OUTPUT" | grep -q "成功\|更新\|完成\|取得"; then
            echo "✅ 完成"
            STOCK_FETCH_COUNT=$((STOCK_FETCH_COUNT + 1))
            SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
            CONSECUTIVE_FAILURES=0
            sleep 2
        elif echo "$OUTPUT" | grep -q "查無資料\|無交易"; then
            echo "⚠️  無資料 (正常)"
            sleep 1
        else
            echo "❌ 失敗"
            echo "     ----------------------------------------"
            echo "     🔍 錯誤詳情:"
            echo "$OUTPUT" | grep -v "stdout is not a tty" | head -n 5 | sed 's/^/     /g'
            echo "     ----------------------------------------"
            # 寫入 host 端 log 方便查看
            echo "[$DATE $symbol] $OUTPUT" >> storage/logs/crawler/errors.log
            
            FAIL_COUNT=$((FAIL_COUNT + 1))
            CONSECUTIVE_FAILURES=$((CONSECUTIVE_FAILURES + 1))
            echo "     ❄️ 偵測到錯誤，冷卻 10 秒..."
            sleep 10
        fi
        
        if [ $CONSECUTIVE_FAILURES -ge 3 ]; then
             echo "     🔥 連續失敗過多，暫停 30 秒..."
             sleep 30
             CONSECUTIVE_FAILURES=0
        fi
    done
    
    if [ $STOCK_FETCH_COUNT -gt 0 ]; then
        echo "  ✅ $symbol 資料同步完成"
    else
        echo "  ⚠️ $symbol 未更新任何資料"
    fi
    echo ""
done

# 清理
docker-compose exec -T app rm -f $TMP_FILE

echo "=========================================="
echo "第 5 步: 驗證資料"
echo "=========================================="

echo "📊 統計資料數量..."
docker-compose exec -T app php artisan tinker --execute="
echo '----------------------------------------' . PHP_EOL;
\$stocks = \\App\\Models\\Stock::withCount('prices')->get();
foreach (\$stocks as \$stock) {
    \$latest = \$stock->prices()->latest('trade_date')->first();
    \$oldest = \$stock->prices()->oldest('trade_date')->first();
    echo sprintf('%-6s %-10s: %3d 筆', \$stock->symbol, \$stock->name, \$stock->prices_count);
    if (\$oldest && \$latest) echo sprintf(' (%s ~ %s)', \$oldest->trade_date, \$latest->trade_date);
    echo PHP_EOL;
}
"

echo ""
echo "✅ 執行結束!"