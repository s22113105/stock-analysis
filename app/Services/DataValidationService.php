<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use App\Jobs\FetchStockDataJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DataValidationService
{
    /**
     * 檢查資料完整性
     */
    public function checkDataCompleteness($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');
        $missing = [];

        // 取得所有活躍的股票
        $activeStocks = Stock::where('is_active', true)->get();

        foreach ($activeStocks as $stock) {
            // 檢查是否有當日價格資料
            $hasPrice = StockPrice::where('stock_id', $stock->id)
                ->where('trade_date', $date)
                ->exists();

            if (!$hasPrice) {
                $missing[] = [
                    'stock_id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'type' => 'price_data'
                ];
            }
        }

        return [
            'date' => $date,
            'total_stocks' => $activeStocks->count(),
            'missing_count' => count($missing),
            'missing_data' => $missing
        ];
    }

    /**
     * 修復遺失的資料
     */
    public function fixMissingData($date = null)
    {
        $date = $date ?: now()->format('Y-m-d');

        $validation = $this->checkDataCompleteness($date);

        if ($validation['missing_count'] > 0) {
            Log::info('開始修復遺失資料', [
                'date' => $date,
                'missing_count' => $validation['missing_count']
            ]);

            // 觸發爬蟲任務
            dispatch(new FetchStockDataJob($date));

            return [
                'status' => 'processing',
                'message' => '已觸發資料修復任務'
            ];
        }

        return [
            'status' => 'complete',
            'message' => '資料完整，無需修復'
        ];
    }
}
