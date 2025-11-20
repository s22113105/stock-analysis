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
            // 如果指定了股票代碼（補歷史模式），就不檢查交易日，直接嘗試抓取
            if (!$this->symbol && !$this->isTradingDay($this->date)) {
                return;
            }

            if ($this->symbol) {
                $this->processSingleStockHistory($twseApi, $this->symbol);
            } else {
                $this->processAllStocks($twseApi);
            }

        } catch (\Exception $e) {
            if (app()->runningInConsole()) {
                echo "❌ 錯誤: " . $e->getMessage() . "\n";
            }
            Log::error('爬蟲失敗', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    protected function processSingleStockHistory(TwseApiService $twseApi, string $symbol)
    {
        DB::beginTransaction();

        try {
            // 使用 getStockDay 抓取整個月資料
            $stockData = $twseApi->getStockDay($symbol, $this->date);
            
            if ($stockData->isEmpty()) {
                if (app()->runningInConsole()) {
                    // 判斷是否為當月
                    $targetMonth = Carbon::parse($this->date)->format('Y-m');
                    echo "⚠️  無資料: {$symbol} 在 {$targetMonth} 無交易紀錄 (可能是假日或 API 限制)\n";
                }
                DB::rollBack();
                return;
            }

            $this->saveStockData($stockData->toArray());

            DB::commit();

            if (app()->runningInConsole()) {
                $month = Carbon::parse($this->date)->format('Y-m');
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
                    // ✅ 關鍵修正: 確保這裡是單數 'transaction'，對應資料庫欄位
                    'transaction' => $data['transaction'] ?? 0,
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
        return ($previousClose > 0) ? round((($data['change'] ?? 0) / $previousClose) * 100, 2) : 0;
    }

    protected function isTradingDay(string $date): bool
    {
        return !Carbon::parse($date)->isWeekend();
    }
}