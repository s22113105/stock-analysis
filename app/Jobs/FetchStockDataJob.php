<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Services\TwseApiService;
use App\Events\StockDataUpdated;
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
        Log::info('開始執行股票資料爬蟲', [
            'date' => $this->date,
            'symbol' => $this->symbol
        ]);

        try {
            DB::beginTransaction();

            if ($this->symbol) {
                $this->updateSingleStock($twseApi);
            } else {
                $this->updateAllStocks($twseApi);
            }

            DB::commit();

            // 觸發事件
            event(new StockDataUpdated($this->date, $this->symbol));

            Log::info('股票資料爬蟲執行完成');
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('股票資料爬蟲執行失敗', [
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * 更新單一股票
     */
    protected function updateSingleStock(TwseApiService $twseApi)
    {
        $stock = Stock::where('symbol', $this->symbol)->firstOrFail();

        $data = $twseApi->getStockDay($this->symbol, $this->date);

        if (!empty($data)) {
            $this->saveStockPrice($stock, $data);
        }
    }

    /**
     * 更新所有股票
     */
    protected function updateAllStocks(TwseApiService $twseApi)
    {
        $stockData = $twseApi->getStockDayAll($this->date);

        foreach ($stockData as $data) {
            $stock = Stock::where('symbol', $data['symbol'])->first();

            if (!$stock) {
                $stock = Stock::create([
                    'symbol' => $data['symbol'],
                    'name' => $data['name'],
                    'is_active' => true,
                ]);
            }

            $this->saveStockPrice($stock, $data);
        }
    }

    /**
     * 儲存股價資料
     */
    protected function saveStockPrice(Stock $stock, array $data)
    {
        StockPrice::updateOrCreate(
            [
                'stock_id' => $stock->id,
                'trade_date' => $this->date
            ],
            [
                'open_price' => $data['open'] ?? null,
                'high_price' => $data['high'] ?? null,
                'low_price' => $data['low'] ?? null,
                'close_price' => $data['close'] ?? null,
                'volume' => $data['volume'] ?? 0,
                'turnover' => $data['turnover'] ?? 0,
                'change' => $data['change'] ?? 0,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }
}
