<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\TwseApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchStockDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;
    protected $symbol;

    public $tries = 3;
    public $timeout = 300;

    public function __construct(string $date, ?string $symbol = null)
    {
        $this->date = $date;
        $this->symbol = $symbol;
    }

    public function handle(TwseApiService $twseApi)
    {
        try {
            // ✅ 決定實際有效日期（今日未收盤或未來日期 → 改用最近收盤日）
            $effectiveDate = $this->resolveEffectiveDate($this->date);

            // ✅ 檢查資料庫是否已有足夠資料，有則跳過
            if ($this->dataAlreadyExists($effectiveDate)) {
                Log::info('資料庫已有足夠資料，跳過爬蟲', [
                    'date'   => $effectiveDate,
                    'symbol' => $this->symbol ?? 'all',
                ]);
                if (app()->runningInConsole()) {
                    echo "⏭️  跳過: {$effectiveDate} 資料已存在於資料庫\n";
                }
                return;
            }

            // 如果是全市場模式，額外檢查是否為交易日
            if (!$this->symbol && !$this->isTradingDay($effectiveDate)) {
                Log::info('非交易日，跳過執行', ['date' => $effectiveDate]);
                return;
            }

            if ($this->symbol) {
                $this->processSingleStockHistory($twseApi, $this->symbol, $effectiveDate);
            } else {
                $this->processAllStocks($twseApi, $effectiveDate);
            }

        } catch (\Exception $e) {
            if (app()->runningInConsole()) {
                echo "❌ 錯誤: " . $e->getMessage() . "\n";
            }
            Log::error('爬蟲失敗', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ==========================================
    // 日期處理
    // ==========================================

    /**
     * 決定實際有效的爬蟲日期
     * 若指定日期為今日且收盤前（13:30 前），或為未來日期，則改用最近有效收盤日
     */
    protected function resolveEffectiveDate(string $requestedDate): string
    {
        $now       = Carbon::now('Asia/Taipei');
        $requested = Carbon::parse($requestedDate, 'Asia/Taipei');

        $isToday      = $requested->isToday();
        $notClosedYet = $now->lt($now->copy()->setTime(13, 30, 0));

        if ($requested->isFuture() || ($isToday && $notClosedYet)) {
            Log::info('指定日期尚未收盤，改用最近有效交易日', [
                'requested'    => $requestedDate,
                'current_time' => $now->toTimeString(),
            ]);

            // 往前找最近的平日
            $date = $now->copy()->subDay();
            while ($date->isWeekend()) {
                $date->subDay();
            }
            return $date->format('Y-m-d');
        }

        return $requestedDate;
    }

    // ==========================================
    // 資料存在檢查
    // ==========================================

    /**
     * 檢查資料庫是否已有足夠資料
     * 使用筆數門檻避免「部分資料」被誤判為完整
     */
    protected function dataAlreadyExists(string $date): bool
    {
        if ($this->symbol) {
            // 單一股票模式：該月有 >= 15 筆才視為完整（一個月約 20 個交易日）
            $count = StockPrice::whereHas('stock', function ($q) {
                    $q->where('symbol', $this->symbol);
                })
                ->whereYear('trade_date', Carbon::parse($date)->year)
                ->whereMonth('trade_date', Carbon::parse($date)->month)
                ->count();

            return $count >= 15;
        }

        // 全市場模式：該日期有 >= 500 筆才視為完整（台股上市約 900+ 檔）
        $count = StockPrice::where('trade_date', $date)->count();

        return $count >= 500;
    }

    // ==========================================
    // 核心爬蟲流程
    // ==========================================

    /**
     * 處理單一股票歷史資料（按月抓取）
     */
    protected function processSingleStockHistory(TwseApiService $twseApi, string $symbol, string $date)
    {
        DB::beginTransaction();

        try {
            $stockData = $twseApi->getStockDay($symbol, $date);

            if ($stockData->isEmpty()) {
                if (app()->runningInConsole()) {
                    $targetMonth = Carbon::parse($date)->format('Y-m');
                    echo "⚠️  無資料: {$symbol} 在 {$targetMonth} 無交易紀錄 (可能是假日或 API 限制)\n";
                }
                DB::rollBack();
                return;
            }

            $this->saveStockData($stockData->toArray());
            DB::commit();

            if (app()->runningInConsole()) {
                $month = Carbon::parse($date)->format('Y-m');
                echo "✅ 成功更新: {$symbol} {$month} 月份資料 (共 {$stockData->count()} 筆)\n";
            }

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 處理全市場當日資料
     */
    protected function processAllStocks(TwseApiService $twseApi, string $effectiveDate)
    {
        DB::beginTransaction();
        try {
            $stockData = $twseApi->getStockDayAll($effectiveDate);

            if ($stockData->isEmpty()) {
                Log::warning('無股票資料', ['date' => $effectiveDate]);
                DB::rollBack();
                return;
            }

            $this->saveStockData($stockData->toArray());
            DB::commit();

            if (app()->runningInConsole()) {
                echo "✅ 成功更新: {$effectiveDate} 全市場資料 (共 {$stockData->count()} 筆)\n";
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    // ==========================================
    // 資料儲存
    // ==========================================

    /**
     * 儲存股票資料至資料庫
     */
    protected function saveStockData(array $stockData)
    {
        foreach ($stockData as $data) {
            // ✅ 跳過價格欄位為 null 或無效的資料（停牌、無成交等）
            if (
                is_null($data['open'])  || $data['open']  <= 0 ||
                is_null($data['high'])  || $data['high']  <= 0 ||
                is_null($data['low'])   || $data['low']   <= 0 ||
                is_null($data['close']) || $data['close'] <= 0
            ) {
                Log::debug('跳過無效價格資料', [
                    'symbol' => $data['symbol'] ?? 'unknown',
                    'date'   => $data['trade_date'] ?? 'unknown',
                    'open'   => $data['open'] ?? null,
                ]);
                continue;
            }

            $stock = Stock::updateOrCreate(
                ['symbol' => $data['symbol']],
                [
                    'name'      => $data['name'],
                    'exchange'  => 'TWSE',
                    'is_active' => true,
                ]
            );

            StockPrice::updateOrCreate(
                [
                    'stock_id'   => $stock->id,
                    'trade_date' => $data['trade_date'],
                ],
                [
                    'open'           => $data['open'],
                    'high'           => $data['high'],
                    'low'            => $data['low'],
                    'close'          => $data['close'],
                    'volume'         => $data['volume'] ?? 0,
                    'turnover'       => $data['turnover'] ?? 0,
                    'change'         => $data['change'] ?? 0,
                    'change_percent' => $this->calculateChangePercent($data),
                    // ✅ 修正欄位名稱：transactions（複數），對應 migration
                    'transactions'   => $data['transactions'] ?? $data['transaction'] ?? 0,
                ]
            );
        }
    }

    // ==========================================
    // 輔助方法
    // ==========================================

    /**
     * 計算漲跌幅百分比
     */
    protected function calculateChangePercent(array $data): float
    {
        if (isset($data['change_percent']) && $data['change_percent'] != 0) {
            return $data['change_percent'];
        }

        $change        = $data['change'] ?? 0;
        $previousClose = ($data['close'] ?? 0) - $change;

        return ($previousClose > 0)
            ? round(($change / $previousClose) * 100, 2)
            : 0;
    }

    /**
     * 檢查是否為交易日（簡單判斷：非週末）
     */
    protected function isTradingDay(string $date): bool
    {
        return !Carbon::parse($date)->isWeekend();
    }
}
