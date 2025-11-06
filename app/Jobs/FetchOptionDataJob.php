<?php

namespace App\Jobs;

use App\Models\Option;
use App\Models\OptionPrice;
use App\Services\TaifexApiService;
use App\Services\OptionDataCleanerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchOptionDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    /**
     * 任務最大嘗試次數
     */
    public $tries = 3;

    /**
     * 任務超時時間
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     *
     * @param string|null $date 日期 (Y-m-d)
     */
    public function __construct($date = null)
    {
        $this->date = $date ?: now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(
        TaifexApiService $taifexApi,
        OptionDataCleanerService $cleaner
    ) {
        $startTime = microtime(true);

        Log::info('開始執行選擇權資料爬蟲 (TXO)', [
            'date' => $this->date
        ]);

        try {
            // 檢查是否為交易日
            if (!$this->isTradingDay($this->date)) {
                Log::info('非交易日，跳過執行', ['date' => $this->date]);
                return;
            }

            DB::beginTransaction();

            // === 步驟 1: 取得原始資料 ===
            $rawData = $taifexApi->getDailyOptionsReport($this->date);

            if ($rawData->isEmpty()) {
                Log::warning('無選擇權資料', ['date' => $this->date]);
                DB::rollBack();
                return;
            }

            Log::info("取得原始資料", ['count' => $rawData->count()]);

            // === 步驟 2: 清理與轉換資料 ===
            $cleanedData = $cleaner->cleanAndTransform($rawData, $this->date);

            if ($cleanedData->isEmpty()) {
                Log::warning('清理後無有效資料', ['date' => $this->date]);
                DB::rollBack();
                return;
            }

            Log::info("資料清理完成", [
                'original' => $rawData->count(),
                'cleaned' => $cleanedData->count(),
                'removed' => $rawData->count() - $cleanedData->count()
            ]);

            // === 步驟 3: 取得 Delta 值 (選用) ===
            $deltaData = $taifexApi->getDailyOptionsDelta($this->date);
            $deltaMap = $this->buildDeltaMap($deltaData);

            // === 步驟 4: 取得標的價格 (用於計算價內價外) ===
            $underlyingPrice = $this->getUnderlyingPrice($this->date);

            // === 步驟 5: 處理並儲存資料 ===
            $this->processAndSaveData($cleanedData, $deltaMap, $underlyingPrice);

            // === 步驟 6: 產生統計報告 ===
            $statistics = $cleaner->generateStatistics($cleanedData);
            Log::info('資料統計', $statistics);

            // === 步驟 7: 匯出資料 (選用) ===
            if (config('options.export_csv', false)) {
                $csvPath = $cleaner->exportToCsv(
                    $cleanedData,
                    "options_{$this->date}.csv"
                );
                Log::info('已匯出 CSV', ['path' => $csvPath]);
            }

            DB::commit();

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('選擇權資料爬蟲執行完成', [
                'date' => $this->date,
                'duration' => "{$duration}秒",
                'processed' => $cleanedData->count(),
                'statistics' => $statistics
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('選擇權資料爬蟲執行失敗', [
                'date' => $this->date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 處理並儲存資料
     */
    protected function processAndSaveData($cleanedData, $deltaMap, $underlyingPrice)
    {
        $insertCount = 0;
        $updateCount = 0;

        foreach ($cleanedData as $data) {
            try {
                // 1. 建立或取得選擇權合約
                $option = Option::firstOrCreate(
                    ['option_code' => $data['option_code']],
                    [
                        'underlying' => $data['underlying'],
                        'option_type' => $data['option_type'],
                        'strike_price' => $data['strike_price'],
                        'expiry_date' => $data['expiry_date'],
                        'contract_size' => '50', // TXO 一口 = 指數 x 50
                        'exercise_style' => 'european',
                        'is_active' => true,
                        'meta_data' => [
                            'underlying_name' => '臺指選擇權',
                            'expiry_month' => $data['expiry_month']
                        ]
                    ]
                );

                if ($option->wasRecentlyCreated) {
                    $insertCount++;
                } else {
                    $updateCount++;
                }

                // 2. 取得 Delta 值
                $delta = $deltaMap[$data['option_code']] ?? null;

                // 3. 建立或更新價格記錄
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
                        'settlement' => $data['settlement_price'],
                        'change' => $data['change'],
                        'change_percent' => $data['change_percent'],

                        // 交易量資訊
                        'volume' => $data['volume_total'],
                        'volume_general' => $data['volume_general'],
                        'volume_afterhours' => $data['volume_afterhours'],
                        'open_interest' => $data['open_interest'],

                        // 買賣報價
                        'bid' => $data['best_bid'],
                        'ask' => $data['best_ask'],
                        'bid_volume' => $data['bid_volume'],
                        'ask_volume' => $data['ask_volume'],

                        // 計算欄位
                        'spread' => $data['spread'],
                        'mid_price' => $data['mid_price'],

                        // Greeks (如果有)
                        'delta' => $delta,

                        // 價內價外資訊
                        'moneyness' => $data['moneyness'],
                        'intrinsic_value' => $data['intrinsic_value'],
                        'time_value' => $data['time_value'],
                        'underlying_price' => $underlyingPrice,

                        // 原始資料
                        'meta_data' => [
                            'raw_data' => $data['raw_data'] ?? null
                        ]
                    ]
                );
            } catch (\Exception $e) {
                Log::error('處理單筆選擇權資料失敗', [
                    'option_code' => $data['option_code'],
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info('資料儲存完成', [
            'inserted_options' => $insertCount,
            'updated_options' => $updateCount,
            'total_prices' => $cleanedData->count()
        ]);
    }

    /**
     * 建立 Delta Map
     */
    protected function buildDeltaMap($deltaData)
    {
        $map = [];

        foreach ($deltaData as $item) {
            $optionCode = $item['ContractCode'] ?? '';
            $delta = floatval($item['Delta'] ?? 0);

            if ($optionCode) {
                $map[$optionCode] = $delta;
            }
        }

        return $map;
    }

    /**
     * 取得標的價格 (台指期貨收盤價或現貨指數)
     * 用於計算選擇權的價內價外狀態
     */
    protected function getUnderlyingPrice($date): ?float
    {
        // 方法 1: 從資料庫取得台指期貨價格
        // $future = \App\Models\FuturePrice::where('trade_date', $date)
        //     ->where('contract_code', 'LIKE', 'TX%')
        //     ->first();

        // if ($future) {
        //     return $future->settlement_price ?? $future->close_price;
        // }

        // 方法 2: 從台股加權指數取得
        // $twIndex = \App\Models\StockPrice::where('trade_date', $date)
        //     ->whereHas('stock', function($q) {
        //         $q->where('symbol', '^TWII');
        //     })
        //     ->first();

        // if ($twIndex) {
        //     return $twIndex->close;
        // }

        // 方法 3: 從選擇權資料推估 (暫時方案)
        // 找價平附近的 Call 和 Put 的收盤價差

        // 暫時返回 null,稍後補充
        return null;
    }

    /**
     * 檢查是否為交易日
     */
    protected function isTradingDay($date): bool
    {
        $carbon = Carbon::parse($date);

        // 週末不交易
        if ($carbon->isWeekend()) {
            return false;
        }

        // TODO: 檢查是否為國定假日
        // 可以建立一個假日表或使用外部 API

        return true;
    }
}
