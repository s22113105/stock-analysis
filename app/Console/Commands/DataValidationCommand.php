<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 資料驗證與清理命令
 * 
 * 用途:
 * 1. 驗證資料完整性
 * 2. 清除測試/假資料
 * 3. 產生資料統計報告
 */
class DataValidationCommand extends Command
{
    /**
     * 命令簽名
     *
     * @var string
     */
    protected $signature = 'data:validate
                            {--clean-test : 清除測試資料}
                            {--report : 產生詳細報告}
                            {--fix : 自動修復問題}';

    /**
     * 命令說明
     *
     * @var string
     */
    protected $description = '驗證資料完整性並清理測試資料';

    /**
     * 執行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('========================================');
        $this->info('📊 資料驗證與清理工具');
        $this->info('========================================');
        $this->newLine();

        // 選項 1: 清除測試資料
        if ($this->option('clean-test')) {
            $this->cleanTestData();
        }

        // 選項 2: 驗證資料
        $this->validateData();

        // 選項 3: 產生報告
        if ($this->option('report')) {
            $this->generateReport();
        }

        // 選項 4: 自動修復
        if ($this->option('fix')) {
            $this->fixIssues();
        }

        $this->newLine();
        $this->info('✅ 驗證完成！');

        return self::SUCCESS;
    }

    /**
     * 清除測試資料
     *
     * @return void
     */
    protected function cleanTestData(): void
    {
        $this->info('🗑️  清除測試資料...');
        $this->newLine();

        if (!$this->confirm('確定要清除測試資料嗎？此操作無法復原！', false)) {
            $this->warn('❌ 已取消清除操作');
            return;
        }

        DB::beginTransaction();

        try {
            $totalDeleted = 0;

            // 1. 清除異常價格資料 (價格 <= 0 或為 null)
            $this->line('1️⃣  清除異常價格資料...');
            $invalidPrices = StockPrice::where(function ($query) {
                $query->where('close', '<=', 0)
                      ->orWhereNull('close');
            })->count();

            if ($invalidPrices > 0) {
                StockPrice::where(function ($query) {
                    $query->where('close', '<=', 0)
                          ->orWhereNull('close');
                })->delete();
                
                $this->line("  ✅ 已刪除 {$invalidPrices} 筆異常價格資料");
                $totalDeleted += $invalidPrices;
            } else {
                $this->line("  ✅ 沒有異常價格資料");
            }

            // 2. 清除未來日期的資料 (可能是測試資料)
            $this->line('2️⃣  清除未來日期資料...');
            $futureData = StockPrice::where('trade_date', '>', now()->format('Y-m-d'))->count();

            if ($futureData > 0) {
                StockPrice::where('trade_date', '>', now()->format('Y-m-d'))->delete();
                $this->line("  ✅ 已刪除 {$futureData} 筆未來日期資料");
                $totalDeleted += $futureData;
            } else {
                $this->line("  ✅ 沒有未來日期資料");
            }

            // 3. 清除沒有價格記錄的股票
            $this->line('3️⃣  清除沒有價格記錄的股票...');
            $emptyStocks = Stock::doesntHave('prices')->count();
            
            if ($emptyStocks > 0) {
                Stock::doesntHave('prices')->delete();
                $this->line("  ✅ 已刪除 {$emptyStocks} 檔沒有價格記錄的股票");
                $totalDeleted += $emptyStocks;
            } else {
                $this->line("  ✅ 沒有空股票記錄");
            }

            // 4. 清除成交量和成交金額都為 0 的記錄 (可能是測試資料)
            $this->line('4️⃣  清除無交易量資料...');
            $noTradeData = StockPrice::where('volume', '=', 0)
                ->where(function ($query) {
                    $query->whereNull('turnover')
                          ->orWhere('turnover', '=', 0);
                })
                ->count();

            if ($noTradeData > 0) {
                StockPrice::where('volume', '=', 0)
                    ->where(function ($query) {
                        $query->whereNull('turnover')
                              ->orWhere('turnover', '=', 0);
                    })
                    ->delete();
                    
                $this->line("  ✅ 已刪除 {$noTradeData} 筆無交易量資料");
                $totalDeleted += $noTradeData;
            } else {
                $this->line("  ✅ 沒有無交易量資料");
            }

            // 5. 清除選擇權測試資料 (如果有的話)
            if (Option::count() > 0) {
                $this->line('5️⃣  清除選擇權測試資料...');
                
                // 清除異常的選擇權價格
                $invalidOptionPrices = OptionPrice::where(function ($query) {
                    $query->where('close', '<=', 0)
                          ->orWhereNull('close');
                })->count();

                if ($invalidOptionPrices > 0) {
                    OptionPrice::where(function ($query) {
                        $query->where('close', '<=', 0)
                              ->orWhereNull('close');
                    })->delete();
                    
                    $this->line("  ✅ 已刪除 {$invalidOptionPrices} 筆異常選擇權價格");
                    $totalDeleted += $invalidOptionPrices;
                }

                // 清除沒有價格記錄的選擇權
                $emptyOptions = Option::doesntHave('prices')->count();
                
                if ($emptyOptions > 0) {
                    Option::doesntHave('prices')->delete();
                    $this->line("  ✅ 已刪除 {$emptyOptions} 個沒有價格記錄的選擇權");
                    $totalDeleted += $emptyOptions;
                }
            }

            DB::commit();

            $this->newLine();
            $this->info("✅ 測試資料清除完成！共刪除 {$totalDeleted} 筆資料");
            $this->newLine();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('❌ 清除失敗: ' . $e->getMessage());
            
            Log::error('測試資料清除失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * 驗證資料完整性
     *
     * @return void
     */
    protected function validateData(): void
    {
        $this->info('🔍 驗證資料完整性...');
        $this->newLine();

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
            $issues[] = "有 {$stocksWithoutPrices} 檔股票沒有價格記錄";
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
            $issues[] = "有 {$invalidPrices} 筆異常價格記錄";
        }
        
        if ($nullPrices > 0) {
            $this->warn("  ⚠️  空值價格: {$nullPrices}");
            $issues[] = "有 {$nullPrices} 筆空值價格記錄";
        }

        // 3. 檢查資料新鮮度
        $this->newLine();
        $this->line('📅 檢查資料新鮮度...');
        
        $latestPrice = StockPrice::orderBy('trade_date', 'desc')->first();
        
        if ($latestPrice) {
            $daysSinceLatest = now()->diffInDays($latestPrice->trade_date);
            $this->line("  • 最新資料日期: {$latestPrice->trade_date}");
            $this->line("  • 距今天數: {$daysSinceLatest} 天");
            
            if ($daysSinceLatest > 7) {
                $this->warn("  ⚠️  資料可能過舊 (超過7天)");
                $issues[] = "最新資料已經 {$daysSinceLatest} 天未更新";
            }
        } else {
            $this->error('  ❌ 沒有任何價格資料！');
            $issues[] = '資料庫中沒有任何價格資料';
        }

        // 4. 檢查重複資料
        $this->newLine();
        $this->line('🔄 檢查重複資料...');
        
        $duplicates = DB::table('stock_prices')
            ->select('stock_id', 'trade_date', DB::raw('count(*) as count'))
            ->groupBy('stock_id', 'trade_date')
            ->having('count', '>', 1)
            ->count();

        if ($duplicates > 0) {
            $this->warn("  ⚠️  發現 {$duplicates} 組重複資料");
            $issues[] = "有 {$duplicates} 組重複的股價記錄";
        } else {
            $this->line('  ✅ 沒有重複資料');
        }

        // 5. 檢查選擇權資料 (如果有的話)
        $this->newLine();
        $this->line('📊 檢查選擇權資料...');
        
        $totalOptions = Option::count();
        $totalOptionPrices = OptionPrice::count();

        $this->line("  • 選擇權合約: {$totalOptions}");
        $this->line("  • 選擇權價格: {$totalOptionPrices}");

        // 總結
        $this->newLine();
        $this->info('========================================');
        
        if (empty($issues)) {
            $this->info('✅ 所有檢查通過,資料完整！');
        } else {
            $this->warn('⚠️  發現以下問題:');
            foreach ($issues as $issue) {
                $this->warn("  • {$issue}");
            }
            $this->newLine();
            $this->info('💡 使用 --fix 參數自動修復問題');
        }
        
        $this->info('========================================');
    }

    /**
     * 產生詳細報告
     *
     * @return void
     */
    protected function generateReport(): void
    {
        $this->newLine();
        $this->info('📋 產生詳細報告...');
        $this->newLine();

        // 1. 資料覆蓋率報告
        $this->line('📊 資料覆蓋率:');
        
        $stocks = Stock::withCount('prices')->get();
        
        $coverageData = [];
        foreach ($stocks as $stock) {
            if ($stock->prices_count > 0) {
                $latestDate = $stock->prices()->max('trade_date');
                $earliestDate = $stock->prices()->min('trade_date');
                
                $coverageData[] = [
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'records' => $stock->prices_count,
                    'from' => $earliestDate,
                    'to' => $latestDate,
                ];
            }
        }

        // 排序並顯示前10名
        usort($coverageData, function($a, $b) {
            return $b['records'] - $a['records'];
        });

        $tableData = array_slice(array_map(function($item) {
            return [
                $item['symbol'],
                $item['name'],
                $item['records'],
                $item['from'],
                $item['to'],
            ];
        }, $coverageData), 0, 10);

        if (!empty($tableData)) {
            $this->table(
                ['代碼', '名稱', '記錄數', '起始日', '最新日'],
                $tableData
            );
        } else {
            $this->warn('  ⚠️  沒有資料可以顯示');
        }

        // 2. 每日資料量統計
        $this->newLine();
        $this->line('📅 最近7天資料量:');
        
        $dailyStats = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $count = StockPrice::whereDate('trade_date', $date)->count();
            $dailyStats[] = [$date, $count];
        }

        $this->table(['日期', '記錄數'], $dailyStats);

        // 3. 月度統計
        $this->newLine();
        $this->line('📊 最近3個月統計:');
        
        $monthlyStats = [];
        for ($i = 2; $i >= 0; $i--) {
            $month = now()->subMonths($i)->format('Y-m');
            $count = StockPrice::where('trade_date', 'like', $month . '%')->count();
            $monthlyStats[] = [$month, $count];
        }

        $this->table(['月份', '記錄數'], $monthlyStats);

        // 4. 資料品質指標
        $this->newLine();
        $this->line('🔍 資料品質指標:');
        
        $totalRecords = StockPrice::count();
        $validRecords = StockPrice::where('close', '>', 0)->count();
        $recordsWithVolume = StockPrice::where('volume', '>', 0)->count();
        $recordsWithChangePercent = StockPrice::whereNotNull('change_percent')->count();

        $qualityMetrics = [
            ['總記錄數', number_format($totalRecords)],
            ['有效記錄 (收盤價>0)', number_format($validRecords), $totalRecords > 0 ? round(($validRecords / $totalRecords) * 100, 2) . '%' : '0%'],
            ['有成交量記錄', number_format($recordsWithVolume), $totalRecords > 0 ? round(($recordsWithVolume / $totalRecords) * 100, 2) . '%' : '0%'],
            ['有漲跌幅記錄', number_format($recordsWithChangePercent), $totalRecords > 0 ? round(($recordsWithChangePercent / $totalRecords) * 100, 2) . '%' : '0%'],
        ];

        $this->table(['指標', '數量', '比例'], $qualityMetrics);

        // 5. 選擇權資料統計
        if (Option::count() > 0) {
            $this->newLine();
            $this->line('📊 選擇權資料統計:');
            
            $optionStats = [
                ['選擇權合約總數', Option::count()],
                ['啟用中合約', Option::where('is_active', true)->count()],
                ['Call 合約', Option::where('option_type', 'call')->count()],
                ['Put 合約', Option::where('option_type', 'put')->count()],
                ['價格記錄總數', OptionPrice::count()],
            ];

            $this->table(['項目', '數量'], $optionStats);
        }

        $this->newLine();
        $this->info('✅ 報告產生完成！');
    }

    /**
     * 自動修復問題
     *
     * @return void
     */
    protected function fixIssues(): void
    {
        $this->newLine();
        $this->info('🔧 自動修復問題...');
        $this->newLine();

        DB::beginTransaction();

        try {
            $fixed = 0;

            // 1. 移除重複資料 (保留最新的)
            $this->line('🔄 移除重複資料...');
            
            // 查詢重複資料,取得每組重複資料中最大的 ID (最新的記錄)
            /** @var \Illuminate\Support\Collection<int, \stdClass> $duplicates */
            $duplicates = DB::table('stock_prices')
                ->select('stock_id', 'trade_date', DB::raw('MAX(id) as keep_id'))
                ->groupBy('stock_id', 'trade_date')
                ->having(DB::raw('COUNT(*)'), '>', 1)
                ->get();

            // 遍歷每組重複資料並刪除舊的記錄
            foreach ($duplicates as $dup) {
                // 刪除該股票在該日期的所有記錄,但保留 ID 最大的那筆
                $deleted = StockPrice::where('stock_id', $dup->stock_id)
                    ->where('trade_date', $dup->trade_date)
                    ->where('id', '!=', $dup->keep_id)
                    ->delete();
                
                $fixed += $deleted;
            }

            if ($fixed > 0) {
                $this->line("  ✅ 移除了 {$fixed} 筆重複記錄");
            } else {
                $this->line("  ✅ 沒有發現重複記錄");
            }

            // 2. 修復 change_percent (漲跌幅)
            $this->newLine();
            $this->line('📊 修復漲跌幅資料...');
            
            // 查詢 change_percent 為 null 或 0 但 close 價格存在的記錄
            $needsFix = StockPrice::where(function ($query) {
                $query->whereNull('change_percent')
                      ->orWhere('change_percent', 0);
            })
                ->where('close', '>', 0)
                ->count();

            if ($needsFix > 0) {
                $fixedChangePercent = 0;
                
                // 使用 chunk 處理大量資料,避免記憶體溢出
                StockPrice::where(function ($query) {
                    $query->whereNull('change_percent')
                          ->orWhere('change_percent', 0);
                })
                    ->where('close', '>', 0)
                    ->chunk(1000, function ($prices) use (&$fixedChangePercent) {
                        foreach ($prices as $price) {
                            // 取得前一個交易日的收盤價
                            $prevPrice = StockPrice::where('stock_id', $price->stock_id)
                                ->where('trade_date', '<', $price->trade_date)
                                ->orderBy('trade_date', 'desc')
                                ->first();

                            if ($prevPrice && $prevPrice->close > 0) {
                                // 計算漲跌幅: (今日收盤價 - 昨日收盤價) / 昨日收盤價 * 100
                                $changePercent = (($price->close - $prevPrice->close) / $prevPrice->close) * 100;
                                
                                $price->update([
                                    'change_percent' => round($changePercent, 2)
                                ]);
                                
                                $fixedChangePercent++;
                            }
                        }
                    });

                $this->line("  ✅ 修復了 {$fixedChangePercent} 筆漲跌幅資料");
            } else {
                $this->line("  ✅ 漲跌幅資料正常");
            }

            // 3. 清除異常資料
            $this->newLine();
            $this->line('🗑️  清除異常資料...');
            
            // 刪除價格異常的記錄 (收盤價 <= 0 或為 null)
            $invalidPrices = StockPrice::where(function ($query) {
                $query->where('close', '<=', 0)
                      ->orWhereNull('close');
            })->count();

            if ($invalidPrices > 0) {
                StockPrice::where(function ($query) {
                    $query->where('close', '<=', 0)
                          ->orWhereNull('close');
                })->delete();
                
                $this->line("  ✅ 刪除了 {$invalidPrices} 筆異常價格記錄");
            } else {
                $this->line("  ✅ 沒有異常價格記錄");
            }

            DB::commit();

            $this->newLine();
            $totalFixed = $fixed + ($needsFix > 0 ? 1 : 0) + ($invalidPrices > 0 ? 1 : 0);
            $this->info("✅ 修復完成！共處理 {$totalFixed} 個問題類型");
            $this->newLine();

        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->error('❌ 修復失敗: ' . $e->getMessage());
            $this->newLine();
            $this->error('錯誤詳情:');
            $this->line($e->getTraceAsString());
            
            Log::error('資料修復失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}