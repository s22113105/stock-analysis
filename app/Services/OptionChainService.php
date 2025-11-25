<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Services\BlackScholesService;

class OptionChainService
{
    protected $blackScholesService;

    public function __construct(BlackScholesService $blackScholesService)
    {
        $this->blackScholesService = $blackScholesService;
    }

    public function getOptionChain(?string $expiryDate = null): array
    {
        try {
            // 1. 檢查是否有選擇權資料
            $hasOptions = DB::table('options')->exists();
            if (!$hasOptions) {
                return $this->emptyResponse('資料庫中沒有選擇權資料');
            }

            // 2. 取得所有可用的到期日
            $availableExpiries = DB::table('options')
                ->where('underlying', 'TXO')
                ->where('is_active', true)
                ->select('expiry_date')
                ->distinct()
                ->orderBy('expiry_date')
                ->pluck('expiry_date')
                ->map(function ($date) {
                    return Carbon::parse($date)->format('Y-m-d');
                })
                ->unique()
                ->values()
                ->toArray();

            if (empty($availableExpiries)) {
                return $this->emptyResponse('沒有可用的到期日');
            }

            // 3. 決定要查詢的到期日
            if (empty($expiryDate) || !in_array($expiryDate, $availableExpiries)) {
                $expiryDate = $availableExpiries[0];
            }

            // 4. 取得最新的交易日期
            $latestTradeDate = DB::table('option_prices')->max('trade_date');
            if (!$latestTradeDate) {
                return $this->emptyResponse('沒有價格資料');
            }

            // 5. 取得現貨價格（大盤指數）
            $spotPrice = $this->getCurrentSpotPrice();

            // 6. 計算到期時間（年）
            $now = Carbon::parse($latestTradeDate);
            $expiry = Carbon::parse($expiryDate);
            $daysToExpiry = max(1, $now->diffInDays($expiry));
            $timeToExpiry = $daysToExpiry / 365.0;

            // 7. 無風險利率（台灣約 1.5%）
            $riskFreeRate = 0.015;

            // 8. 查詢選擇權資料
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
                    'p.close',
                    'p.settlement',
                    'p.volume',
                    'p.open_interest',
                    'p.implied_volatility',
                    'p.delta',
                    'p.gamma',
                    'p.theta',
                    'p.vega'
                ])
                ->orderBy('o.strike_price')
                ->get();

            if ($options->isEmpty()) {
                return $this->emptyResponse("沒有找到到期日 {$expiryDate} 的資料");
            }

            // 9. 組裝 T 字報價結構
            $chainData = [];
            $strikes = $options->pluck('strike_price')->unique()->sort()->values();
            $atmStrike = $this->findATMStrike($strikes, $spotPrice);

            foreach ($strikes as $strike) {
                $callOption = null;
                $putOption = null;

                foreach ($options as $opt) {
                    if ($opt->strike_price == $strike) {
                        if (strtolower($opt->option_type) == 'call') {
                            $callOption = $opt;
                        } else {
                            $putOption = $opt;
                        }
                    }
                }

                $chainData[] = [
                    'strike' => intval($strike),
                    'is_atm' => intval($strike) == $atmStrike,
                    'call' => $this->formatOptionDataWithGreeks(
                        $callOption,
                        $spotPrice,
                        $strike,
                        $timeToExpiry,
                        $riskFreeRate,
                        'call'
                    ),
                    'put' => $this->formatOptionDataWithGreeks(
                        $putOption,
                        $spotPrice,
                        $strike,
                        $timeToExpiry,
                        $riskFreeRate,
                        'put'
                    )
                ];
            }

            return [
                'success' => true,
                'chain' => $chainData,
                'available_expiries' => $availableExpiries,
                'expiry_date' => $expiryDate,
                'trade_date' => $latestTradeDate,
                'atm_strike' => $atmStrike,
                'spot_price' => $spotPrice,
                'days_to_expiry' => $daysToExpiry,
                'total_strikes' => count($chainData)
            ];
        } catch (\Exception $e) {
            Log::error('OptionChainService 錯誤: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->emptyResponse('系統錯誤: ' . $e->getMessage());
        }
    }

    /**
     * 格式化選擇權資料並計算 Greeks
     */
    private function formatOptionDataWithGreeks(
        $option,
        float $spotPrice,
        float $strike,
        float $timeToExpiry,
        float $riskFreeRate,
        string $type
    ): ?array {
        if (!$option) {
            return null;
        }

        $isCall = strtolower($type) == 'call';
        $isITM = $isCall ? ($spotPrice > $strike) : ($spotPrice < $strike);

        // 取得市場價格
        $marketPrice = floatval($option->close ?? $option->settlement ?? 0);

        // 預設值
        $delta = floatval($option->delta ?? 0);
        $gamma = floatval($option->gamma ?? 0);
        $theta = floatval($option->theta ?? 0);
        $vega = floatval($option->vega ?? 0);
        $iv = floatval($option->implied_volatility ?? 0);

        // 如果有市場價格且 Greeks 為空，則計算
        if ($marketPrice > 0 && $timeToExpiry > 0) {
            try {
                // 計算隱含波動率（如果沒有）
                if ($iv <= 0) {
                    $calculatedIV = $this->blackScholesService->calculateImpliedVolatility(
                        $marketPrice,
                        $spotPrice,
                        $strike,
                        $timeToExpiry,
                        $riskFreeRate,
                        $type
                    );
                    if ($calculatedIV !== null && $calculatedIV > 0) {
                        $iv = $calculatedIV;
                    }
                }

                // 使用 IV 計算 Greeks（如果 delta 為 0）
                if ($delta == 0 && $iv > 0) {
                    $greeks = $this->blackScholesService->calculateGreeks(
                        $spotPrice,
                        $strike,
                        $timeToExpiry,
                        $riskFreeRate,
                        $iv,
                        $type
                    );

                    $delta = $greeks['delta'] ?? 0;
                    $gamma = $greeks['gamma'] ?? 0;
                    $theta = $greeks['theta'] ?? 0;
                    $vega = $greeks['vega'] ?? 0;
                }
            } catch (\Exception $e) {
                // 計算失敗，使用預設值
                Log::debug('Greeks 計算失敗: ' . $e->getMessage());
            }
        }

        return [
            'id' => $option->id,
            'code' => $option->option_code,
            'price' => $marketPrice,
            'volume' => intval($option->volume ?? 0),
            'oi' => intval($option->open_interest ?? 0),
            'iv' => round($iv * 100, 2),  // 轉為百分比
            'delta' => round($delta, 4),
            'gamma' => round($gamma, 6),
            'theta' => round($theta, 4),
            'vega' => round($vega, 4),
            'is_itm' => $isITM
        ];
    }

    /**
     * 取得現貨價格（大盤指數）
     */
    private function getCurrentSpotPrice(): float
    {
        // 從 TXO 選擇權的 ATM 履約價推算
        $atmPrice = DB::table('options as o')
            ->join('option_prices as p', 'o.id', '=', 'p.option_id')
            ->where('o.underlying', 'TXO')
            ->where('p.volume', '>', 0)
            ->orderBy('p.trade_date', 'desc')
            ->orderBy('p.volume', 'desc')
            ->value('o.strike_price');

        if ($atmPrice) {
            return floatval($atmPrice);
        }

        // 預設值
        return 23000.0;
    }

    /**
     * 找出 ATM 履約價
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
     * 空回應
     */
    private function emptyResponse(string $message = ''): array
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
}
