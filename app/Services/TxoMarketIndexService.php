<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TXO 市場指數服務
 * 修正版本 - 正確取得最新資料
 */
class TxoMarketIndexService
{
    /**
     * 計算 TXO 市場每日加權平均價格指數
     *
     * @param int $days 需要的歷史天數
     * @return array 歷史價格陣列,按日期升序排列
     */
    public function getHistoricalIndexForPrediction(int $days = 200): array
    {
        try {
            Log::info('開始計算 TXO 市場指數', ['days' => $days]);

            // ✅ 修正:使用子查詢先取得最新的 N 天,然後按日期升序排列
            $indexData = DB::select("
                SELECT *
                FROM (
                    SELECT
                        CAST(trade_date AS CHAR) as date,
                        CAST(SUM(close * volume) / NULLIF(SUM(volume), 0) AS DECIMAL(10,2)) as weighted_price,
                        CAST(AVG(close) AS DECIMAL(10,2)) as avg_price,
                        CAST(SUM(volume) AS UNSIGNED) as total_volume
                    FROM option_prices
                    WHERE option_id IN (
                        SELECT id
                        FROM options
                        WHERE underlying = 'TXO'
                    )
                    AND close IS NOT NULL
                    AND close > 0
                    AND volume IS NOT NULL
                    AND volume > 0
                    GROUP BY trade_date
                    ORDER BY trade_date DESC
                    LIMIT ?
                ) as latest_data
                ORDER BY date ASC
            ", [$days]);

            // 清理和轉換資料
            $result = [];
            foreach ($indexData as $row) {
                $price = (float) ($row->weighted_price ?? $row->avg_price ?? 0);

                // 確保所有字串都是有效的 UTF-8
                $date = (string) $row->date;
                $date = mb_convert_encoding($date, 'UTF-8', 'UTF-8');

                $result[] = [
                    'date' => $date,
                    'close' => round($price, 2),
                    'open' => round($price, 2),
                    'high' => round($price, 2),
                    'low' => round($price, 2),
                    'volume' => (int) ($row->total_volume ?? 0),
                ];
            }

            Log::info('TXO 市場指數計算完成', [
                'data_points' => count($result),
                'date_range' => count($result) > 0 ? [
                    'from' => $result[0]['date'],
                    'to' => end($result)['date']
                ] : null
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('TXO 市場指數計算失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [];
        }
    }
}
