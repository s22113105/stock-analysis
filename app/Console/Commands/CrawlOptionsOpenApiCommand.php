<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TaifexOpenApiService;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrawlOptionsOpenApiCommand extends Command
{
    protected $signature = 'crawler:options-api
                            {--date= : 指定日期 (Y-m-d)，不指定則取最新資料}';

    protected $description = '使用 OpenAPI (JSON) 執行選擇權資料爬蟲 - 只抓取 TXO';

    protected $apiService;

    public function __construct(TaifexOpenApiService $apiService)
    {
        parent::__construct();
        $this->apiService = $apiService;
    }

    public function handle()
    {
        $this->info('========================================');
        $this->info('🚀 選擇權資料爬蟲 - OpenAPI (JSON)');
        $this->info('========================================');
        $this->newLine();

        $date = $this->option('date');

        if ($date) {
            $this->info("📅 指定日期: {$date}");
            $this->warn('注意: API 只返回最新資料，可能無法取得指定日期');
        } else {
            $this->info("📅 取得最新資料");
        }

        $this->info('🎯 只抓取: TXO (台指選擇權)');
        $this->newLine();

        try {
            // 1. 從 OpenAPI 取得資料（已清理和轉換）
            $this->line('⏳ 正在呼叫 OpenAPI...');

            $cleanedData = $this->apiService->getDailyOptionsData($date);

            if ($cleanedData->isEmpty()) {
                $this->error('❌ 無法取得資料');
                $this->warn('可能原因：');
                $this->line('  - API 暫時無法連線');
                $this->line('  - 該日期無交易資料');
                $this->line('  - 非交易日');
                return Command::FAILURE;
            }

            $this->info("✅ 取得 {$cleanedData->count()} 筆 TXO 資料");

            // 檢查資料的實際日期
            $actualDate = $cleanedData->first()['date'] ?? null;
            if ($actualDate) {
                $this->line("📅 資料日期: {$actualDate}");
            }

            $this->newLine();

            // 2. 資料驗證
            $this->line('⏳ 正在驗證資料...');

            $validCount = 0;
            foreach ($cleanedData as $item) {
                if (!empty($item['option_code']) && $item['strike_price'] > 0) {
                    $validCount++;
                }
            }

            if ($validCount === 0) {
                $this->error('❌ 資料驗證失敗：沒有有效記錄');
                $this->line('資料範例:');
                $sample = $cleanedData->first();
                $this->line(json_encode([
                    'option_code' => $sample['option_code'] ?? 'missing',
                    'strike_price' => $sample['strike_price'] ?? 'missing',
                    'option_type' => $sample['option_type'] ?? 'missing',
                ], JSON_PRETTY_PRINT));
                return Command::FAILURE;
            }

            $this->info("✅ 驗證完成，有效資料: {$validCount} 筆");
            $this->newLine();

            // 3. 儲存到資料庫
            $this->line('⏳ 正在儲存到資料庫...');

            $result = $this->saveToDatabase($cleanedData);

            $this->newLine();
            $this->info('========================================');
            $this->info('📊 執行結果');
            $this->info('========================================');
            $this->newLine();

            $this->info("✅ 新增選擇權合約: {$result['saved_options']} 個");
            $this->info("✅ 更新價格記錄: {$result['updated_prices']} 筆");

            if (!empty($actualDate)) {
                $this->info("📅 資料日期: {$actualDate}");
            }

            $this->newLine();

            // 4. 顯示統計資訊
            $this->info('📈 資料統計:');
            $callCount = $cleanedData->where('option_type', 'call')->count();
            $putCount = $cleanedData->where('option_type', 'put')->count();
            $avgVolume = $cleanedData->avg('volume_total');

            $this->line("   總筆數: {$cleanedData->count()}");
            $this->line("   Call: {$callCount} 筆");
            $this->line("   Put: {$putCount} 筆");
            $this->line("   平均成交量: " . number_format($avgVolume, 0));
            $this->newLine();

            $this->info('💡 資料已儲存到:');
            $this->line('   - options 表 (選擇權合約)');
            $this->line('   - option_prices 表 (每日價格)');
            $this->newLine();

            $this->info('🎯 驗證資料:');
            $this->line('   php artisan tinker');
            $this->line('   >>> \\App\\Models\\OptionPrice::whereDate(\'trade_date\', \'' . ($actualDate ?? 'today') . '\')->count()');
            $this->newLine();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ 執行失敗: ' . $e->getMessage());
            $this->error('詳細錯誤: ' . $e->getFile() . ':' . $e->getLine());
            $this->newLine();
            $this->error('請查看 Log: tail -f storage/logs/laravel.log');

            Log::error('OpenAPI 爬蟲執行失敗', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 儲存資料到資料庫
     */
    protected function saveToDatabase($cleanedData): array
    {
        DB::beginTransaction();

        try {
            $savedOptions = 0;
            $updatedPrices = 0;

            $progressBar = $this->output->createProgressBar($cleanedData->count());
            $progressBar->start();

            foreach ($cleanedData as $data) {
                // 驗證必要欄位
                if (empty($data['option_code']) || $data['strike_price'] <= 0) {
                    $progressBar->advance();
                    continue;
                }

                // 建立或取得選擇權合約
                $option = Option::firstOrCreate(
                    ['option_code' => $data['option_code']],
                    [
                        'underlying' => $data['underlying'],
                        'option_type' => $data['option_type'],
                        'strike_price' => $data['strike_price'],
                        'expiry_date' => $data['expiry_date'],
                        'contract_size' => '50',
                        'exercise_style' => 'european',
                        'is_active' => true,
                        'meta_data' => [
                            'underlying_name' => '臺指選擇權',
                            'expiry_month' => $data['expiry_month'] ?? null,
                            'created_by' => 'crawler_openapi',
                            'created_at' => now()->toDateTimeString(),
                        ]
                    ]
                );

                if ($option->wasRecentlyCreated) {
                    $savedOptions++;
                }

                // 建立或更新價格記錄
                OptionPrice::updateOrCreate(
                    [
                        'option_id' => $option->id,
                        'trade_date' => $data['date']
                    ],
                    [
                        'open' => $data['open_price'],
                        'high' => $data['high_price'],
                        'low' => $data['low_price'],
                        'close' => $data['close_price'],
                        'settlement' => $data['settlement_price'] ?? null,
                        'change' => $data['change'] ?? null,
                        'change_percent' => $data['change_percent'] ?? null,
                        'volume' => $data['volume_total'],
                        'volume_general' => $data['volume_general'] ?? null,
                        'volume_afterhours' => $data['volume_afterhours'] ?? null,
                        'open_interest' => $data['open_interest'],
                        'bid' => $data['best_bid'] ?? null,
                        'ask' => $data['best_ask'] ?? null,
                        'bid_volume' => $data['bid_volume'] ?? null,
                        'ask_volume' => $data['ask_volume'] ?? null,
                        'spread' => $data['spread'] ?? null,
                        'mid_price' => $data['mid_price'] ?? null,
                    ]
                );

                $updatedPrices++;
                $progressBar->advance();
            }

            $progressBar->finish();

            DB::commit();

            Log::info('OpenAPI 資料儲存完成', [
                'saved_options' => $savedOptions,
                'updated_prices' => $updatedPrices,
            ]);

            return [
                'saved_options' => $savedOptions,
                'updated_prices' => $updatedPrices,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
