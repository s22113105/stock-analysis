#!/bin/bash

echo "========================================="
echo "🧪 選擇權交易系統 - 完整測試"
echo "========================================="
echo ""

# 測試 1: 檢查容器狀態
echo "1️⃣ 檢查 Docker 容器..."
docker-compose ps

echo ""
echo "2️⃣ 測試資料庫連線..."
docker-compose exec app php artisan tinker --execute="DB::connection()->getPdo(); echo 'Database connected!';"

echo ""
echo "3️⃣ 檢查 Migration 狀態..."
docker-compose exec app php artisan migrate:status

echo ""
echo "4️⃣ 測試股票爬蟲 (同步模式)..."
docker-compose exec app php artisan crawler:stocks --symbol=2330 --date=2025-11-05 --sync

echo ""
echo "5️⃣ 檢查資料庫記錄..."
docker-compose exec app php artisan tinker --execute="echo 'Stocks: ' . App\Models\Stock::count(); echo '\nPrices: ' . App\Models\StockPrice::count();"

echo ""
echo "6️⃣ 測試 Black-Scholes 計算..."
docker-compose exec app php artisan tinker --execute="
\$bs = app(App\Services\BlackScholesService::class);
\$price = \$bs->calculatePrice(100, 105, 0.25, 0.015, 0.3, 'call');
echo 'Option Price: ' . \$price;
"

echo ""
echo "7️⃣ 測試波動率計算..."
docker-compose exec app php artisan tinker --execute="
\$vol = app(App\Services\VolatilityService::class);
\$hv = \$vol->calculateHistoricalVolatility(1, 30);
echo 'Historical Volatility: ' . \$hv;
"

echo ""
echo "========================================="
echo "✅ 測試完成！"
echo "========================================="