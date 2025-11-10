<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Stock;
use App\Models\StockPrice;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->command->info('🌱 開始建立測試資料...');

        // 建立測試股票
        $stocks = [
            ['symbol' => '2330', 'name' => '台積電', 'exchange' => 'TWSE', 'industry' => '半導體'],
            ['symbol' => '2317', 'name' => '鴻海', 'exchange' => 'TWSE', 'industry' => '電子'],
            ['symbol' => '2454', 'name' => '聯發科', 'exchange' => 'TWSE', 'industry' => '半導體'],
            ['symbol' => '2308', 'name' => '台達電', 'exchange' => 'TWSE', 'industry' => '電子'],
            ['symbol' => '2882', 'name' => '國泰金', 'exchange' => 'TWSE', 'industry' => '金融'],
            ['symbol' => '2881', 'name' => '富邦金', 'exchange' => 'TWSE', 'industry' => '金融'],
            ['symbol' => '2891', 'name' => '中信金', 'exchange' => 'TWSE', 'industry' => '金融'],
            ['symbol' => '0050', 'name' => '元大台灣50', 'exchange' => 'TWSE', 'industry' => 'ETF'],
            ['symbol' => '0056', 'name' => '元大高股息', 'exchange' => 'TWSE', 'industry' => 'ETF'],
            ['symbol' => '006208', 'name' => '富邦台50', 'exchange' => 'TWSE', 'industry' => 'ETF'],
        ];

        foreach ($stocks as $stockData) {
            $stock = Stock::create($stockData);
            $this->command->info("✓ 建立股票: {$stockData['symbol']} {$stockData['name']}");

            // 為每支股票建立 30 天的模擬價格資料
            $this->createMockPrices($stock, 30);
        }

        $this->command->info('');
        $this->command->info('✅ 測試資料建立完成！');
        $this->command->info('📊 股票數量: ' . Stock::count());
        $this->command->info('📈 價格記錄: ' . StockPrice::count());
    }

    /**
     * 建立模擬價格資料
     */
    private function createMockPrices(Stock $stock, int $days)
    {
        $basePrice = $this->getBasePrice($stock->symbol);
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);

            // 跳過週末
            if ($date->isWeekend()) {
                continue;
            }

            // 模擬價格波動
            $changePercent = (mt_rand(-300, 300) / 100); // -3% ~ +3%
            $open = $basePrice * (1 + (mt_rand(-100, 100) / 1000));
            $close = $basePrice * (1 + ($changePercent / 100));
            $high = max($open, $close) * (1 + (mt_rand(0, 100) / 1000));
            $low = min($open, $close) * (1 - (mt_rand(0, 100) / 1000));
            $volume = mt_rand(10000, 100000) * 1000;

            StockPrice::create([
                'stock_id' => $stock->id,
                'trade_date' => $date->format('Y-m-d'),
                'open' => round($open, 2),
                'high' => round($high, 2),
                'low' => round($low, 2),
                'close' => round($close, 2),
                'volume' => $volume,
                'turnover' => round($close * $volume, 2),
                'change' => round($close - $basePrice, 2),
                'change_percent' => round($changePercent, 2),
            ]);

            // 更新基準價格
            $basePrice = $close;
        }
    }

    /**
     * 取得股票基準價格
     */
    private function getBasePrice(string $symbol): float
    {
        return match($symbol) {
            '2330' => 585.0,
            '2317' => 105.0,
            '2454' => 920.0,
            '2308' => 325.0,
            '2882' => 58.5,
            '2881' => 72.8,
            '2891' => 28.5,
            '0050' => 145.5,
            '0056' => 35.8,
            '006208' => 93.2,
            default => 100.0,
        };
    }
}