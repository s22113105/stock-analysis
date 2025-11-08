<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TaifexOpenApiService;
use App\Services\OptionDataCleanerService;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CrawlOptionsOpenApiCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:options-api
                            {--date= : 指定日期 (Y-m-d)，不指定則取最新資料}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '使用 OpenAPI (JSON) 執行選擇權資料爬蟲 - 只抓取 TXO';

    protected $apiService;
    protected $cleanerService;

    /**
     * Create a new command instance.
     */
    public function __construct(
        TaifexOpenApiService $apiService,
        OptionDataCleanerService $cleanerService
    ) {
        parent::__construct();
        $this->apiService = $apiService;
        $this->cleanerService = $cleanerService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('========================================');
        $this->info('🚀 選擇權資料爬蟲 - OpenAPI (JSON)');
        $this->info('========================================');
        $this->newLine();

        $date = $this->option('date');

        if ($date) {
            $this->info("📅 指定日期: {$date}");
        } else {
            $this->info("📅 取得最新資料");
        }

        $this->info('🎯 只抓取: TXO (台指選擇權)');
        $this->newLine();

        try {
            // 1. 從 OpenAPI 取得資料
            $this->line('⏳ 正在呼叫 OpenAPI...');

            $rawData = $this->apiService->getDailyOptionsData($date);

            if ($rawData->isEmpty()) {
                $this->error('❌ 無法取得資料');
                $this->warn('可能原因：');
                $this->line('  - API 暫時無法連線');
                $this->line('  - 該日期無交易資料');
                $this->line('  - 非交易日');
                return Command::FAILURE;
            }

            $this->info("✅ 取得 {$rawData->count()} 筆 TXO 資料");
            $this->newLine();

            // 2. 資料清理與驗證
            $this->line('⏳ 正在清理與驗證資料...');

            $cleanedData = $this->cleanerService->cleanAndTransform(
                $rawData,
                $date ?? now()->format('Y-m-d')
            );

            if ($cleanedData->isEmpty()) {
                $this->error('❌ 資料清理後無有效記錄');
                return Command::FAILURE;
            }

            $this->info("✅ 清理完成，有效資料: {$cleanedData->count()} 筆");
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
            $this->newLine();

            // 4. 顯示統計資訊
            $statistics = $this->cleanerService->generateStatistics($cleanedData);

            $this->info('📈 資料統計:');
            $this->line("   總筆數: {$statistics['total_count']}");
            $this->line("   Call: {$statistics['call_count']} 筆");
            $this->line("   Put: {$statistics['put_count']} 筆");

            if (isset($statistics['avg_volume'])) {
                $this->line("   平均成交量: " . number_format($statistics['avg_volume'], 0));
            }

            $this->newLine();

            $this->info('💡 資料已儲存到:');
            $this->line('   - options 表 (選擇權合約)');
            $this->line('   - option_prices 表 (每日價格)');
            $this->newLine();

            $this->info('🎯 後續可以:');
            $this->line('   1. 前端從資料庫查詢顯示圖表');
            $this->line('   2. 預測模型從資料庫讀取訓練資料');
            $this->line('   3. API 服務從資料庫提供資料');
            $this->newLine();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ 執行失敗: ' . $e->getMessage());
            $this->error('請查看 Log: tail -f storage/logs/laravel.log');

            Log::error('OpenAPI 爬蟲執行失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return Command::FAILURE;
        }
    }

    /**
     * 儲存資料到資料庫
     */
    protected function saveToDatabase(Collection $cleanedData): array
    {
        DB::beginTransaction();

        try {
            $savedOptions = 0;
            $updatedPrices = 0;

            $progressBar = $this->output->createProgressBar($cleanedData->count());
            $progressBar->start();

            foreach ($cleanedData as $data) {
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
                        // 價格資訊
                        'open' => $data['open_price'],
                        'high' => $data['high_price'],
                        'low' => $data['low_price'],
                        'close' => $data['close_price'],
                        'settlement' => $data['settlement_price'] ?? null,
                        'change' => $data['change'] ?? null,
                        'change_percent' => $data['change_percent'] ?? null,

                        // 交易量資訊
                        'volume' => $data['volume_total'],
                        'volume_general' => $data['volume_general'] ?? null,
                        'volume_afterhours' => $data['volume_afterhours'] ?? null,
                        'open_interest' => $data['open_interest'],

                        // 買賣報價
                        'bid' => $data['best_bid'] ?? null,
                        'ask' => $data['best_ask'] ?? null,
                        'bid_volume' => $data['bid_volume'] ?? null,
                        'ask_volume' => $data['ask_volume'] ?? null,

                        // 計算欄位
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
