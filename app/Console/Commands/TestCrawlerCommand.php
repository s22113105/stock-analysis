<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use Illuminate\Console\Command;
use Carbon\Carbon;

class TestCrawlerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:test
                            {--symbol=2330 : 股票代碼}
                            {--days=7 : 測試天數}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '測試爬蟲功能（使用模擬資料）';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $symbol = $this->option('symbol');
        $days = (int) $this->option('days');

        $this->info("========================================");
        $this->info("📊 開始測試爬蟲功能");
        $this->info("========================================");
        $this->info("股票代碼: {$symbol}");
        $this->info("測試天數: {$days}");
        $this->newLine();

        // 確認股票存在
        $stock = Stock::where('symbol', $symbol)->first();

        if (!$stock) {
            $this->warn("股票 {$symbol} 不存在，建立測試資料...");

            $stock = Stock::create([
                'symbol' => $symbol,
                'name' => $this->getStockName($symbol),
                'exchange' => 'TWSE',
                'industry' => '測試產業',
                'is_active' => true,
                'meta_data' => [
                    'created_by' => 'test_crawler',
                    'created_at' => now()->toDateTimeString()
                ]
            ]);

            $this->info("✅ 已建立股票資料");
        } else {
            $this->info("✅ 找到股票: {$stock->name}");
        }

        $this->newLine();
        $this->info("開始產生模擬價格資料...");

        $bar = $this->output->createProgressBar($days);
        $bar->start();

        $basePrice = 100 + ($stock->id % 500); // 基準價格
        $insertedCount = 0;
        $updatedCount = 0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);

            // 跳過週末
            if ($date->isWeekend()) {
                $bar->advance();
                continue;
            }

            // 產生隨機價格資料
            $volatility = rand(1, 5) / 100; // 1-5% 波動
            $open = $basePrice * (1 + (rand(-100, 100) / 10000));
            $close = $open * (1 + (rand(-100, 100) / 10000) * $volatility);
            $high = max($open, $close) * (1 + rand(0, 100) / 10000);
            $low = min($open, $close) * (1 - rand(0, 100) / 10000);
            $volume = rand(1000, 100000) * 1000;

            $change = $close - $basePrice;
            $changePercent = ($change / $basePrice) * 100;

            $priceData = [
                'stock_id' => $stock->id,
                'trade_date' => $date->format('Y-m-d'),
                'open' => round($open, 2),
                'high' => round($high, 2),
                'low' => round($low, 2),
                'close' => round($close, 2),
                'volume' => $volume,
                'turnover' => round($volume * $close, 0),
                'change' => round($change, 2),
                'change_percent' => round($changePercent, 2),
            ];

            $existingPrice = StockPrice::where('stock_id', $stock->id)
                ->where('trade_date', $date->format('Y-m-d'))
                ->first();

            if ($existingPrice) {
                $existingPrice->update($priceData);
                $updatedCount++;
            } else {
                StockPrice::create($priceData);
                $insertedCount++;
            }

            $basePrice = $close; // 更新基準價格

            $bar->advance();
            usleep(50000); // 模擬處理延遲
        }

        $bar->finish();
        $this->newLine(2);

        // 顯示統計資訊
        $this->info("========================================");
        $this->info("📈 測試完成統計");
        $this->info("========================================");
        $this->table(
            ['項目', '數值'],
            [
                ['股票代碼', $symbol],
                ['股票名稱', $stock->name],
                ['新增筆數', $insertedCount],
                ['更新筆數', $updatedCount],
                ['總處理筆數', $insertedCount + $updatedCount],
            ]
        );

        // 顯示最近價格
        $recentPrices = StockPrice::where('stock_id', $stock->id)
            ->orderBy('trade_date', 'desc')
            ->limit(5)
            ->get();

        if ($recentPrices->count() > 0) {
            $this->newLine();
            $this->info("最近 5 筆價格資料:");
            $this->table(
                ['日期', '開盤', '最高', '最低', '收盤', '成交量', '漲跌%'],
                $recentPrices->map(function ($price) {
                    return [
                        $price->trade_date,
                        $price->open,
                        $price->high,
                        $price->low,
                        $price->close,
                        number_format($price->volume),
                        $price->change_percent . '%'
                    ];
                })
            );
        }

        $this->newLine();
        $this->info("💡 提示：這是使用模擬資料的測試，實際爬蟲請使用:");
        $this->info("   php artisan crawler:stocks --symbol={$symbol}");

        return 0;
    }

    /**
     * 取得股票名稱
     */
    private function getStockName($symbol)
    {
        $names = [
            '2330' => '台積電',
            '2317' => '鴻海',
            '2454' => '聯發科',
            '2412' => '中華電',
            '2882' => '國泰金',
            '2303' => '聯電',
            '2308' => '台達電',
        ];

        return $names[$symbol] ?? '測試股票';
    }
}
