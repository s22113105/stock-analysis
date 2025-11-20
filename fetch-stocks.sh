#!/bin/bash

# 股票代碼列表
stocks=(2330 2317 2454 2308 2303 2882 2881)

# 要抓取的天數
days=7

echo "開始抓取股票資料..."

for symbol in "${stocks[@]}"; do
    echo "抓取 $symbol ..."
    
    for ((i=$days-1; i>=0; i--)); do
        date=$(date -d "$i days ago" +%Y-%m-%d)
        
        # 跳過週末
        dow=$(date -d "$date" +%u)
        if [ $dow -eq 6 ] || [ $dow -eq 7 ]; then
            continue
        fi
        
        php artisan crawler:stocks --symbol=$symbol --date=$date --sync
        
        # 避免API請求過於頻繁
        sleep 2
    done
    
    echo "✅ $symbol 完成"
done

echo "所有股票抓取完成!"