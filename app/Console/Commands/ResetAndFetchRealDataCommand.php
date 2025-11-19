<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use App\Models\Prediction;
use App\Models\BacktestResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

/**
 * 清除測試資料並重新抓取真實資料
 * 
 * 用途:
 * 1. 清除所有 seeder 產生的測試資料
 * 2. 保留使用者資料
 * 3. 重新抓取真實的台股/選擇權資料
 */
class ResetAndFetchRealDataCommand extends Command
{
    /**
     * 命令簽名
     *
     * @var string
     */
    protected $signature = 'data:reset-and-fetch
                            {--skip-confirm : 跳過確認提示}
                            {--keep-users : 保留使用者資料}
                            {--fetch-stocks : 抓取股票資料}
                            {--fetch-options : 抓取選擇權資料}
                            {--stocks=* : 指定要抓取的股票代碼}
                            {--days=7 : 抓取最近幾天的資料}';

    /**
     * 命令說明
     *
     * @var string
     */
    protected $description = '清除測試資料並重新抓取真實資料';

    /**
     * 執行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('🔄 清除測試資料並重新抓取真實資料');
        $this->info('========================================');
        $this->newLine();

        // 確認操作
        if (!$this->option('skip-confirm')) {
            $this->warn('⚠️  此操作將清除所有股票和選擇權資料！');
            $this->warn('⚠️  使用者資料將會' . ($this->option('keep-users') ? '保留' : '清除') . '！');
            $this->newLine();

            if (!$this->confirm('確定要繼續嗎？', false)) {
                $this->info('❌ 已取消操作');
                return self::SUCCESS;
            }
        }

        // 步驟 1: 清除資料
        $this->newLine();
        $this->info('📋 步驟 1/3: 清除現有資料...');
        $this->cleanData();

        // 步驟 2: 抓取股票資料
        if ($this->option('fetch-stocks')) {
            $this->newLine();
            $this->info('📋 步驟 2/3: 抓取股票資料...');
            $this->fetchStockData();
        }

        // 步驟 3: 抓取選擇權資料
        if ($this->option('fetch-options')) {
            $this->newLine();
            $this->info('📋 步驟 3/3: 抓取選擇權資料...');
            $this->fetchOptionData();
        }

        // 完成
        $this->newLine();
        $this->info('========================================');
        $this->info('✅ 所有操作完成！');
        $this->info('========================================');
        $this->newLine();

        // 顯示統計
        $this->displayStatistics();

        return self::SUCCESS;
    }

    /**
     * 清除資料
     *
     * @return void
     */
    protected function cleanData(): void
    {
        try {
            // 禁用外鍵檢查
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            // 清除預測資料
            $this->line('🗑️  清除預測資料...');
            $predictionCount = Prediction::count();
            if ($predictionCount > 0) {
                DB::table('predictions')->truncate();
                $this->line("  ✅ 已清除 {$predictionCount} 筆預測資料");
            } else {
                $this->line("  ✅ 沒有預測資料需要清除");
            }

            // 清除回測結果
            $this->line('🗑️  清除回測結果...');
            $backtestCount = BacktestResult::count();
            if ($backtestCount > 0) {
                DB::table('backtest_results')->truncate();
                $this->line("  ✅ 已清除 {$backtestCount} 筆回測結果");
            } else {
                $this->line("  ✅ 沒有回測結果需要清除");
            }

            // 清除選擇權價格
            $this->line('🗑️  清除選擇權價格...');
            $optionPriceCount = OptionPrice::count();
            if ($optionPriceCount > 0) {
                DB::table('option_prices')->truncate();
                $this->line("  ✅ 已清除 {$optionPriceCount} 筆選擇權價格");
            } else {
                $this->line("  ✅ 沒有選擇權價格需要清除");
            }

            // 清除選擇權合約
            $this->line('🗑️  清除選擇權合約...');
            $optionCount = Option::count();
            if ($optionCount > 0) {
                DB::table('options')->truncate();
                $this->line("  ✅ 已清除 {$optionCount} 個選擇權合約");
            } else {
                $this->line("  ✅ 沒有選擇權合約需要清除");
            }

            // 清除股價資料
            $this->line('🗑️  清除股價資料...');
            $priceCount = StockPrice::count();
            if ($priceCount > 0) {
                DB::table('stock_prices')->truncate();
                $this->line("  ✅ 已清除 {$priceCount} 筆股價資料");
            } else {
                $this->line("  ✅ 沒有股價資料需要清除");
            }

            // 清除股票資料
            $this->line('🗑️  清除股票資料...');
            $stockCount = Stock::count();
            if ($stockCount > 0) {
                DB::table('stocks')->truncate();
                $this->line("  ✅ 已清除 {$stockCount} 檔股票資料");
            } else {
                $this->line("  ✅ 沒有股票資料需要清除");
            }

            // 清除使用者資料 (選擇性)
            if (!$this->option('keep-users')) {
                $this->line('🗑️  清除使用者資料...');
                $userCount = DB::table('users')->count();
                if ($userCount > 0) {
                    DB::table('users')->truncate();
                    $this->line("  ✅ 已清除 {$userCount} 位使用者");
                    $this->warn("  ⚠️  請執行 php artisan db:seed --class=UserSeeder 重建使用者");
                }
            }

            // 重新啟用外鍵檢查
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->newLine();
            $this->info('✅ 資料清除完成！');

        } catch (\Exception $e) {
            // 確保外鍵檢查被重新啟用
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            
            $this->error('❌ 清除失敗: ' . $e->getMessage());
            
            Log::error('資料清除失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 抓取股票資料
     *
     * @return void
     */
    protected function fetchStockData(): void
    {
        $days = (int) $this->option('days');
        $symbols = $this->option('stocks');

        $this->line('📊 開始抓取股票資料...');
        $this->line("  • 抓取天數: {$days} 天");
        
        if (!empty($symbols)) {
            $this->line("  • 指定股票: " . implode(', ', $symbols));
        } else {
            $this->line("  • 抓取範圍: 全部上市股票");
        }

        $this->newLine();

        // 計算日期範圍
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            // 跳過週末
            if ($date->isWeekend()) {
                continue;
            }
            $dates[] = $date->format('Y-m-d');
        }

        $this->line("  • 交易日數量: " . count($dates) . " 天");
        $this->newLine();

        $bar = $this->output->createProgressBar(count($dates));
        $bar->start();

        $successCount = 0;
        $failCount = 0;

        foreach ($dates as $date) {
            try {
                if (!empty($symbols)) {
                    // 抓取指定股票
                    foreach ($symbols as $symbol) {
                        $exitCode = Artisan::call('crawler:stocks', [
                            '--symbol' => $symbol,
                            '--date' => $date,
                            '--sync' => true
                        ]);

                        if ($exitCode === 0) {
                            $successCount++;
                        } else {
                            $failCount++;
                        }
                    }
                } else {
                    // 抓取所有股票
                    $exitCode = Artisan::call('crawler:stocks', [
                        '--date' => $date,
                        '--sync' => true
                    ]);

                    if ($exitCode === 0) {
                        $successCount++;
                    } else {
                        $failCount++;
                    }
                }

                $bar->advance();

            } catch (\Exception $e) {
                $failCount++;
                $bar->advance();
                
                Log::error('抓取股票資料失敗', [
                    'date' => $date,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ 股票資料抓取完成！");
        $this->line("  • 成功: {$successCount}");
        if ($failCount > 0) {
            $this->warn("  • 失敗: {$failCount}");
        }
    }

    /**
     * 抓取選擇權資料
     *
     * @return void
     */
    protected function fetchOptionData(): void
    {
        $days = (int) $this->option('days');

        $this->line('📊 開始抓取選擇權資料...');
        $this->line("  • 抓取天數: {$days} 天");
        $this->newLine();

        // 計算日期範圍
        $dates = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i);
            // 跳過週末
            if ($date->isWeekend()) {
                continue;
            }
            $dates[] = $date->format('Y-m-d');
        }

        $bar = $this->output->createProgressBar(count($dates));
        $bar->start();

        $successCount = 0;
        $failCount = 0;

        foreach ($dates as $date) {
            try {
                $exitCode = Artisan::call('crawler:options-api', [
                    '--date' => $date
                ]);

                if ($exitCode === 0) {
                    $successCount++;
                } else {
                    $failCount++;
                }

                $bar->advance();

            } catch (\Exception $e) {
                $failCount++;
                $bar->advance();
                
                Log::error('抓取選擇權資料失敗', [
                    'date' => $date,
                    'error' => $e->getMessage()
                ]);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("✅ 選擇權資料抓取完成！");
        $this->line("  • 成功: {$successCount}");
        if ($failCount > 0) {
            $this->warn("  • 失敗: {$failCount}");
        }
    }

    /**
     * 顯示統計資訊
     *
     * @return void
     */
    protected function displayStatistics(): void
    {
        $this->info('📊 目前資料統計:');
        $this->newLine();

        $stats = [
            ['股票數量', Stock::count()],
            ['股價記錄', StockPrice::count()],
            ['選擇權合約', Option::count()],
            ['選擇權價格', OptionPrice::count()],
            ['預測記錄', Prediction::count()],
            ['回測結果', BacktestResult::count()],
        ];

        $this->table(['項目', '數量'], $stats);

        // 顯示最新資料日期
        $this->newLine();
        $latestStockPrice = StockPrice::orderBy('trade_date', 'desc')->first();
        if ($latestStockPrice) {
            $this->info("📅 最新股價資料: {$latestStockPrice->trade_date}");
        }

        $latestOptionPrice = OptionPrice::orderBy('trade_date', 'desc')->first();
        if ($latestOptionPrice) {
            $this->info("📅 最新選擇權資料: {$latestOptionPrice->trade_date}");
        }
    }
}