#!/bin/bash

#==============================================================================
# 清除測試資料並批次抓取真實股票資料
# 
# 使用方式:
#   chmod +x clean-and-fetch-stocks.sh
#   ./clean-and-fetch-stocks.sh
#
# 功能:
#   1. 清除所有測試資料(保留使用者)
#   2. 批次抓取指定股票的歷史資料
#   3. 驗證資料完整性
#==============================================================================

set -e  # 遇到錯誤立即退出

# 顏色定義
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================"
echo "🔄 清除測試資料並抓取真實資料"
echo -e "========================================${NC}"
echo ""

# ============ 設定區 ============

# 要抓取的股票代碼(可自行修改)
STOCKS=(
    2330  # 台積電
    2317  # 鴻海
    2454  # 聯發科
    2308  # 台達電
    2303  # 聯電
    2882  # 國泰金
    2881  # 富邦金
    2891  # 中信金
    2892  # 第一金
    2886  # 兆豐金
)

# 抓取天數(預設30天)
DAYS=30

# ================================

echo -e "${YELLOW}📋 設定:${NC}"
echo "  股票數量: ${#STOCKS[@]} 支"
echo "  股票代碼: ${STOCKS[@]}"
echo "  抓取天數: ${DAYS} 天(實際交易日會更少)"
echo ""

# 確認操作
read -p "確定要繼續嗎? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo -e "${RED}❌ 已取消操作${NC}"
    exit 0
fi

echo ""

# ============ 步驟 1: 清除測試資料 ============

echo -e "${GREEN}步驟 1/3: 清除測試資料${NC}"
echo ""

# 執行清除指令(只清除資料,保留使用者)
php artisan data:reset-and-fetch --keep-users --skip-confirm

if [ $? -ne 0 ]; then
    echo -e "${RED}❌ 清除資料失敗${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}✅ 資料清除完成${NC}"
echo ""

# ============ 步驟 2: 抓取股票資料 ============

echo -e "${GREEN}步驟 2/3: 抓取股票資料${NC}"
echo ""

# 計算交易日
TRADING_DAYS=()
for ((i=$DAYS-1; i>=0; i--)); do
    date=$(date -d "$i days ago" +%Y-%m-%d)
    dow=$(date -d "$date" +%u)
    
    # 跳過週末
    if [ $dow -ne 6 ] && [ $dow -ne 7 ]; then
        TRADING_DAYS+=("$date")
    fi
done

echo -e "${BLUE}找到 ${#TRADING_DAYS[@]} 個交易日${NC}"
echo -e "${BLUE}日期範圍: ${TRADING_DAYS[-1]} 到 ${TRADING_DAYS[0]}${NC}"
echo ""

# 計算總任務數
TOTAL_TASKS=$((${#STOCKS[@]} * ${#TRADING_DAYS[@]}))
CURRENT_TASK=0
SUCCESS_COUNT=0
FAIL_COUNT=0

echo -e "${YELLOW}開始抓取 ${#STOCKS[@]} 支股票 x ${#TRADING_DAYS[@]} 個交易日 = $TOTAL_TASKS 個任務${NC}"
echo ""

# 批次抓取
for stock in "${STOCKS[@]}"; do
    echo -e "${BLUE}📊 抓取股票: $stock${NC}"
    
    for date in "${TRADING_DAYS[@]}"; do
        CURRENT_TASK=$((CURRENT_TASK + 1))
        
        printf "  [%3d/%3d] %s - %s ... " $CURRENT_TASK $TOTAL_TASKS $stock $date
        
        # 執行抓取
        if php artisan crawler:stocks --symbol=$stock --date=$date --sync > /dev/null 2>&1; then
            echo -e "${GREEN}✓${NC}"
            SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
        else
            echo -e "${RED}✗${NC}"
            FAIL_COUNT=$((FAIL_COUNT + 1))
        fi
        
        # 避免API請求過於頻繁
        sleep 0.5
    done
    
    echo ""
done

echo -e "${GREEN}✅ 股票資料抓取完成${NC}"
echo -e "${BLUE}成功: $SUCCESS_COUNT 個任務${NC}"
if [ $FAIL_COUNT -gt 0 ]; then
    echo -e "${YELLOW}失敗: $FAIL_COUNT 個任務${NC}"
fi
echo ""

# ============ 步驟 3: 驗證資料 ============

echo -e "${GREEN}步驟 3/3: 驗證資料完整性${NC}"
echo ""

php artisan data:validate --report

echo ""

# ============ 完成 ============

echo -e "${GREEN}========================================"
echo "✅ 所有操作完成!"
echo -e "========================================${NC}"
echo ""

# 顯示統計
echo -e "${BLUE}📊 資料統計:${NC}"
php artisan tinker --execute="
echo '股票數量: ' . App\Models\Stock::count() . PHP_EOL;
echo '股價記錄: ' . App\Models\StockPrice::count() . PHP_EOL;
echo '最新日期: ' . App\Models\StockPrice::max('trade_date') . PHP_EOL;
"

echo ""
echo -e "${YELLOW}📝 建議的下一步:${NC}"
echo "  1. 檢查資料: php artisan tinker"
echo "  2. 測試 API: curl http://localhost:8000/api/stocks"
echo "  3. 開啟前端: npm run dev"
echo ""
echo -e "${GREEN}🎉 系統已準備就緒!${NC}"