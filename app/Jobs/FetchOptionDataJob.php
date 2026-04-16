<?php

namespace App\Jobs;

use App\Models\Option;
use App\Models\OptionPrice;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\TaifexApiService;
use App\Services\TaifexOpenApiService;
use App\Services\OptionDataCleanerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class FetchOptionDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    /** 任務最大嘗試次數 */
    public $tries = 3;

    /** 任務超時時間 */
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

        Log::info('開始執行選擇權資料爬蟲 (TXO)', ['date' => $this->date]);

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
                'cleaned'  => $cleanedData->count(),
                'removed'  => $rawData->count() - $cleanedData->count(),
            ]);

            // === 步驟 3: 取得 Delta 值 (選用) ===
            $deltaData = $taifexApi->getDailyOptionsDelta($this->date);
            $deltaMap  = $this->buildDeltaMap($deltaData);

            // === 步驟 4: 取得標的價格 (用於計算價內價外) ===
            $underlyingPrice = $this->getUnderlyingPrice($this->date);

            if (!$underlyingPrice) {
                Log::warning('無法取得標的價格，underlying_price 欄位將為 null', [
                    'date' => $this->date,
                ]);
            }

            // === 步驟 5: 處理並儲存資料 ===
            $this->processAndSaveData($cleanedData, $deltaMap, $underlyingPrice);

            // === 步驟 6: 產生統計報告 ===
            $statistics = $cleaner->generateStatistics($cleanedData);
            Log::info('資料統計', $statistics);

            // === 步驟 7: 匯出資料 (選用) ===
            if (config('options.export_csv', false)) {
                $csvPath = $cleaner->exportToCsv($cleanedData, "options_{$this->date}.csv");
                Log::info('已匯出 CSV', ['path' => $csvPath]);
            }

            DB::commit();

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('選擇權資料爬蟲執行完成', [
                'date'       => $this->date,
                'duration'   => "{$duration}秒",
                'processed'  => $cleanedData->count(),
                'statistics' => $statistics,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('選擇權資料爬蟲執行失敗', [
                'date'  => $this->date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    // ==========================================
    // 取得標的價格（台股加權指數）
    // ==========================================

    /**
     * 取得標的價格（台股加權指數收盤價）
     *
     * 優先順序:
     *   1. 從 stock_prices 取得加權指數 (symbol = '^TWII')
     *   2. 從期交所 OpenAPI 取得台指期貨最後結算價
     *   3. 從選擇權資料反推 ATM 附近的理論現貨價
     */
    protected function getUnderlyingPrice(string $date): ?float
    {
        $cacheKey = "underlying_price:{$date}";

        return Cache::remember($cacheKey, 3600, function () use ($date) {

            $price = $this->getPriceFromStockPrices($date);
            if ($price) {
                Log::info('標的價格來源: stock_prices (加權指數)', ['date' => $date, 'price' => $price]);
                return $price;
            }

            $price = $this->getPriceFromTaifexApi($date);
            if ($price) {
                Log::info('標的價格來源: 期交所 OpenAPI (TX 期貨)', ['date' => $date, 'price' => $price]);
                return $price;
            }

            $price = $this->getPriceFromOptionImplied($date);
            if ($price) {
                Log::info('標的價格來源: 選擇權反推 (估算)', ['date' => $date, 'price' => $price]);
                return $price;
            }

            Log::warning('無法取得標的價格', ['date' => $date]);
            return null;
        });
    }

    /** 方法 1：從 stock_prices 取加權指數收盤價 */
    private function getPriceFromStockPrices(string $date): ?float
    {
        $price = StockPrice::whereHas('stock', fn($q) => $q->where('symbol', '^TWII'))
            ->where('trade_date', $date)
            ->value('close');

        return $price ? (float) $price : null;
    }

    /** 方法 2：從期交所 OpenAPI 取台指期貨最後成交價 */
    private function getPriceFromTaifexApi(string $date): ?float
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['Accept' => 'application/json'])
                ->get('https://openapi.taifex.com.tw/v1/DailyMarketReportFut');

            if (!$response->successful()) return null;

            $data = $response->json();
            if (empty($data)) return null;

            $targetDate = Carbon::parse($date)->format('Y/m/d');

            $txFutures = collect($data)->filter(function ($item) use ($targetDate) {
                $contract  = $item['Contract'] ?? '';
                $tradeDate = $item['Date'] ?? '';
                return str_starts_with($contract, 'TX')
                    && !str_starts_with($contract, 'TXO')
                    && $tradeDate === $targetDate;
            });

            if ($txFutures->isEmpty()) return null;

            $nearest = $txFutures->sortBy('ContractMonth')->first();
            $close   = $nearest['SettlementPrice'] ?? $nearest['Close'] ?? null;

            return $close ? (float) str_replace(',', '', $close) : null;

        } catch (\Exception $e) {
            Log::warning('期交所 API 查詢失敗', ['date' => $date, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** 方法 3：從當日 OptionPrice 以 Put-Call Parity 反推現貨價 */
    private function getPriceFromOptionImplied(string $date): ?float
    {
        try {
            $nearestExpiry = Option::where('expiry_date', '>=', $date)
                ->orderBy('expiry_date')
                ->value('expiry_date');

            if (!$nearestExpiry) return null;

            $strikes = OptionPrice::whereHas('option', function ($q) use ($nearestExpiry) {
                    $q->where('expiry_date', $nearestExpiry)->where('underlying', 'TXO');
                })
                ->where('trade_date', $date)
                ->where('volume', '>', 0)
                ->whereNotNull('close')
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->select('options.strike_price', 'options.option_type', 'option_prices.close')
                ->get()
                ->groupBy('strike_price');

            if ($strikes->isEmpty()) return null;

            $pairStrikes = $strikes->filter(
                fn($group) => $group->where('option_type', 'call')->count() > 0
                           && $group->where('option_type', 'put')->count() > 0
            );

            if ($pairStrikes->isEmpty()) return null;

            $impliedPrices = $pairStrikes->map(function ($group, $strike) {
                $callClose = (float) $group->where('option_type', 'call')->first()->close;
                $putClose  = (float) $group->where('option_type', 'put')->first()->close;
                return $strike + $callClose - $putClose;
            });

            $sorted = $impliedPrices->sort()->values();
            $median = $sorted->count() % 2 === 0
                ? ($sorted[$sorted->count() / 2 - 1] + $sorted[$sorted->count() / 2]) / 2
                : $sorted[(int)($sorted->count() / 2)];

            return round((float) $median, 2);

        } catch (\Exception $e) {
            Log::warning('選擇權反推標的價格失敗', ['date' => $date, 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ==========================================
    // 其他方法
    // ==========================================

    /** 建立 Delta Map */
    protected function buildDeltaMap($deltaData): array
    {
        $map = [];
        foreach ($deltaData as $item) {
            $optionCode = $item['ContractCode'] ?? '';
            $delta      = floatval($item['Delta'] ?? 0);
            if ($optionCode) {
                $map[$optionCode] = $delta;
            }
        }
        return $map;
    }

    /**
     * 處理並儲存資料
     *
     * OptionDataCleanerService::transformItem 輸出的 key：
     *   option_code, underlying, option_type, strike_price, expiry_date,
     *   trade_date, open, high, low, close, volume, open_interest, implied_volatility
     */
    protected function processAndSaveData($cleanedData, array $deltaMap, ?float $underlyingPrice): void
    {
        $insertCount = 0;
        $updateCount = 0;

        foreach ($cleanedData as $data) {
            try {
                // 1. 建立或取得選擇權合約
                $option = Option::firstOrCreate(
                    ['option_code' => $data['option_code']],
                    [
                        'underlying'     => $data['underlying'],
                        'option_type'    => $data['option_type'],
                        'strike_price'   => $data['strike_price'],
                        'expiry_date'    => $data['expiry_date'],
                        'contract_size'  => '50',
                        'exercise_style' => 'european',
                        'is_active'      => true,
                        'meta_data'      => [
                            'underlying_name' => '臺指選擇權',
                            'expiry_month'    => $data['expiry_month'] ?? null,
                        ],
                    ]
                );

                $option->wasRecentlyCreated ? $insertCount++ : $updateCount++;

                // 2. 取得 Delta 值
                $delta = $deltaMap[$data['option_code']] ?? null;

                // 3. 建立或更新價格記錄
                // ✅ key 名稱對齊 OptionDataCleanerService::transformItem 的輸出
                OptionPrice::updateOrCreate(
                    [
                        'option_id'  => $option->id,
                        'trade_date' => $data['trade_date'],        // ✅ 修正：原本錯誤為 $data['date']
                    ],
                    [
                        'open'               => $data['open']           ?? null,  // ✅ 修正：原本為 open_price
                        'high'               => $data['high']           ?? null,  // ✅ 修正：原本為 high_price
                        'low'                => $data['low']            ?? null,  // ✅ 修正：原本為 low_price
                        'close'              => $data['close']          ?? null,  // ✅ 修正：原本為 close_price
                        'volume'             => $data['volume']         ?? 0,     // ✅ 修正：原本為 volume_total
                        'open_interest'      => $data['open_interest']  ?? null,
                        'implied_volatility' => $data['implied_volatility'] ?? null,
                        'bid'                => null,   // cleaner 未提供
                        'ask'                => null,   // cleaner 未提供
                        'delta'              => $delta,
                        'underlying_price'   => $underlyingPrice,
                        'meta_data'          => null,
                    ]
                );

            } catch (\Exception $e) {
                Log::error('處理單筆選擇權資料失敗', [
                    'option_code' => $data['option_code'],
                    'error'       => $e->getMessage(),
                ]);
                continue;
            }
        }

        Log::info('資料儲存完成', [
            'inserted_options' => $insertCount,
            'updated_options'  => $updateCount,
            'total_prices'     => $cleanedData->count(),
        ]);
    }

    /** 檢查是否為交易日 */
    protected function isTradingDay(string $date): bool
    {
        $carbon = Carbon::parse($date);

        if ($carbon->isWeekend()) {
            return false;
        }

        // TODO: 可擴充國定假日檢查
        return true;
    }
}
