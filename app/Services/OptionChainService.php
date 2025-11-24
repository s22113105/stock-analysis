<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 選擇權鏈服務 - 改進版本
 * 確保資料能夠正確讀取並顯示
 */
class OptionChainService
{
    /**
     * 取得選擇權 T 字報價表
     *
     * @param string|null $expiryDate 指定到期日
     * @return array
     */
    public function getOptionChain(?string $expiryDate = null): array
    {
        try {
            // 1. 先確認資料庫中是否有選擇權資料
            $hasOptions = DB::table('options')->exists();
            if (!$hasOptions) {
                Log::warning('資料庫中沒有選擇權資料');
                return $this->emptyResponse('資料庫中沒有選擇權資料，請先執行資料爬蟲');
            }

            // 2. 取得所有可用的到期日（確保有價格資料）
            $availableExpiries = DB::table('options as o')
                ->join('option_prices as p', 'o.id', '=', 'p.option_id')
                ->where('o.underlying', 'TXO')
                ->where('o.is_active', true)
                ->select('o.expiry_date')
                ->distinct()
                ->orderBy('o.expiry_date', 'asc')
                ->pluck('expiry_date')
                ->map(function ($date) {
                    // 確保日期格式一致
                    return Carbon::parse($date)->format('Y-m-d');
                })
                ->unique()
                ->values();

            Log::info('可用到期日', ['expiries' => $availableExpiries->toArray()]);

            if ($availableExpiries->isEmpty()) {
                return $this->emptyResponse('沒有找到有價格資料的選擇權合約');
            }

            // 3. 決定要查詢的到期日
            if (empty($expiryDate) || !$availableExpiries->contains($expiryDate)) {
                // 預設選擇最近的到期日
                $today = Carbon::now()->format('Y-m-d');
                $expiryDate = $availableExpiries->first(function ($date) use ($today) {
                    return $date >= $today;
                }) ?: $availableExpiries->first();
            }

            Log::info('查詢到期日', ['expiry_date' => $expiryDate]);

            // 4. 取得最新的交易日期
            $latestTradeDate = DB::table('option_prices')->max('trade_date');
            if (!$latestTradeDate) {
                return $this->emptyResponse('選擇權價格資料表是空的');
            }

            Log::info('最新交易日', ['trade_date' => $latestTradeDate]);

            // 5. 查詢選擇權資料（使用子查詢確保效能）
            $options = DB::table('options as o')
                ->leftJoin('option_prices as p', function ($join) use ($latestTradeDate) {
                    $join->on('o.id', '=', 'p.option_id')
                        ->where('p.trade_date', '=', $latestTradeDate);
                })
                ->where('o.underlying', 'TXO')
                ->where('o.expiry_date', $expiryDate)
                ->where('o.is_active', true)
                ->select([
                    'o.id',
                    'o.option_code',
                    'o.strike_price',
                    'o.option_type',
                    'o.expiry_date',
                    // 價格資料
                    'p.open',
                    'p.high',
                    'p.low',
                    'p.close',
                    'p.volume',
                    'p.open_interest',
                    'p.implied_volatility',
                    // Greeks
                    'p.delta',
                    'p.gamma',
                    'p.theta',
                    'p.vega',
                    'p.rho'
                ])
                ->orderBy('o.strike_price', 'asc')
                ->orderBy('o.option_type', 'asc')
                ->get();

            Log::info('查詢結果數量', ['count' => $options->count()]);

            if ($options->isEmpty()) {
                return $this->emptyResponse("沒有找到到期日 {$expiryDate} 的選擇權資料");
            }

            // 6. 取得當前指數價格（用於判斷 ATM）
            $spotPrice = $this->getCurrentSpotPrice();
            Log::info('當前指數價格', ['spot_price' => $spotPrice]);

            // 7. 組裝 T 字報價結構
            $chainData = [];
            $strikes = $options->pluck('strike_price')->unique()->sort()->values();

            // 找出最接近現貨價格的履約價（ATM）
            $atmStrike = $this->findATMStrike($strikes, $spotPrice);

            foreach ($strikes as $strike) {
                $callOption = $options->firstWhere(function ($item) use ($strike) {
                    return $item->strike_price == $strike && strtoupper($item->option_type) == 'CALL';
                });

                $putOption = $options->firstWhere(function ($item) use ($strike) {
                    return $item->strike_price == $strike && strtoupper($item->option_type) == 'PUT';
                });

                // 組裝每個履約價的資料
                $chainData[] = [
                    'strike' => intval($strike),
                    'is_atm' => intval($strike) == $atmStrike,
                    'call' => $this->formatOptionData($callOption, $spotPrice, 'CALL'),
                    'put' => $this->formatOptionData($putOption, $spotPrice, 'PUT')
                ];
            }

            // 8. 回傳完整資料
            return [
                'success' => true,
                'chain' => $chainData,
                'available_expiries' => $availableExpiries->toArray(),
                'expiry_date' => $expiryDate,
                'trade_date' => $latestTradeDate,
                'atm_strike' => $atmStrike,
                'spot_price' => $spotPrice,
                'total_strikes' => count($chainData)
            ];
        } catch (\Exception $e) {
            Log::error('OptionChainService 錯誤', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->emptyResponse('系統錯誤: ' . $e->getMessage());
        }
    }

    /**
     * 格式化單個選擇權資料
     */
    private function formatOptionData($option, $spotPrice, $type): ?array
    {
        if (!$option) {
            return null;
        }

        $strike = floatval($option->strike_price);
        $isCall = strtoupper($type) == 'CALL';

        // 判斷是否價內 (ITM)
        $isITM = $isCall ? ($spotPrice > $strike) : ($spotPrice < $strike);

        // 決定顯示價格（優先順序：收盤價 > 最高價 > 開盤價）
        $displayPrice = $option->close ?? $option->high ?? $option->open ?? 0;

        return [
            'id' => $option->id,
            'code' => $option->option_code,
            'price' => floatval($displayPrice),
            'volume' => intval($option->volume ?? 0),
            'oi' => intval($option->open_interest ?? 0),
            'iv' => floatval($option->implied_volatility ?? 0) * 100, // 轉為百分比
            'delta' => floatval($option->delta ?? 0),
            'gamma' => floatval($option->gamma ?? 0),
            'theta' => floatval($option->theta ?? 0),
            'vega' => floatval($option->vega ?? 0),
            'is_itm' => $isITM
        ];
    }

    /**
     * 取得當前指數價格
     * 可以從股票資料表取得加權指數，或使用固定值測試
     */
    private function getCurrentSpotPrice(): float
    {
        // 嘗試從股票資料表取得大盤指數
        $indexPrice = DB::table('stocks as s')
            ->join('stock_prices as sp', 's.id', '=', 'sp.stock_id')
            ->where('s.symbol', 'TAIEX') // 或其他代表大盤的代碼
            ->orWhere('s.symbol', '^TWII')
            ->orderBy('sp.trade_date', 'desc')
            ->value('sp.close');

        if ($indexPrice) {
            return floatval($indexPrice);
        }

        // 如果沒有指數資料，使用預設值或計算平均
        // 這裡使用 18000 作為預設值（可根據實際情況調整）
        return 18000.0;
    }

    /**
     * 找出最接近現貨價格的履約價 (ATM)
     */
    private function findATMStrike($strikes, $spotPrice): int
    {
        if ($strikes->isEmpty()) {
            return 0;
        }

        $minDiff = PHP_FLOAT_MAX;
        $atmStrike = $strikes->first();

        foreach ($strikes as $strike) {
            $diff = abs(floatval($strike) - $spotPrice);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $atmStrike = $strike;
            }
        }

        return intval($atmStrike);
    }

    /**
     * 回傳空資料結構
     */
    private function emptyResponse($message = ''): array
    {
        return [
            'success' => false,
            'chain' => [],
            'available_expiries' => [],
            'expiry_date' => null,
            'trade_date' => null,
            'atm_strike' => 0,
            'spot_price' => 0,
            'message' => $message
        ];
    }

    /**
     * 測試資料庫連接和資料
     * 可用於除錯
     */
    public function testDatabaseConnection(): array
    {
        try {
            $stats = [
                'options_count' => DB::table('options')->count(),
                'option_prices_count' => DB::table('option_prices')->count(),
                'tco_options' => DB::table('options')->where('underlying', 'TXO')->count(),
                'latest_price_date' => DB::table('option_prices')->max('trade_date'),
                'expiry_dates' => DB::table('options')
                    ->where('underlying', 'TXO')
                    ->distinct()
                    ->pluck('expiry_date')
                    ->sort()
                    ->values()
                    ->toArray()
            ];

            return [
                'success' => true,
                'stats' => $stats
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
