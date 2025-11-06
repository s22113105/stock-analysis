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
     * @param string|null $symbol 股票代碼
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
        $startTime = microtime(true);

        Log::info('開始執行股票資料爬蟲', [
            'symbol' => $this->symbol,
            'date' => $this->date
        ]);

        try {
            // 檢查是否為交易日
            if (!$this->isTradingDay($this->date)) {
                Log::info('非交易日，跳過執行', ['date' => $this->date]);
                return;
            }

            // 如果指定股票代碼
            if ($this->symbol) {
                $this->fetchSingleStock($twseApi, $this->symbol);
            } else {
                // 爬取所有股票
                $this->fetchAllStocks($twseApi);
            }

            $duration = round(microtime(true) - $startTime, 2);

            Log::info('股票資料爬蟲執行完成', [
                'symbol' => $this->symbol ?: 'all',
                'date' => $this->date,
                'duration' => "{$duration}秒"
            ]);
        } catch (\Exception $e) {
            Log::error('股票資料爬蟲執行失敗', [
                'symbol' => $this->symbol,
                'date' => $this->date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 爬取單一股票資料
     */
    protected function fetchSingleStock(TwseApiService $twseApi, $symbol)
    {
        Log::info("開始爬取股票: {$symbol}");

        // 取得該股票當月所有資料
        $stockData = $twseApi->getStockDay($symbol);

        if ($stockData->isEmpty()) {
            Log::warning("無法取得股票資料", ['symbol' => $symbol]);
            return;
        }

        $this->saveStockData($stockData, $symbol);
    }

    /**
     * 爬取所有股票資料
     */
    protected function fetchAllStocks(TwseApiService $twseApi)
    {
        // 取得所有股票代碼
        $symbols = $twseApi->getAllStockSymbols();

        Log::info("準備爬取 {$symbols->count()} 支股票");

        $successCount = 0;
        $failCount = 0;

        foreach ($symbols as $symbol) {
            try {
                $this->fetchSingleStock($twseApi, $symbol);
                $successCount++;

                // 避免請求過快,稍微延遲
                usleep(500000); // 0.5 秒

            } catch (\Exception $e) {
                $failCount++;
                Log::error("爬取股票失敗", [
                    'symbol' => $symbol,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        Log::info('批次爬取完成', [
            'total' => $symbols->count(),
            'success' => $successCount,
            'failed' => $failCount
        ]);
    }

    /**
     * 儲存股票資料到資料庫
     */
    protected function saveStockData($stockData, $symbol)
    {
        DB::beginTransaction();

        try {
            $insertCount = 0;
            $updateCount = 0;
            $skipCount = 0;

            foreach ($stockData as $data) {
                // 確保股票記錄存在
                $stock = Stock::firstOrCreate(
                    ['symbol' => $symbol],
                    [
                        'name' => $data['name'],
                        'exchange' => 'TWSE',
                        'is_active' => true,
                    ]
                );

                // 如果股票名稱有變化,更新它
                if ($stock->name !== $data['name'] && !empty($data['name'])) {
                    $stock->update(['name' => $data['name']]);
                }

                // 檢查這筆資料是否已存在
                $existingPrice = StockPrice::where('stock_id', $stock->id)
                    ->where('trade_date', $data['trade_date'])
                    ->first();

                if ($existingPrice) {
                    // 資料已存在,更新它
                    $existingPrice->update([
                        'open' => $data['open'],
                        'high' => $data['high'],
                        'low' => $data['low'],
                        'close' => $data['close'],
                        'volume' => $data['volume'],
                        'turnover' => $data['turnover'],
                        'change' => $data['change'],
                        'change_percent' => $this->calculateChangePercent($data),
                    ]);
                    $updateCount++;
                } else {
                    // 新增資料
                    StockPrice::create([
                        'stock_id' => $stock->id,
                        'trade_date' => $data['trade_date'],
                        'open' => $data['open'],
                        'high' => $data['high'],
                        'low' => $data['low'],
                        'close' => $data['close'],
                        'volume' => $data['volume'],
                        'turnover' => $data['turnover'],
                        'change' => $data['change'],
                        'change_percent' => $this->calculateChangePercent($data),
                    ]);
                    $insertCount++;
                }
            }

            DB::commit();

            Log::info("股票資料儲存完成", [
                'symbol' => $symbol,
                'inserted' => $insertCount,
                'updated' => $updateCount,
                'skipped' => $skipCount
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("儲存股票資料失敗", [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * 計算漲跌幅百分比
     */
    protected function calculateChangePercent($data)
    {
        if (!$data['close'] || !$data['change']) {
            return 0;
        }

        $previousClose = $data['close'] - $data['change'];

        if ($previousClose <= 0) {
            return 0;
        }

        return round(($data['change'] / $previousClose) * 100, 2);
    }

    /**
     * 檢查是否為交易日
     */
    protected function isTradingDay($date)
    {
        $carbon = Carbon::parse($date);

        // 週末不是交易日
        if ($carbon->isWeekend()) {
            return false;
        }

        // TODO: 可以從資料庫或 API 檢查是否為國定假日
        // 目前僅檢查週末

        return true;
    }

    /**
     * 任務失敗時的處理
     */
    public function failed(\Throwable $exception)
    {
        Log::error('股票資料爬蟲任務失敗', [
            'symbol' => $this->symbol,
            'date' => $this->date,
            'error' => $exception->getMessage()
        ]);

        // TODO: 發送通知給管理員
        // Notification::send($admins, new JobFailedNotification($exception));
    }
}
