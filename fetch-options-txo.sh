#!/bin/bash

# 強制設定編碼，避免 Windows Git Bash 亂碼
export LANG=C.UTF-8

echo "=========================================="
echo "📊 臺指選擇權 TXO 分析系統 - Docker 專用爬蟲工具 (v1.0)"
echo "=========================================="
echo ""
echo "⚠️  警告: 此腳本將透過 Docker 容器執行爬蟲"
echo "ℹ️  說明: 專門用於抓取臺指選擇權 (TXO) 資料"
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

# 檢查容器是否在執行 (沿用您的檢查邏輯)
if [ -z "$(docker-compose ps -q app)" ]; then
    echo "⚠️  App 容器未啟動，正在啟動..."
    docker-compose up -d
    echo "⏳ 等待服務啟動 (10秒)..."
    sleep 10
fi

# 檢查資料庫連線 (在容器內執行) (沿用您的檢查邏輯)
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

# 由於您的清理指令已經包含選擇權資料，故保留原樣
read -p "是否清空現有選擇權資料及相關資料? (yes/no): " clear_data

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

read -p "要抓取最近幾天的資料? (預設: $DEFAULT_DAYS): " DAYS
DAYS=${DAYS:-$DEFAULT_DAYS}

# 使用今天作為基準
LATEST_DATE=$(date +%Y-%m-%d)
SYMBOL="TXO"

echo ""
echo "📋 抓取設定:"
echo "   期間: 最近 $DAYS 天"
echo "   標的: 臺指選擇權 ($SYMBOL)"
echo ""

echo "=========================================="
echo "第 4 步: 批次抓取選擇權歷史資料"
echo "=========================================="

# 在容器內建立 log 目錄 (確保權限)
docker-compose exec -T app mkdir -p storage/logs/crawler
# 定義暫存檔路徑 (容器內路徑)
TMP_FILE="storage/logs/crawler/last_run_options.tmp"

SUCCESS_COUNT=0
FAIL_COUNT=0
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

    echo -n "  📅 正在抓取 $DATE 資料 ... "

    # ✅ 核心修正: 呼叫 CrawlOptionDataCommand 指令
    docker-compose exec -T app bash -c "php artisan crawler:options --date='$DATE' --sync > $TMP_FILE 2>&1"
    EXIT_CODE=$?

    # 讀取容器內的暫存檔內容
    OUTPUT=$(docker-compose exec -T app cat $TMP_FILE)

    # 判斷邏輯
    if [ $EXIT_CODE -eq 0 ] && echo "$OUTPUT" | grep -q "成功\|更新\|完成\|取得\|執行完成"; then
        echo "✅ 完成"
        SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
        CONSECUTIVE_FAILURES=0
        sleep 2
    elif echo "$OUTPUT" | grep -q "查無資料\|無交易"; then
        echo "⚠️  無資料 (正常)"
        sleep 1
    else
        echo "❌ 失敗"
        echo "     ----------------------------------------"
        echo "     🔍 錯誤詳情:"
        # 排除不相關的 Docker 警告
        echo "$OUTPUT" | grep -v "stdout is not a tty" | head -n 5 | sed 's/^/     /g'
        echo "     ----------------------------------------"
        # 寫入 host 端 log 方便查看
        echo "[$DATE $SYMBOL] $OUTPUT" >> storage/logs/crawler/options_errors.log

        FAIL_COUNT=$((FAIL_COUNT + 1))
        CONSECUTIVE_FAILURES=$((CONSECUTIVE_FAILURES + 1))
        echo "     ❄️ 偵測到錯誤，冷卻 10 秒..."
        sleep 10
    fi

    if [ $CONSECUTIVE_FAILURES -ge 3 ]; then
         echo "     🔥 連續失敗過多，暫停 30 秒..."
         sleep 30
         CONSECUTIVE_FAILURES=0
    fi
done

# 清理
docker-compose exec -T app rm -f $TMP_FILE

echo ""
echo "✅ $SYMBOL 資料批次同步完成!"
echo ""
echo "=========================================="
echo "第 5 步: 驗證資料"
echo "=========================================="

echo "📊 統計 TXO 選擇權合約數量..."
# 驗證步驟：使用 Option Model 的 TXO scope 和 prices 關聯 來統計
docker-compose exec -T app php artisan tinker --execute="
echo '----------------------------------------' . PHP_EOL;
\$options = \\App\\Models\\Option::TXO()
    ->where('is_active', true)
    ->withCount('prices')
    ->get();

echo sprintf('總共抓到 %d 條 TXO 有效合約', \$options->count()) . PHP_EOL;

if (\$options->isNotEmpty()) {
    echo '----------------------------------------' . PHP_EOL;
    echo '前 10 筆合約價格數量:' . PHP_EOL;
    \$options->sortByDesc('prices_count')->take(10)->each(function (\$option) {
        echo sprintf(
            '%-10s: %5d 筆 (履約價: %s)',
            \$option->option_code,
            \$option->prices_count,
            \$option->strike_price
        ) . PHP_EOL;
    });
}
echo '----------------------------------------' . PHP_EOL;
"

echo ""
echo "✅ 執行結束!"
