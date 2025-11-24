<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 選擇權鏈服務 (SQL JOIN 版)
 * 直接使用 SQL 查詢，繞過模型關聯可能的問題
 */
class OptionChainService
{
    public function getOptionChain(?string $expiryDate = null): array
    {
        // 1. 取得所有可用的到期日
        $availableExpiries = DB::table('options')
            ->where('underlying', 'TXO')
            ->where('is_active', true)
            ->select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date')
            ->pluck('expiry_date');

        if ($availableExpiries->isEmpty()) {
            return ['error' => '資料庫中找不到任何 TXO 合約 (options 表為空)'];
        }

        // 2. 決定到期日
        if (!$expiryDate || !$availableExpiries->contains($expiryDate)) {
            $expiryDate = $availableExpiries->first();
        }

        // 3. 找出全市場最新的交易日期
        $latestTradeDate = DB::table('option_prices')->max('trade_date');

        if (!$latestTradeDate) {
            // 如果價格表全空
            return ['error' => '資料庫中沒有任何價格資料 (option_prices 表為空)'];
        }

        // 4. 使用 SQL JOIN 直接查詢 (最穩定的方式)
        // 邏輯：拿出該到期日的所有合約，並嘗試對應最新日期的價格
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
                'p.settlement', // 確保讀取結算價
                'p.volume',
                'p.open_interest as oi',
                'p.implied_volatility as iv',
                'p.delta'
            ])
            ->orderBy('o.strike_price')
            ->get();

        if ($rows->isEmpty()) {
            return ['error' => "找不到 {$expiryDate} 到期的合約資料"];
        }

        // 5. 組裝 T 字結構
        $chain = [];
        $atmStrike = 0;
        $minDiff = PHP_FLOAT_MAX;

        foreach ($rows as $row) {
            $strike = (int)$row->strike_price;
            $type = $row->option_type; // 'call' or 'put'

            // 價格顯示邏輯：優先 Close > Settlement > 0
            $displayPrice = 0;
            if ($row->close > 0) {
                $displayPrice = $row->close;
            } elseif ($row->settlement > 0) {
                $displayPrice = $row->settlement;
            }

            $data = [
                'id' => $row->id,
                'code' => $row->option_code,
                'price' => $row->close,
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
            'trade_date' => $latestTradeDate, // 直接使用資料庫抓到的日期字串
            'available_expiries' => $availableExpiries,
            'atm_strike' => $atmStrike,
            'chain' => array_values($chain),
        ];
    }
}
