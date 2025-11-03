<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use Carbon\Carbon;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            StockSeeder::class,
            OptionSeeder::class,
        ]);
    }
}

class StockSeeder extends Seeder
{
    public function run(): void
    {
        $stocks = [
            ['symbol' => '2330', 'name' => '台積電', 'market' => 'TSE', 'industry' => '半導體'],
            ['symbol' => '2317', 'name' => '鴻海', 'market' => 'TSE', 'industry' => '電子代工'],
            ['symbol' => '2454', 'name' => '聯發科', 'market' => 'TSE', 'industry' => 'IC設計'],
            ['symbol' => '2412', 'name' => '中華電', 'market' => 'TSE', 'industry' => '電信'],
            ['symbol' => '2881', 'name' => '富邦金', 'market' => 'TSE', 'industry' => '金融'],
            ['symbol' => '2882', 'name' => '國泰金', 'market' => 'TSE', 'industry' => '金融'],
            ['symbol' => '2884', 'name' => '玉山金', 'market' => 'TSE', 'industry' => '金融'],
            ['symbol' => '2891', 'name' => '中信金', 'market' => 'TSE', 'industry' => '金融'],
            ['symbol' => '2303', 'name' => '聯電', 'market' => 'TSE', 'industry' => '半導體'],
            ['symbol' => '1301', 'name' => '台塑', 'market' => 'TSE', 'industry' => '塑化'],
        ];

        foreach ($stocks as $stockData) {
            $stock = Stock::create($stockData);
            $this->generateStockPrices($stock);
        }
    }

    private function generateStockPrices(Stock $stock)
    {
        $startDate = Carbon::now()->subMonths(6);
        $endDate = Carbon::now();
        $currentPrice = $this->getInitialPrice($stock->symbol);

        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            // 跳過週末
            if ($date->isWeekend()) {
                continue;
            }

            // 產生隨機價格變動 (±3%)
            $changePercent = (mt_rand(-300, 300) / 10000);
            $change = $currentPrice * $changePercent;
            $newPrice = $currentPrice + $change;

            // 產生 OHLC 資料
            $open = $newPrice * (1 + mt_rand(-100, 100) / 10000);
            $high = max($open, $newPrice) * (1 + mt_rand(0, 200) / 10000);
            $low = min($open, $newPrice) * (1 - mt_rand(0, 200) / 10000);
            $close = $newPrice;

            StockPrice::create([
                'stock_id' => $stock->id,
                'trade_date' => $date->format('Y-m-d'),
                'open_price' => round($open, 2),
                'high_price' => round($high, 2),
                'low_price' => round($low, 2),
                'close_price' => round($close, 2),
                'volume' => mt_rand(1000000, 50000000),
                'change' => round($change, 2),
                'change_percent' => round($changePercent * 100, 2),
            ]);

            $currentPrice = $newPrice;
        }
    }

    private function getInitialPrice($symbol)
    {
        $prices = [
            '2330' => 580,  // 台積電
            '2317' => 105,  // 鴻海
            '2454' => 700,  // 聯發科
            '2412' => 105,  // 中華電
            '2881' => 65,   // 富邦金
            '2882' => 60,   // 國泰金
            '2884' => 30,   // 玉山金
            '2891' => 25,   // 中信金
            '2303' => 45,   // 聯電
            '1301' => 95,   // 台塑
        ];

        return $prices[$symbol] ?? 100;
    }
}

class OptionSeeder extends Seeder
{
    public function run(): void
    {
        $stocks = Stock::whereIn('symbol', ['2330', '2317', '2454'])->get();

        foreach ($stocks as $stock) {
            $this->generateOptions($stock);
        }
    }

    private function generateOptions(Stock $stock)
    {
        $currentPrice = $stock->latestPrice->close_price ?? 100;
        $expiryDates = [
            Carbon::now()->addWeeks(1),
            Carbon::now()->addWeeks(2),
            Carbon::now()->addMonth(),
            Carbon::now()->addMonths(2),
            Carbon::now()->addMonths(3),
        ];

        foreach ($expiryDates as $expiryDate) {
            // 產生不同履約價的選擇權
            $strikes = $this->generateStrikes($currentPrice);

            foreach ($strikes as $strike) {
                // Call Option
                $callOption = Option::create([
                    'stock_id' => $stock->id,
                    'option_code' => $stock->symbol . $expiryDate->format('ymd') . 'C' . $strike,
                    'option_type' => 'call',
                    'strike_price' => $strike,
                    'expiry_date' => $expiryDate->format('Y-m-d'),
                    'is_active' => true,
                ]);

                $this->generateOptionPrices($callOption, $stock);

                // Put Option
                $putOption = Option::create([
                    'stock_id' => $stock->id,
                    'option_code' => $stock->symbol . $expiryDate->format('ymd') . 'P' . $strike,
                    'option_type' => 'put',
                    'strike_price' => $strike,
                    'expiry_date' => $expiryDate->format('Y-m-d'),
                    'is_active' => true,
                ]);

                $this->generateOptionPrices($putOption, $stock);
            }
        }
    }

    private function generateStrikes($currentPrice)
    {
        $strikes = [];
        $step = $this->getStrikeStep($currentPrice);

        // 產生 ITM, ATM, OTM 履約價
        for ($i = -5; $i <= 5; $i++) {
            $strike = round($currentPrice + ($i * $step), 0);
            if ($strike > 0) {
                $strikes[] = $strike;
            }
        }

        return $strikes;
    }

    private function getStrikeStep($price)
    {
        if ($price < 50) return 1;
        if ($price < 100) return 2.5;
        if ($price < 200) return 5;
        if ($price < 500) return 10;
        return 25;
    }

    private function generateOptionPrices(Option $option, Stock $stock)
    {
        $startDate = Carbon::now()->subMonth();
        $endDate = Carbon::now();

        for ($date = $startDate->copy(); $date <= $endDate; $date->addDay()) {
            // 跳過週末
            if ($date->isWeekend()) {
                continue;
            }

            // 取得當日股價
            $stockPrice = StockPrice::where('stock_id', $stock->id)
                ->where('trade_date', $date->format('Y-m-d'))
                ->first();

            if (!$stockPrice) {
                continue;
            }

            // 計算選擇權理論價格 (簡化版)
            $timeToExpiry = $date->diffInDays(Carbon::parse($option->expiry_date)) / 365;
            $moneyness = $stockPrice->close_price / $option->strike_price;

            // 基礎價格計算
            if ($option->option_type == 'call') {
                $intrinsicValue = max(0, $stockPrice->close_price - $option->strike_price);
            } else {
                $intrinsicValue = max(0, $option->strike_price - $stockPrice->close_price);
            }

            // 時間價值 (簡化計算)
            $timeValue = sqrt($timeToExpiry) * $stockPrice->close_price * 0.1 * mt_rand(50, 150) / 100;
            $optionPrice = max(0.01, $intrinsicValue + $timeValue);

            // 產生買賣價差
            $spread = $optionPrice * 0.05;
            $bidPrice = max(0.01, $optionPrice - $spread);
            $askPrice = $optionPrice + $spread;

            // 隱含波動率 (隨機產生)
            $impliedVolatility = mt_rand(15, 45) / 100;

            OptionPrice::create([
                'option_id' => $option->id,
                'trade_date' => $date->format('Y-m-d'),
                'open_price' => round($optionPrice * (1 + mt_rand(-5, 5) / 100), 2),
                'high_price' => round($optionPrice * (1 + mt_rand(0, 10) / 100), 2),
                'low_price' => round($optionPrice * (1 - mt_rand(0, 10) / 100), 2),
                'close_price' => round($optionPrice, 2),
                'settlement_price' => round($optionPrice, 2),
                'volume' => mt_rand(0, 10000),
                'open_interest' => mt_rand(100, 50000),
                'bid_price' => round($bidPrice, 2),
                'ask_price' => round($askPrice, 2),
                'implied_volatility' => $impliedVolatility,
            ]);
        }
    }
}
