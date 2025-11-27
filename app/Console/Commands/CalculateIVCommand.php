<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

/**
 * 計算選擇權隱含波動率 (IV) 指令
 * 
 * 使用 Black-Scholes 模型從選擇權價格反推 IV
 * 
 * 使用方式:
 * php artisan calc:iv              # 計算所有缺少 IV 的選擇權
 * php artisan calc:iv --date=2025-11-25  # 指定日期
 * php artisan calc:iv --limit=100  # 限制處理筆數
 */
class CalculateIVCommand extends Command
{
    protected $signature = 'calc:iv
                            {--date= : 指定日期}
                            {--limit=0 : 限制處理筆數}
                            {--force : 強制重算所有 IV}
                            {--spot= : 手動指定標的價格}';

    protected $description = '計算選擇權隱含波動率 (IV) - 使用 Black-Scholes 模型';

    /**
     * 無風險利率 (年化)
     * 可使用台灣央行利率或國庫券利率
     */
    protected $riskFreeRate = 0.0175; // 1.75%

    /**
     * IV 計算的收斂容差
     */
    protected $tolerance = 0.0001;

    /**
     * 最大迭代次數
     */
    protected $maxIterations = 100;

    public function handle()
    {
        $this->info('');
        $this->info('╔════════════════════════════════════════╗');
        $this->info('║      隱含波動率 (IV) 計算工具          ║');
        $this->info('╠════════════════════════════════════════╣');
        $this->info('║  方法: Black-Scholes 模型反推          ║');
        $this->info('║  標的: TXO (台指選擇權)                ║');
        $this->info('╚════════════════════════════════════════╝');
        $this->info('');

        $date = $this->option('date');
        $limit = (int) $this->option('limit');
        $force = $this->option('force');
        $manualSpot = $this->option('spot');

        // 1. 取得標的價格 (台灣加權指數)
        $this->info('📈 取得台灣加權指數...');
        
        $spotPrice = $manualSpot ? floatval($manualSpot) : $this->getTaiwanIndex();
        
        if (!$spotPrice || $spotPrice <= 0) {
            $this->error('❌ 無法取得加權指數價格');
            $this->warn('請使用 --spot=價格 手動指定');
            $this->line('範例: php artisan calc:iv --spot=22500');
            return Command::FAILURE;
        }

        $this->info("✅ 加權指數: {$spotPrice} 點");
        $this->info("📊 無風險利率: " . ($this->riskFreeRate * 100) . "%");
        $this->info('');

        // 2. 查詢需要計算 IV 的選擇權價格
        $this->info('🔍 查詢選擇權資料...');

        // 先檢查資料表有哪些欄位
        $columns = Schema::getColumnListing('option_prices');
        $hasSettlement = in_array('settlement', $columns);
        $hasSettlementPrice = in_array('settlement_price', $columns);
        
        $this->line("   可用價格欄位: close" . ($hasSettlement ? ', settlement' : '') . ($hasSettlementPrice ? ', settlement_price' : ''));

        $query = OptionPrice::with('option')
            ->whereHas('option', function ($q) {
                $q->where('underlying', 'TXO')
                  ->where('expiry_date', '>=', now()->format('Y-m-d'));
            });

        // 日期篩選
        if ($date) {
            $query->where('trade_date', $date);
            $this->info("   篩選日期: {$date}");
        } else {
            // 預設取最新日期
            $latestDate = OptionPrice::whereHas('option', function ($q) {
                $q->where('underlying', 'TXO');
            })->max('trade_date');
            
            if ($latestDate) {
                $query->where('trade_date', $latestDate);
                $this->info("   最新日期: {$latestDate}");
            }
        }

        // 是否只計算缺少 IV 的
        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('implied_volatility')
                  ->orWhere('implied_volatility', 0);
            });
            $this->info('   模式: 只計算缺少 IV 的資料');
        } else {
            $this->warn('   模式: 重算所有 IV (--force)');
        }

        // 限制筆數
        if ($limit > 0) {
            $query->limit($limit);
            $this->info("   限制: {$limit} 筆");
        }

        $optionPrices = $query->get();

        if ($optionPrices->isEmpty()) {
            $this->warn('⚠️  沒有需要計算的選擇權資料');
            return Command::SUCCESS;
        }

        $this->info("✅ 找到 {$optionPrices->count()} 筆需計算");
        $this->info('');

        // 3. 計算 IV
        $this->info('⚙️  計算隱含波動率中...');
        
        $progressBar = $this->output->createProgressBar($optionPrices->count());
        $progressBar->start();

        $stats = [
            'calculated' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_iv' => 0,
        ];

        DB::beginTransaction();

        try {
            foreach ($optionPrices as $optionPrice) {
                $option = $optionPrice->option;
                
                if (!$option) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                // 取得選擇權價格 (嘗試多個欄位)
                $optPrice = 0;
                
                // 優先順序: close -> settlement -> settlement_price
                if (isset($optionPrice->close) && floatval($optionPrice->close) > 0) {
                    $optPrice = floatval($optionPrice->close);
                } elseif ($hasSettlement && isset($optionPrice->settlement) && floatval($optionPrice->settlement) > 0) {
                    $optPrice = floatval($optionPrice->settlement);
                } elseif ($hasSettlementPrice && isset($optionPrice->settlement_price) && floatval($optionPrice->settlement_price) > 0) {
                    $optPrice = floatval($optionPrice->settlement_price);
                }

                if ($optPrice <= 0) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                // 計算到期時間 (年)
                $expiryDate = Carbon::parse($option->expiry_date);
                $tradeDate = Carbon::parse($optionPrice->trade_date);
                $timeToExpiry = $tradeDate->diffInDays($expiryDate) / 365;

                if ($timeToExpiry <= 0) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                // 履約價
                $strikePrice = $option->strike_price;

                // 選擇權類型
                $optionType = strtolower($option->option_type) === 'call' ? 'call' : 'put';

                // 計算 IV
                $iv = $this->calculateImpliedVolatility(
                    $spotPrice,
                    $strikePrice,
                    $timeToExpiry,
                    $this->riskFreeRate,
                    $optPrice,
                    $optionType
                );

                if ($iv !== null && $iv > 0 && $iv < 5) { // IV 合理範圍 0-500%
                    $optionPrice->implied_volatility = $iv;
                    $optionPrice->save();
                    
                    $stats['calculated']++;
                    $stats['total_iv'] += $iv;
                } else {
                    $stats['failed']++;
                }

                $progressBar->advance();
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("計算失敗: " . $e->getMessage());
            Log::error('IV 計算錯誤', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }

        $progressBar->finish();
        $this->info('');
        $this->info('');

        // 4. 顯示結果
        $avgIV = $stats['calculated'] > 0 
            ? round(($stats['total_iv'] / $stats['calculated']) * 100, 2) 
            : 0;

        $this->info('╔════════════════════════════════════════╗');
        $this->info('║             計算結果                   ║');
        $this->info('╠════════════════════════════════════════╣');
        $this->info("║  成功計算: {$stats['calculated']} 筆");
        $this->info("║  計算失敗: {$stats['failed']} 筆");
        $this->info("║  跳過: {$stats['skipped']} 筆");
        $this->info("║  平均 IV: {$avgIV}%");
        $this->info('╚════════════════════════════════════════╝');

        // 顯示 IV 分布
        if ($stats['calculated'] > 0) {
            $this->info('');
            $this->info('📊 IV 分布統計:');
            
            $ivStats = OptionPrice::whereHas('option', function ($q) {
                    $q->where('underlying', 'TXO')
                      ->where('expiry_date', '>=', now()->format('Y-m-d'));
                })
                ->whereNotNull('implied_volatility')
                ->where('implied_volatility', '>', 0)
                ->selectRaw('
                    MIN(implied_volatility) as min_iv,
                    MAX(implied_volatility) as max_iv,
                    AVG(implied_volatility) as avg_iv,
                    COUNT(*) as count
                ')
                ->first();

            if ($ivStats) {
                $this->line("   最小 IV: " . round($ivStats->min_iv * 100, 2) . "%");
                $this->line("   最大 IV: " . round($ivStats->max_iv * 100, 2) . "%");
                $this->line("   平均 IV: " . round($ivStats->avg_iv * 100, 2) . "%");
                $this->line("   有 IV 資料: {$ivStats->count} 筆");
            }
        }

        Log::info('IV 計算完成', $stats);

        return Command::SUCCESS;
    }

    /**
     * 計算隱含波動率 (使用 Newton-Raphson 方法)
     */
    protected function calculateImpliedVolatility(
        float $spot,
        float $strike,
        float $time,
        float $rate,
        float $optionPrice,
        string $optionType
    ): ?float {
        // 初始猜測值
        $sigma = 0.3; // 30%

        for ($i = 0; $i < $this->maxIterations; $i++) {
            // 計算 Black-Scholes 價格
            $bsPrice = $this->blackScholesPrice($spot, $strike, $time, $rate, $sigma, $optionType);
            
            // 計算 Vega
            $vega = $this->blackScholesVega($spot, $strike, $time, $rate, $sigma);

            if ($vega < 1e-10) {
                // Vega 太小，無法繼續迭代
                break;
            }

            // Newton-Raphson 更新
            $diff = $bsPrice - $optionPrice;
            $sigma = $sigma - $diff / $vega;

            // 檢查收斂
            if (abs($diff) < $this->tolerance) {
                return $sigma;
            }

            // 確保 sigma 在合理範圍內
            if ($sigma <= 0) {
                $sigma = 0.01;
            }
            if ($sigma > 5) {
                $sigma = 5;
            }
        }

        // 如果 Newton-Raphson 失敗，嘗試二分法
        return $this->bisectionMethod($spot, $strike, $time, $rate, $optionPrice, $optionType);
    }

    /**
     * 二分法計算 IV (備用方法)
     */
    protected function bisectionMethod(
        float $spot,
        float $strike,
        float $time,
        float $rate,
        float $optionPrice,
        string $optionType
    ): ?float {
        $low = 0.001;
        $high = 5.0;

        for ($i = 0; $i < $this->maxIterations; $i++) {
            $mid = ($low + $high) / 2;
            $bsPrice = $this->blackScholesPrice($spot, $strike, $time, $rate, $mid, $optionType);

            if (abs($bsPrice - $optionPrice) < $this->tolerance) {
                return $mid;
            }

            if ($bsPrice > $optionPrice) {
                $high = $mid;
            } else {
                $low = $mid;
            }
        }

        // 返回最後的估計值
        $mid = ($low + $high) / 2;
        return ($mid > 0.01 && $mid < 3) ? $mid : null;
    }

    /**
     * Black-Scholes 選擇權定價
     */
    protected function blackScholesPrice(
        float $spot,
        float $strike,
        float $time,
        float $rate,
        float $sigma,
        string $optionType
    ): float {
        if ($sigma <= 0 || $time <= 0) {
            return 0;
        }

        $d1 = (log($spot / $strike) + ($rate + 0.5 * $sigma * $sigma) * $time) / ($sigma * sqrt($time));
        $d2 = $d1 - $sigma * sqrt($time);

        if ($optionType === 'call') {
            return $spot * $this->normalCDF($d1) - $strike * exp(-$rate * $time) * $this->normalCDF($d2);
        } else {
            return $strike * exp(-$rate * $time) * $this->normalCDF(-$d2) - $spot * $this->normalCDF(-$d1);
        }
    }

    /**
     * Black-Scholes Vega
     */
    protected function blackScholesVega(
        float $spot,
        float $strike,
        float $time,
        float $rate,
        float $sigma
    ): float {
        if ($sigma <= 0 || $time <= 0) {
            return 0;
        }

        $d1 = (log($spot / $strike) + ($rate + 0.5 * $sigma * $sigma) * $time) / ($sigma * sqrt($time));
        
        return $spot * sqrt($time) * $this->normalPDF($d1);
    }

    /**
     * 標準常態分佈 CDF
     */
    protected function normalCDF(float $x): float
    {
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        $sign = $x < 0 ? -1 : 1;
        $x = abs($x) / sqrt(2);

        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return 0.5 * (1.0 + $sign * $y);
    }

    /**
     * 標準常態分佈 PDF
     */
    protected function normalPDF(float $x): float
    {
        return exp(-0.5 * $x * $x) / sqrt(2 * M_PI);
    }

    /**
     * 取得台灣加權指數
     */
    protected function getTaiwanIndex(): ?float
    {
        // 快取 30 分鐘
        $cacheKey = 'taiwan_weighted_index';
        
        $cached = Cache::get($cacheKey);
        if ($cached) {
            return $cached;
        }

        try {
            // 方法1: 從 TWSE API 取得
            $response = Http::timeout(10)
                ->get('https://openapi.twse.com.tw/v1/exchangeReport/FMTQIK');

            if ($response->successful()) {
                $data = $response->json();
                if (!empty($data) && isset($data[0]['收盤指數'])) {
                    $index = floatval(str_replace(',', '', $data[0]['收盤指數']));
                    if ($index > 0) {
                        Cache::put($cacheKey, $index, now()->addMinutes(30));
                        return $index;
                    }
                }
            }

            // 方法2: 從期交所取得台指期貨價格
            $response = Http::timeout(10)
                ->get('https://openapi.taifex.com.tw/v1/DailyMarketReportFut');

            if ($response->successful()) {
                $data = $response->json();
                foreach ($data as $item) {
                    if (isset($item['Contract']) && str_contains($item['Contract'], 'TX')) {
                        $price = floatval($item['SettlementPrice'] ?? $item['Close'] ?? 0);
                        if ($price > 10000) { // 合理範圍
                            Cache::put($cacheKey, $price, now()->addMinutes(30));
                            return $price;
                        }
                    }
                }
            }

            // 方法3: 使用預設值 (需要使用者確認)
            $this->warn('⚠️  無法自動取得加權指數');
            
            return null;

        } catch (\Exception $e) {
            Log::warning('取得加權指數失敗', ['error' => $e->getMessage()]);
            return null;
        }
    }
}