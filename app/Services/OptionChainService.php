<?php

namespace App\Services;

use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 選擇權鏈服務 (Option Chain Service)
 * 專門負責產生 T 字報價表數據，不包含預測模型
 */
class OptionChainService
{
    /**
     * 取得 T 字報價表數據
     *
     * @param string|null $expiryDate 指定到期日
     * @return array
     */
    public function getOptionChain(?string $expiryDate = null): array
    {
        // 1. 取得所有可用的到期日 (用於前端下拉選單)
        // 只取大於等於今天的到期日
        $availableExpiries = Option::where('underlying', 'TXO')
            ->where('is_active', true)
            ->where('expiry_date', '>=', now()->format('Y-m-d'))
            ->distinct()
            ->orderBy('expiry_date')
            ->pluck('expiry_date');

        // 2. 決定要查詢的到期日 (預設為最近的一個)
        if (!$expiryDate || !$availableExpiries->contains($expiryDate)) {
            $expiryDate = $availableExpiries->first();
        }

        if (!$expiryDate) {
            return ['error' => '目前沒有可交易的合約'];
        }

        // 3. 找出資料庫中最新的交易日期 (確保不抓到空資料)
        $latestTradeDate = OptionPrice::max('trade_date');

        // 4. 查詢該到期日的所有合約與最新價格
        // 使用 Eager Loading 預加載最新價格，提升效能
        $options = Option::with(['latestPrice' => function ($query) use ($latestTradeDate) {
            if ($latestTradeDate) {
                $query->where('trade_date', $latestTradeDate);
            }
        }])
            ->where('expiry_date', $expiryDate)
            ->where('underlying', 'TXO')
            ->orderBy('strike_price')
            ->get();

        // 5. 組裝 T 字結構
        // 結構: [ 履約價 => [ 'strike' => 10000, 'call' => {...}, 'put' => {...} ] ]
        $chain = [];
        $atmStrike = 0; // 預估價平履約價
        $minDiff = PHP_FLOAT_MAX;

        foreach ($options as $option) {
            $strike = (int)$option->strike_price;
            $type = $option->option_type; // 'call' or 'put'
            $price = $option->latestPrice;

            // 單邊資料包
            $data = [
                'id' => $option->id,
                'code' => $option->option_code,
                // 優先使用收盤價，若無則使用結算價
                'price' => $price ? ($price->close ?? $price->settlement) : null,
                'change' => 0, // 若有昨日收盤價可計算漲跌
                'volume' => $price ? (int)$price->volume : 0,
                'oi' => $price ? (int)$price->open_interest : 0,
                'iv' => $price ? (float)$price->implied_volatility : null,
                'delta' => $price ? (float)$price->delta : null,
                'settlement' => $price ? (float)$price->settlement : null,
            ];

            if (!isset($chain[$strike])) {
                $chain[$strike] = ['strike' => $strike, 'call' => null, 'put' => null];
            }
            $chain[$strike][$type] = $data;

            // 估算 ATM (價平) 履約價：Call 和 Put 價格差距最小的那個履約價
            // 原理：在價平附近，Call 和 Put 的時間價值最接近
            if (isset($chain[$strike]['call']) && isset($chain[$strike]['put'])) {
                $callPrice = $chain[$strike]['call']['price'] ?? 0;
                $putPrice = $chain[$strike]['put']['price'] ?? 0;

                if ($callPrice > 0 && $putPrice > 0) {
                    $diff = abs($callPrice - $putPrice);
                    if ($diff < $minDiff) {
                        $minDiff = $diff;
                        $atmStrike = $strike;
                    }
                }
            }
        }

        // 6. 標記價內 (ITM) 狀態
        // Call: 履約價 < ATM 為價內 (In-The-Money)
        // Put: 履約價 > ATM 為價內 (In-The-Money)
        foreach ($chain as $strike => &$row) {
            $row['is_atm'] = ($strike === $atmStrike);

            if ($row['call']) {
                $row['call']['is_itm'] = ($strike < $atmStrike);
            }
            if ($row['put']) {
                $row['put']['is_itm'] = ($strike > $atmStrike);
            }
        }

        // 按履約價排序
        ksort($chain);

        return [
            'expiry_date' => $expiryDate,
            'trade_date' => $latestTradeDate ? Carbon::parse($latestTradeDate)->format('Y-m-d') : '無資料',
            'available_expiries' => $availableExpiries,
            'atm_strike' => $atmStrike,
            'chain' => array_values($chain),
        ];
    }
}
