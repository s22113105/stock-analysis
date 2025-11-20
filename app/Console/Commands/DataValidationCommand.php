<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DataValidationCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:validate 
                            {--fix : 自動修復發現的問題}
                            {--clear : 清除測試資料}
                            {--fetch : 抓取缺失的資料}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '驗證資料完整性並可選擇自動修復';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('========================================');
        $this->info('📊 股票資料驗證工具');
        $this->info('========================================');
        $this->newLine();

        $fix = $this->option('fix');
        $clear = $this->option('clear');
        $fetch = $this->option('fetch');

        if ($fix) {
            $this->warn('⚠️  啟用自動修復模式');
            $this->newLine();
        }

        // 步驟 1: 檢查環境
        $this->checkEnvironment();

        // 步驟 2: 清除測試資料（如果指定）
        if ($clear) {
            $this->clearTestData();
        }

        // 步驟 3: 驗證資料完整性
        $issues = $this->validateData();

        // 步驟 4: 自動修復（如果指定）
        if ($fix && !empty($issues)) {
            $this->fixIssues($issues, $fetch);
        }

        // 步驟 5: 顯示最終統計
        $this->showFinalStats();

        return Command::SUCCESS;
    }

    /**
     * 檢查環境設定
     */
    protected function checkEnvironment(): void
    {
        $this->info('步驟 1/3: 檢查環境設定');
        $this->line('========================================');

        // 檢查資料庫連線
        try {
            DB::connection()->getPdo();
            $this->line('✅ 資料庫連線正常');
        } catch (\Exception $e) {
            $this->error('❌ 資料庫連線失敗: ' . $e->getMessage());
            exit(1);
        }

        // 檢查必要的資料表
        $tables = ['stocks', 'stock_prices', 'options', 'option_prices'];
        foreach ($tables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $count = DB::table($table)->count();
                $this->line("✅ 資料表 {$table} 存在 (記錄數: {$count})");
            } else {
                $this->error("❌ 資料表 {$table} 不存在");
                $this->line("   執行: php artisan migrate");
                exit(1);
            }
        }

        $this->newLine();
    }

    /**
     * 清除測試資料
     */
    protected function clearTestData(): void
    {
        $this->info('清除測試資料...');
        $this->line('========================================');

        if (!$this->confirm('確定要清除測試資料嗎？')) {
            return;
        }

        try {
            DB::beginTransaction();

            // 1. 清除測試股票 (symbol 以 TEST 開頭)
            $testStocks = Stock::where('symbol', 'like', 'TEST%')->count();
            if ($testStocks > 0) {
                Stock::where('symbol', 'like', 'TEST%')->delete();
                $this->line("  ✅ 已刪除 {$testStocks} 檔測試股票");
            }

            // 2. 清除沒有價格記錄的股票
            $emptyStocks = Stock::doesntHave('prices')->count();
            if ($emptyStocks > 0) {
                Stock::doesntHave('prices')->delete();
                $this->line("  ✅ 已刪除 {$emptyStocks} 檔沒有價格記錄的股票");
            }

            // 3. 清除異常價格資料 (價格 = 0 或 null)
            $invalidPrices = StockPrice::where('close', '<=', 0)
                ->orWhereNull('close')
                ->count();

            if ($invalidPrices > 0) {
                StockPrice::where('close', '<=', 0)
                    ->orWhereNull('close')
                    ->delete();
                
                $this->line("  ✅ 已刪除 {$invalidPrices} 筆異常價格資料");
            }

            DB::commit();
            $this->info('✅ 測試資料清除完成！');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ 清除失敗: ' . $e->getMessage());
        }

        $this->newLine();
    }

    /**
     * 驗證資料完整性
     *
     * @return array
     */
    protected function validateData(): array
    {
        $this->info('步驟 2/3: 驗證資料完整性');
        $this->line('========================================');

        $issues = [];

        // 1. 檢查股票資料
        $this->line('📈 檢查股票資料...');
        
        $totalStocks = Stock::count();
        $activeStocks = Stock::where('is_active', true)->count();
        $stocksWithPrices = Stock::has('prices')->count();
        $stocksWithoutPrices = Stock::doesntHave('prices')->count();

        $this->line("  • 總股票數: {$totalStocks}");
        $this->line("  • 啟用中: {$activeStocks}");
        $this->line("  • 有價格記錄: {$stocksWithPrices}");
        
        if ($stocksWithoutPrices > 0) {
            $this->warn("  ⚠️  沒有價格記錄: {$stocksWithoutPrices}");
            $issues['stocks_without_prices'] = $stocksWithoutPrices;
        }

        // 2. 檢查股價資料
        $this->newLine();
        $this->line('💰 檢查股價資料...');
        
        $totalPrices = StockPrice::count();
        $recentPrices = StockPrice::where('trade_date', '>=', now()->subDays(30))->count();
        $invalidPrices = StockPrice::where('close', '<=', 0)->count();
        $nullPrices = StockPrice::whereNull('close')->count();

        $this->line("  • 總價格記錄: {$totalPrices}");
        $this->line("  • 最近30天: {$recentPrices}");
        
        if ($invalidPrices > 0) {
            $this->warn("  ⚠️  異常價格 (≤0): {$invalidPrices}");
            $issues['invalid_prices'] = $invalidPrices;
        }
        
        if ($nullPrices > 0) {
            $this->warn("  ⚠️  空值價格: {$nullPrices}");
            $issues['null_prices'] = $nullPrices;
        }

        // 3. 檢查成交量資料
        $this->newLine();
        $this->line('📊 檢查成交量資料...');
        
        $zeroVolume = StockPrice::where('volume', 0)->count();
        $nullVolume = StockPrice::whereNull('volume')->count();

        if ($zeroVolume > 0) {
            $this->warn("  ⚠️  零成交量: {$zeroVolume}");
            $issues['zero_volume'] = $zeroVolume;
        }
        
        if ($nullVolume > 0) {
            $this->warn("  ⚠️  空值成交量: {$nullVolume}");
            $issues['null_volume'] = $nullVolume;
        }

        // 4. 檢查資料新鮮度
        $this->newLine();
        $this->line('📅 檢查資料新鮮度...');
        
        if ($totalPrices > 0) {
            $latestDate = StockPrice::max('trade_date');
            $daysSinceUpdate = Carbon::parse($latestDate)->diffInDays(now());
            
            $this->line("  • 最新資料日期: {$latestDate}");
            $this->line("  • 距今天數: {$daysSinceUpdate} 天");
            
            if ($daysSinceUpdate > 7) {
                $this->warn("  ⚠️  資料已過期超過一週！");
                $issues['stale_data'] = $daysSinceUpdate;
            }
        } else {
            $this->error('  ❌ 沒有任何價格資料！');
            $issues['no_data'] = true;
        }

        // 5. 檢查重複資料
        $this->newLine();
        $this->line('🔍 檢查重複資料...');
        
        $duplicates = DB::table('stock_prices')
            ->select('stock_id', 'trade_date', DB::raw('count(*) as count'))
            ->groupBy('stock_id', 'trade_date')
            ->having('count', '>', 1)
            ->count();
        
        if ($duplicates > 0) {
            $this->warn("  ⚠️  發現 {$duplicates} 組重複資料");
            $issues['duplicates'] = $duplicates;
        } else {
            $this->line("  ✅ 沒有重複資料");
        }

        $this->newLine();

        if (empty($issues)) {
            $this->info('✅ 資料驗證通過，沒有發現問題！');
        } else {
            $this->warn('⚠️  發現 ' . count($issues) . ' 個問題');
            if (!$this->option('fix')) {
                $this->line('   使用 --fix 參數自動修復問題');
            }
        }

        return $issues;
    }

    /**
     * 修復發現的問題
     *
     * @param array $issues
     * @param bool $fetch
     */
    protected function fixIssues(array $issues, bool $fetch): void
    {
        $this->newLine();
        $this->info('步驟 3/3: 自動修復問題');
        $this->line('========================================');

        try {
            DB::beginTransaction();

            // 修復沒有價格的股票
            if (isset($issues['stocks_without_prices'])) {
                $this->line('🔧 修復沒有價格記錄的股票...');
                
                if ($fetch) {
                    // 嘗試抓取資料
                    $stocks = Stock::doesntHave('prices')->limit(5)->get();
                    foreach ($stocks as $stock) {
                        $this->line("   抓取 {$stock->symbol} 的資料...");
                        $this->call('crawler:stocks', [
                            '--symbol' => $stock->symbol,
                            '--date' => now()->subDays(3)->format('Y-m-d'),
                            '--sync' => true
                        ]);
                    }
                } else {
                    // 刪除沒有價格的股票
                    Stock::doesntHave('prices')->delete();
                    $this->line("   ✅ 已刪除 {$issues['stocks_without_prices']} 檔股票");
                }
            }

            // 修復異常價格
            if (isset($issues['invalid_prices']) || isset($issues['null_prices'])) {
                $this->line('🔧 修復異常價格資料...');
                $deleted = StockPrice::where('close', '<=', 0)
                    ->orWhereNull('close')
                    ->delete();
                $this->line("   ✅ 已刪除 {$deleted} 筆異常價格");
            }

            // 修復異常成交量
            if (isset($issues['zero_volume']) || isset($issues['null_volume'])) {
                $this->line('🔧 修復異常成交量資料...');
                $deleted = StockPrice::where('volume', '<=', 0)
                    ->orWhereNull('volume')
                    ->delete();
                $this->line("   ✅ 已刪除 {$deleted} 筆異常成交量");
            }

            // 修復重複資料
            if (isset($issues['duplicates'])) {
                $this->line('🔧 修復重複資料...');
                
                $duplicates = DB::table('stock_prices')
                    ->select('stock_id', 'trade_date', DB::raw('min(id) as keep_id'))
                    ->groupBy('stock_id', 'trade_date')
                    ->having(DB::raw('count(*)'), '>', 1)
                    ->get();
                
                foreach ($duplicates as $duplicate) {
                    StockPrice::where('stock_id', $duplicate->stock_id)
                        ->where('trade_date', $duplicate->trade_date)
                        ->where('id', '!=', $duplicate->keep_id)
                        ->delete();
                }
                
                $this->line("   ✅ 已修復 {$issues['duplicates']} 組重複資料");
            }

            // 更新過期資料
            if (isset($issues['stale_data']) || isset($issues['no_data'])) {
                $this->line('🔧 更新過期資料...');
                
                if ($fetch) {
                    $this->line("   正在抓取最新資料...");
                    $this->call('crawler:stocks', [
                        '--date' => now()->subDays(3)->format('Y-m-d'),
                        '--sync' => true
                    ]);
                    $this->line("   ✅ 已更新資料");
                } else {
                    $this->warn("   ⚠️  請手動執行: php artisan crawler:stocks");
                }
            }

            DB::commit();
            $this->newLine();
            $this->info('✅ 問題修復完成！');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ 修復失敗: ' . $e->getMessage());
        }
    }

    /**
     * 顯示最終統計
     */
    protected function showFinalStats(): void
    {
        $this->newLine();
        $this->info('📊 最終統計');
        $this->line('========================================');

        $stats = [
            '股票總數' => Stock::count(),
            '啟用股票' => Stock::where('is_active', true)->count(),
            '股價記錄' => StockPrice::count(),
            '選擇權合約' => Option::count(),
            '選擇權價格' => OptionPrice::count(),
        ];

        foreach ($stats as $label => $value) {
            $this->line("• {$label}: {$value}");
        }

        // 顯示日期範圍
        if (StockPrice::count() > 0) {
            $minDate = StockPrice::min('trade_date');
            $maxDate = StockPrice::max('trade_date');
            $this->line("• 資料期間: {$minDate} ~ {$maxDate}");
        }

        // 顯示最近 7 天資料量
        $recentCount = StockPrice::where('trade_date', '>=', now()->subDays(7))->count();
        $this->line("• 最近7天資料: {$recentCount} 筆");

        $this->newLine();
    }
}