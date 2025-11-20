#!/bin/bash

echo "=========================================="
echo "🚑 資料庫連線急救工具"
echo "=========================================="

# 1. 重啟資料庫容器
echo "1️⃣ 重啟 MySQL 容器..."
docker-compose restart db

echo "⏳ 等待 MySQL 啟動 (20秒)..."
for i in {20..1}; do
    echo -ne "$i... \r"
    sleep 1
done
echo ""

# 2. 測試連線
echo "2️⃣ 測試連線..."
if docker-compose exec -T app php artisan tinker --execute="try{\DB::connection()->getPdo();echo '✅ 連線成功';}catch(\Exception \$e){echo '❌ 連線失敗: '.\$e->getMessage();}"; then
    echo ""
    echo "🎉 修復完成！現在可以重新執行 ./fetch_real_data.sh 了"
else
    echo ""
    echo "❌ 仍然無法連線。請檢查 docker logs db 看是否有錯誤訊息。"
    
    echo "📋 最近 20 行資料庫日誌:"
    docker-compose logs --tail=20 db
fi
```

### 執行完急救腳本後...

如果顯示 **「✅ 連線成功」**，請再次執行您的爬蟲腳本：

```bash
./fetch_real_data.sh