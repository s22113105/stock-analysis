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
        // ... (保留原本的 Log)

        try {
            if (!$this->symbol && !$this->isTradingDay($this->date)) {
                return;
            }

            if ($this->symbol) {
                $this->processSingleStockHistory($twseApi, $this->symbol);
            } else {
                $this->processAllStocks($twseApi);
            }

        } catch (\Exception $e) {
            // ✅ 新增: 發生錯誤時明確輸出
            if (app()->runningInConsole()) {
                echo "❌ 錯誤: " . $e->getMessage() . "\n";
            }
            Log::error('股票資料爬蟲執行失敗', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    protected function processSingleStockHistory(TwseApiService $twseApi, string $symbol)
    {
        DB::beginTransaction();

        try {
            $stockData = $twseApi->getStockDay($symbol, $this->date);
            
            if ($stockData->isEmpty()) {
                Log::warning('找不到股票歷史資料', ['symbol' => $symbol, 'date' => $this->date]);
                
                // ✅ 新增: 查無資料時輸出警告
                if (app()->runningInConsole()) {
                    echo "⚠️  查無資料: {$symbol} 在 " . Carbon::parse($this->date)->format('Y-m') . " 無交易紀錄\n";
                }
                
                DB::rollBack();
                return;
            }

            $this->saveStockData($stockData->toArray());

            DB::commit();

            $month = Carbon::parse($this->date)->format('Y-m');
            Log::info('股票歷史資料更新完成', ['symbol' => $symbol]);
            
            // ✅ 新增: 成功時明確輸出關鍵字
            if (app()->runningInConsole()) {
                echo "✅ 成功更新: {$symbol} {$month} 月份資料 (共 {$stockData->count()} 筆)\n";
            }

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function processAllStocks(TwseApiService $twseApi)
    {
        DB::beginTransaction();

        try {
            $stockData = $twseApi->getStockDayAll($this->date);

            if ($stockData->isEmpty()) {
                DB::rollBack();
                return;
            }

            $this->saveStockData($stockData->toArray());

            DB::commit();

            // ✅ 新增: 成功時明確輸出
            if (app()->runningInConsole()) {
                echo "✅ 成功更新: {$this->date} 全市場資料 (共 {$stockData->count()} 筆)\n";
            }

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function saveStockData(array $stockData)
    {
        foreach ($stockData as $data) {
            $stock = Stock::updateOrCreate(
                ['symbol' => $data['symbol']],
                [
                    'name' => $data['name'],
                    'exchange' => 'TWSE',
                    'is_active' => true,
                ]
            );

            StockPrice::updateOrCreate(
                [
                    'stock_id' => $stock->id,
                    'trade_date' => $data['trade_date']
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
                    'transactions' => $data['transaction'] ?? 0,
                ]
            );
        }
    }

    protected function calculateChangePercent(array $data): float
    {
        if (isset($data['change_percent']) && $data['change_percent'] != 0) {
            return $data['change_percent'];
        }
        $previousClose = $data['close'] - ($data['change'] ?? 0);
        if ($previousClose > 0) {
            return round((($data['change'] ?? 0) / $previousClose) * 100, 2);
        }
        return 0;
    }

    protected function isTradingDay(string $date): bool
    {
        return !Carbon::parse($date)->isWeekend();
    }
}