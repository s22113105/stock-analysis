<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * 選擇權鏈服務 (SQL 原生查詢版)
 * 直接對資料庫下指令，確保資料絕對能顯示
 */
class OptionChainService
{
    public function getOptionChain(?string $expiryDate = null): array
    {
        // 1. 取得所有有資料的到期日 (不限未來，歷史資料也要能看)
        $availableExpiries = DB::table('options')
            ->where('underlying', 'TXO')
            ->where('is_active', true)
            ->select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date')
            ->pluck('expiry_date');

        if ($availableExpiries->isEmpty()) {
            return ['error' => '資料庫 options 表中找不到 TXO 合約'];
        }

        // 2. 決定到期日 (預設選列表中的第一個)
        if (!$expiryDate || !$availableExpiries->contains($expiryDate)) {
            $expiryDate = $availableExpiries->first();
        }

        // 3. 找出全市場最新的交易日期 (確保一定有價格)
        $latestTradeDate = DB::table('option_prices')->max('trade_date');

        if (!$latestTradeDate) {
            return ['error' => '資料庫 option_prices 表是空的，請先匯入資料'];
        }

        // 4. 使用 SQL JOIN 直接查詢 (最穩定的方式)
        // 邏輯：拿出該到期日的所有合約，並 Left Join 最新日期的價格
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
            return ['error' => "找不到到期日為 {$expiryDate} 的合約資料"];
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
            if (isset($row->close) && $row->close > 0) {
                $displayPrice = (float)$row->close;
            } elseif (isset($row->settlement) && $row->settlement > 0) {
                $displayPrice = (float)$row->settlement;
            }

            $data = [
                'id' => $row->id,
                'code' => $row->option_code,
                'price' => $displayPrice, // 統一用這個欄位顯示
                'display_price' => $displayPrice,
                'volume' => (int)($row->volume ?? 0),
                'oi' => (int)($row->oi ?? 0),
                'iv' => (float)($row->iv ?? 0),
                'delta' => (float)($row->delta ?? 0),
            ];

            if (!isset($chain[$strike])) {
                $chain[$strike] = ['strike' => $strike, 'call' => null, 'put' => null];
            }
            $chain[$strike][$type] = $data;

            // 計算 ATM (只用有價格的來算)
            if (isset($chain[$strike]['call']) && isset($chain[$strike]['put'])) {
                $callP = $chain[$strike]['call']['price'];
                $putP = $chain[$strike]['put']['price'];

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
