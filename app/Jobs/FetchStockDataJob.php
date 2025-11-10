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

    /**
     * Create a new job instance.
     *
     * @param string $date 日期 (Y-m-d)
     * @param string|null $symbol 股票代碼 (null = 全部)
     */
    public function __construct(string $date, ?string $symbol = null)
    {
        $this->date = $date;
        $this->symbol = $symbol;
    }

    /**
     * Execute the job.
     */
    public function handle(TwseApiService $twseApi)
    {
        Log::info('開始執行股票資料爬蟲', [
            'date' => $this->date,
            'symbol' => $this->symbol ?? '全部'
        ]);

        try {
            // 檢查是否為交易日
            if (!$this->isTradingDay($this->date)) {
                Log::info('非交易日，跳過執行', ['date' => $this->date]);
                return;
            }

            if ($this->symbol) {
                // 單一股票
                $this->processSingleStock($twseApi, $this->symbol);
            } else {
                // 全部股票
                $this->processAllStocks($twseApi);
            }

        } catch (\Exception $e) {
            Log::error('股票資料爬蟲執行失敗', [
                'date' => $this->date,
                'symbol' => $this->symbol,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 處理單一股票
     */
    protected function processSingleStock(TwseApiService $twseApi, string $symbol)
    {
        DB::beginTransaction();

        try {
            // 取得股票資料
            $stockData = $twseApi->getStockDayAll($this->date);
            
            // 找到指定股票
            $data = $stockData->firstWhere('symbol', $symbol);

            if (!$data) {
                Log::warning('找不到股票資料', [
                    'symbol' => $symbol,
                    'date' => $this->date
                ]);
                DB::rollBack();
                return;
            }

            // 儲存資料
            $this->saveStockData([$data]);

            DB::commit();

            Log::info('股票資料爬蟲執行完成', [
                'symbol' => $symbol,
                'date' => $this->date
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 處理所有股票
     */
    protected function processAllStocks(TwseApiService $twseApi)
    {
        DB::beginTransaction();

        try {
            // 取得所有股票資料
            $stockData = $twseApi->getStockDayAll($this->date);

            if ($stockData->isEmpty()) {
                Log::warning('無股票資料', ['date' => $this->date]);
                DB::rollBack();
                return;
            }

            // 儲存資料
            $this->saveStockData($stockData->toArray());

            DB::commit();

            Log::info('股票資料爬蟲執行完成', [
                'date' => $this->date,
                'count' => $stockData->count()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * 儲存股票資料
     */
    protected function saveStockData(array $stockData)
    {
        $insertCount = 0;
        $updateCount = 0;

        foreach ($stockData as $data) {
            // 建立或更新股票
            $stock = Stock::updateOrCreate(
                ['symbol' => $data['symbol']],
                [
                    'name' => $data['name'],
                    'exchange' => 'TWSE',
                    'is_active' => true,
                ]
            );

            // 建立或更新價格
            $price = StockPrice::updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $this->date
                ],
                [
                    'open' => $data['open'],
                    'high' => $data['high'],
                    'low' => $data['low'],
                    'close' => $data['close'],
                    'volume' => $data['volume'],
                    'turnover' => $data['turnover'] ?? 0,
                    'change' => $data['change'] ?? 0,
                    'change_percent' => $this->calculateChangePercent($data),
                ]
            );

            if ($price->wasRecentlyCreated) {
                $insertCount++;
            } else {
                $updateCount++;
            }
        }

        Log::info('資料儲存完成', [
            'inserted' => $insertCount,
            'updated' => $updateCount
        ]);
    }

    /**
     * 計算漲跌百分比
     */
    protected function calculateChangePercent(array $data): float
    {
        $previousClose = $data['close'] - ($data['change'] ?? 0);
        
        if ($previousClose > 0) {
            return round((($data['change'] ?? 0) / $previousClose) * 100, 2);
        }
        
        return 0;
    }

    /**
     * 檢查是否為交易日
     */
    protected function isTradingDay(string $date): bool
    {
        $carbon = Carbon::parse($date);
        
        // 檢查是否為週末
        if ($carbon->isWeekend()) {
            return false;
        }

        // 檢查是否為國定假日
        $holidays = $this->getTaiwanHolidays($carbon->year);
        
        return !in_array($date, $holidays);
    }

    /**
     * 取得台灣國定假日
     */
    protected function getTaiwanHolidays(int $year): array
    {
        return [
            // 2025年國定假日
            "{$year}-01-01", // 元旦
            "{$year}-01-27", // 除夕
            "{$year}-01-28", // 春節
            "{$year}-01-29", // 春節
            "{$year}-01-30", // 春節
            "{$year}-02-28", // 和平紀念日
            "{$year}-04-04", // 兒童節
            "{$year}-04-05", // 清明節
            "{$year}-05-31", // 端午節
            "{$year}-10-10", // 國慶日
        ];
    }
}