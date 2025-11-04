<?php

namespace App\Jobs;

use App\Models\Option;
use App\Models\Stock;
use App\Models\OptionPrice;
use App\Services\TwseApiService;
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
    protected $symbol;

    /**
     * 任務最大嘗試次數
     */
    public $tries = 3;

    /**
     * 任務超時時間
     */
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct($date = null, $symbol = null)
    {
        $this->date = $date ?: now()->format('Y-m-d');
        $this->symbol = $symbol;
    }

    /**
     * Execute the job.
     */
    public function handle(TwseApiService $twseApi)
    {
        Log::info('開始執行選擇權資料爬蟲', [
            'date' => $this->date,
            'symbol' => $this->symbol
        ]);

        try {
            DB::beginTransaction();

            // 取得選擇權每日交易資料
            $this->updateOptionDailyData($twseApi);

            // 取得選擇權結算價格
            $this->updateOptionSettlementPrices($twseApi);

            // 計算隱含波動率
            $this->calculateImpliedVolatility();

            // 更新未平倉量
            $this->updateOpenInterest($twseApi);

            DB::commit();

            Log::info('選擇權資料爬蟲執行完成', [
                'date' => $this->date,
                'symbol' => $this->symbol
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('選擇權資料爬蟲執行失敗', [
                'date' => $this->date,
                'symbol' => $this->symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 更新選擇權每日資料
     */
    protected function updateOptionDailyData(TwseApiService $twseApi)
    {
        $dateString = Carbon::parse($this->date)->format('Ym');
        $optionData = $twseApi->getOptionDayAll($dateString);

        if ($optionData->isEmpty()) {
            Log::warning('無選擇權資料', ['date' => $this->date]);
            return;
        }

        foreach ($optionData as $data) {
            // 這裡需要根據實際的選擇權代碼邏輯來處理
            // 暫時使用簡化的邏輯
            $this->processOptionData($data);
        }

        Log::info('選擇權每日資料更新完成', [
            'date' => $this->date,
            'count' => $optionData->count()
        ]);
    }

    /**
     * 處理單筆選擇權資料
     */
    protected function processOptionData($data)
    {
        // 解析選擇權代碼，取得標的股票、履約價、到期日等資訊
        // 這需要根據實際的代碼格式來實作

        // 範例邏輯（需要根據實際情況調整）
        if (!$this->symbol) {
            // 處理所有選擇權
            $this->updateAllOptions($data);
        } else {
            // 只處理特定標的的選擇權
            $stock = Stock::where('symbol', $this->symbol)->first();
            if ($stock) {
                $this->updateStockOptions($stock, $data);
            }
        }
    }

    /**
     * 更新所有選擇權
     */
    protected function updateAllOptions($data)
    {
        // 實作更新所有選擇權的邏輯
        // 這需要根據 TWSE API 的實際回傳格式來處理
    }

    /**
     * 更新特定股票的選擇權
     */
    protected function updateStockOptions(Stock $stock, $data)
    {
        $options = Option::where('stock_id', $stock->id)
            ->where('expiry_date', '>=', $this->date)
            ->get();

        foreach ($options as $option) {
            // 建立或更新選擇權價格
            OptionPrice::updateOrCreate(
                [
                    'option_id' => $option->id,
                    'trade_date' => $this->date
                ],
                [
                    'open_price' => $data['open'] ?? null,
                    'high_price' => $data['high'] ?? null,
                    'low_price' => $data['low'] ?? null,
                    'close_price' => $data['close'] ?? null,
                    'settlement_price' => $data['settlement'] ?? null,
                    'volume' => $data['volume'] ?? 0,
                    'open_interest' => $data['open_interest'] ?? 0,
                    'bid_price' => $data['bid'] ?? null,
                    'ask_price' => $data['ask'] ?? null,
                ]
            );
        }
    }

    /**
     * 更新選擇權結算價格
     */
    protected function updateOptionSettlementPrices(TwseApiService $twseApi)
    {
        // 實作取得結算價格的邏輯
        Log::info('更新選擇權結算價格', ['date' => $this->date]);
    }

    /**
     * 計算隱含波動率
     */
    protected function calculateImpliedVolatility()
    {
        $options = Option::where('expiry_date', '>=', $this->date)
            ->where('is_active', true)
            ->get();

        foreach ($options as $option) {
            $optionPrice = $option->prices()
                ->where('trade_date', $this->date)
                ->first();

            if (!$optionPrice || !$optionPrice->close_price) {
                continue;
            }

            // 取得標的股票價格
            $stockPrice = $option->stock->prices()
                ->where('trade_date', $this->date)
                ->first();

            if (!$stockPrice) {
                continue;
            }

            // 計算隱含波動率（這裡需要實作 Black-Scholes 反算）
            $iv = $this->calculateIV(
                $stockPrice->close_price,
                $option->strike_price,
                $option->option_type,
                $optionPrice->close_price,
                $this->getTimeToExpiry($option->expiry_date)
            );

            if ($iv !== null) {
                $optionPrice->update(['implied_volatility' => $iv]);
            }
        }

        Log::info('隱含波動率計算完成', ['date' => $this->date]);
    }

    /**
     * 計算到期時間（年化）
     */
    protected function getTimeToExpiry($expiryDate)
    {
        $days = Carbon::parse($this->date)->diffInDays(Carbon::parse($expiryDate));
        return $days / 365.0;
    }

    /**
     * 計算隱含波動率（簡化版）
     */
    protected function calculateIV($spotPrice, $strikePrice, $optionType, $optionPrice, $timeToExpiry)
    {
        // 這裡應該實作 Newton-Raphson 方法來反算隱含波動率
        // 暫時返回一個估計值
        $moneyness = $spotPrice / $strikePrice;

        if ($optionType == 'call') {
            if ($moneyness > 1.1) {
                // Deep ITM
                return 0.15;
            } elseif ($moneyness > 0.9) {
                // ATM
                return 0.20;
            } else {
                // OTM
                return 0.25;
            }
        } else {
            if ($moneyness < 0.9) {
                // Deep ITM
                return 0.15;
            } elseif ($moneyness < 1.1) {
                // ATM
                return 0.20;
            } else {
                // OTM
                return 0.25;
            }
        }
    }

    /**
     * 更新未平倉量
     */
    protected function updateOpenInterest(TwseApiService $twseApi)
    {
        // 實作更新未平倉量的邏輯
        Log::info('更新未平倉量', ['date' => $this->date]);
    }

    /**
     * 判斷是否為交易日
     */
    protected function isTradingDay($date)
    {
        $carbon = Carbon::parse($date);

        // 週末不是交易日
        if ($carbon->isWeekend()) {
            return false;
        }

        // 這裡可以加入國定假日的判斷
        // 可以從資料庫或設定檔讀取假日清單

        return true;
    }
}
