<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 選擇權鏈服務 (智慧日期版)
 * 修正問題：避免全市場最新日期與特定合約交易日不一致導致的資料空白
 */
class OptionChainService
{
    public function getOptionChain(?string $expiryDate = null): array
    {
        // 1. 取得所有可用的到期日 (從 options 表找)
        $availableExpiries = DB::table('options')
            ->where('underlying', 'TXO')
            ->where('is_active', true)
            ->select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date')
            ->pluck('expiry_date');

        if ($availableExpiries->isEmpty()) {
            return ['error' => '資料庫中找不到任何 TXO 合約，請先執行匯入指令'];
        }

        // 2. 決定到期日
        if (!$expiryDate || !$availableExpiries->contains($expiryDate)) {
            $expiryDate = $availableExpiries->first();
        }

        // 3. [關鍵修正] 針對「這個到期日」，找出它最新的交易日期
        // 不要用全域的 max('trade_date')，因為可能別的合約有更新的日期，導致這裡抓不到
        $latestTradeDate = DB::table('option_prices')
            ->join('options', 'option_prices.option_id', '=', 'options.id')
            ->where('options.expiry_date', $expiryDate)
            ->where('options.underlying', 'TXO')
            ->max('option_prices.trade_date');

        // 如果該合約完全沒資料，才使用全域最新日期當備案
        if (!$latestTradeDate) {
            $latestTradeDate = DB::table('option_prices')->max('trade_date');
        }

        if (!$latestTradeDate) {
            return ['error' => "資料庫有合約但無價格資料，請執行: php artisan import:taifex"];
        }

        // 4. 使用 SQL JOIN 直接查詢
        $rows = DB::table('options as o')
            ->leftJoin('option_prices as p', function ($join) use ($latestTradeDate) {
                $join->on('o.id', '=', 'p.option_id')
                    ->where('p.trade_date', '=', $latestTradeDate);
            })
            ->where('o.expiry_date', $expiryDate)
            ->where('o.underlying', 'TXO')
            ->select([
                'o.id',
                'o.option_code',
                'o.strike_price',
                'o.option_type',
                'p.close',
                'p.settlement',
                'p.volume',
                'p.open_interest as oi',
                'p.implied_volatility as iv',
                'p.delta'
            ])
            ->orderBy('o.strike_price')
            ->get();

        // 5. 組裝 T 字結構
        $chain = [];
        $atmStrike = 0;
        $minDiff = PHP_FLOAT_MAX;

        foreach ($rows as $row) {
            $strike = (int)$row->strike_price;
            $type = $row->option_type;

            // 價格顯示邏輯：優先顯示結算價(settlement)，因為它是最穩定的數據，其次收盤價(close)
            $displayPrice = 0;
            if ($row->settlement > 0) {
                $displayPrice = $row->settlement;
            } elseif ($row->close > 0) {
                $displayPrice = $row->close;
            }

            $data = [
                'id' => $row->id,
                'code' => $row->option_code,
                'price' => $displayPrice, // 這裡統一用 displayPrice
                'display_price' => $displayPrice,
                'volume' => (int)$row->volume,
                'oi' => (int)$row->oi,
                'iv' => (float)$row->iv,
                'delta' => (float)$row->delta,
            ];

            if (!isset($chain[$strike])) {
                $chain[$strike] = ['strike' => $strike, 'call' => null, 'put' => null];
            }
            $chain[$strike][$type] = $data;

            // 計算 ATM
            if (isset($chain[$strike]['call']) && isset($chain[$strike]['put'])) {
                $callP = $chain[$strike]['call']['display_price'];
                $putP = $chain[$strike]['put']['display_price'];

                // 只有當兩邊都有價格時才計算價差
                if ($callP > 0 && $putP > 0) {
                    $diff = abs($callP - $putP);
                    if ($diff < $minDiff) {
                        $minDiff = $diff;
                        $atmStrike = $strike;
                    }
                }
            }
        }

        // 6. 標記價內外
        foreach ($chain as $strike => &$row) {
            $row['is_atm'] = ($atmStrike > 0 && $strike === $atmStrike);

            if ($row['call']) {
                $row['call']['is_itm'] = ($atmStrike > 0 && $strike < $atmStrike);
            }
            if ($row['put']) {
                $row['put']['is_itm'] = ($atmStrike > 0 && $strike > $atmStrike);
            }
        }

        ksort($chain);

        return [
            'expiry_date' => $expiryDate,
            'trade_date' => $latestTradeDate,
            'available_expiries' => $availableExpiries,
            'atm_strike' => $atmStrike,
            'chain' => array_values($chain),
        ];
    }
}
