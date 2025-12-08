<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\BlackScholesService;
use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

/**
 * 波動率計算與分析控制器
 * 
 * @version 2.0 改進版
 * - 增強 marketIV 方法，返回股票價格
 */
class VolatilityController extends Controller
{
    protected $blackScholesService;

    public function __construct(BlackScholesService $blackScholesService)
    {
        $this->blackScholesService = $blackScholesService;
    }

    /**
     * 取得歷史波動率
     *
     * GET /api/volatility/historical/{stockId}
     */
    public function historical(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);
            $period = $request->input('period', 20);
            $endDate = $request->input('end_date', now()->format('Y-m-d'));
            $method = $request->input('method', 'close_to_close');

            // 快取 key
            $cacheKey = "volatility:historical:{$stockId}:{$period}:{$endDate}:{$method}";

            $result = Cache::remember($cacheKey, 300, function () use ($stock, $period, $endDate, $method) {
                // 取得價格資料
                $prices = StockPrice::where('stock_id', $stock->id)
                    ->where('trade_date', '<=', $endDate)
                    ->orderBy('trade_date', 'desc')
                    ->limit($period + 1)
                    ->get()
                    ->sortBy('trade_date')
                    ->values();

                if ($prices->count() < 2) {
                    return null;
                }

                // 計算對數報酬率
                $returns = [];
                for ($i = 1; $i < $prices->count(); $i++) {
                    $currentClose = $prices[$i]->close;
                    $previousClose = $prices[$i - 1]->close;

                    if ($previousClose > 0 && $currentClose > 0) {
                        $returns[] = log($currentClose / $previousClose);
                    }
                }

                if (empty($returns)) {
                    return null;
                }

                // 計算波動率
                $mean = array_sum($returns) / count($returns);
                $squaredDiffs = array_map(fn($r) => pow($r - $mean, 2), $returns);
                $variance = array_sum($squaredDiffs) / (count($squaredDiffs) - 1);
                $dailyVolatility = sqrt($variance);
                $annualizedVolatility = $dailyVolatility * sqrt(252);

                return [
                    'volatility' => round($annualizedVolatility, 6),
                    'volatility_percentage' => round($annualizedVolatility * 100, 2),
                    'daily_volatility' => round($dailyVolatility, 6),
                    'period' => $period,
                    'data_points' => count($returns),
                    'method' => $method,
                ];
            });

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => '資料不足，無法計算波動率'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => array_merge($result, [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'end_date' => $endDate,
                ])
            ]);

        } catch (\Exception $e) {
            Log::error('計算歷史波動率錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得市場隱含波動率 (從選擇權價格)
     * 
     * 改進：增加股票/期貨當前價格
     *
     * GET /api/volatility/market-iv/{stockId}
     */
    public function marketIV(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);

            // 快取 key
            $cacheKey = "volatility:market_iv:{$stockId}";

            $cachedData = Cache::get($cacheKey);
            if ($cachedData && !$request->has('force')) {
                return response()->json([
                    'success' => true,
                    'data' => $cachedData,
                    'cached' => true
                ]);
            }

            // 取得股票最新價格
            $latestPrice = StockPrice::where('stock_id', $stock->id)
                ->orderBy('trade_date', 'desc')
                ->first();

            $stockPrice = $latestPrice ? floatval($latestPrice->close) : null;
            $priceDate = $latestPrice ? $latestPrice->trade_date : null;

            // 結果初始化
            $result = [
                'stock' => [
                    'id' => $stock->id,
                    'symbol' => $stock->symbol,
                    'name' => $stock->name,
                    'current_price' => $stockPrice,  // 新增：當前價格
                    'price_date' => $priceDate,
                ],
                'stock_price' => $stockPrice,  // 為了兼容性也放在頂層
                'has_real_iv' => false,
                'real_iv' => null,
                'real_iv_percentage' => null,
                'iv_source' => null,
                'txo_iv' => null,
                'txo_iv_percentage' => null,
                'data_date' => null,
                'txo_info' => null,
            ];

            // 從 TXO 選擇權取得市場 IV
            $txoIV = $this->getTxoMarketIV();

            if ($txoIV) {
                $result['has_real_iv'] = true;
                $result['txo_iv'] = $txoIV['iv'];
                $result['txo_iv_percentage'] = round($txoIV['iv'] * 100, 2);
                $result['real_iv'] = $txoIV['iv'];
                $result['real_iv_percentage'] = round($txoIV['iv'] * 100, 2);
                $result['iv_source'] = 'txo';
                $result['data_date'] = $txoIV['date'];
                $result['txo_info'] = $txoIV['info'] ?? null;

                // 如果從 TXO 可以取得期貨價格，優先使用
                if (isset($txoIV['futures_price']) && $txoIV['futures_price'] > 0) {
                    $result['stock_price'] = $txoIV['futures_price'];
                    $result['stock']['current_price'] = $txoIV['futures_price'];
                }
            }

            // 如果還沒有價格，嘗試從選擇權推算
            if (!$result['stock_price']) {
                $estimatedPrice = $this->estimateFuturesPriceFromOptions();
                if ($estimatedPrice) {
                    $result['stock_price'] = $estimatedPrice;
                    $result['stock']['current_price'] = $estimatedPrice;
                    $result['stock']['price_source'] = 'estimated_from_options';
                }
            }

            // 快取 5 分鐘
            Cache::put($cacheKey, $result, 300);

            return response()->json([
                'success' => true,
                'data' => $result,
                'cached' => false
            ]);

        } catch (\Exception $e) {
            Log::error('取得市場 IV 錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => '取得市場 IV 失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 從 TXO 選擇權取得市場隱含波動率
     */
    private function getTxoMarketIV(): ?array
    {
        try {
            // 取得最新有 IV 資料的日期
            $latestDate = OptionPrice::whereHas('option', function ($query) {
                    $query->where('underlying', 'TXO');
                })
                ->whereNotNull('implied_volatility')
                ->where('implied_volatility', '>', 0)
                ->max('trade_date');

            if (!$latestDate) {
                return null;
            }

            // 取得該日期 ATM 附近的選擇權 IV
            $optionPrices = OptionPrice::whereHas('option', function ($query) {
                    $query->where('underlying', 'TXO')
                          ->where('expiry_date', '>=', now());
                })
                ->where('trade_date', $latestDate)
                ->whereNotNull('implied_volatility')
                ->where('implied_volatility', '>', 0.05)
                ->where('implied_volatility', '<', 1.5)
                ->with('option')
                ->get();

            if ($optionPrices->isEmpty()) {
                return null;
            }

            // 計算加權平均 IV（以成交量為權重）
            $totalVolume = 0;
            $weightedIV = 0;
            $futuresPrice = null;

            foreach ($optionPrices as $price) {
                $volume = $price->volume ?? 1;
                $iv = $price->implied_volatility;

                $weightedIV += $iv * $volume;
                $totalVolume += $volume;

                // 嘗試從 ATM 選擇權推算期貨價格
                if (!$futuresPrice && $price->option) {
                    $strike = $price->option->strike_price;
                    // ATM 選擇權的履約價約等於期貨價格
                    if ($iv >= 0.1 && $iv <= 0.3) {
                        $futuresPrice = $strike;
                    }
                }
            }

            $avgIV = $totalVolume > 0 ? $weightedIV / $totalVolume : null;

            if (!$avgIV) {
                return null;
            }

            return [
                'iv' => round($avgIV, 4),
                'date' => $latestDate,
                'futures_price' => $futuresPrice,
                'info' => [
                    'data_points' => $optionPrices->count(),
                    'total_volume' => $totalVolume,
                ]
            ];

        } catch (\Exception $e) {
            Log::warning('取得 TXO IV 失敗', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 從選擇權價格推算期貨價格
     */
    private function estimateFuturesPriceFromOptions(): ?float
    {
        try {
            // 找最接近 ATM 的選擇權（Call 和 Put 價格最接近的履約價）
            $latestDate = OptionPrice::max('trade_date');

            if (!$latestDate) {
                return null;
            }

            // 取得同一履約價的 Call 和 Put
            $options = Option::where('underlying', 'TXO')
                ->where('expiry_date', '>=', now())
                ->with(['latestPrice' => function ($query) use ($latestDate) {
                    $query->where('trade_date', $latestDate);
                }])
                ->get()
                ->groupBy('strike_price');

            $minDiff = PHP_FLOAT_MAX;
            $estimatedPrice = null;

            foreach ($options as $strike => $opts) {
                $call = $opts->where('option_type', 'call')->first();
                $put = $opts->where('option_type', 'put')->first();

                if ($call && $put && $call->latestPrice && $put->latestPrice) {
                    $callPrice = $call->latestPrice->close;
                    $putPrice = $put->latestPrice->close;

                    // ATM 時 Call 和 Put 價格最接近
                    $diff = abs($callPrice - $putPrice);

                    if ($diff < $minDiff) {
                        $minDiff = $diff;
                        $estimatedPrice = floatval($strike);
                    }
                }
            }

            return $estimatedPrice;

        } catch (\Exception $e) {
            Log::warning('推算期貨價格失敗', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 計算隱含波動率
     *
     * GET /api/volatility/implied/{optionId}
     */
    public function implied(Request $request, int $optionId): JsonResponse
    {
        try {
            $option = Option::with('latestPrice')->findOrFail($optionId);

            if (!$option->latestPrice) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到選擇權價格資料'
                ], 404);
            }

            // 取得標的資產價格
            $stock = Stock::where('symbol', $option->underlying)->first();
            $spotPrice = $stock?->latestPrice?->close ?? 0;

            if ($spotPrice <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => '無法取得標的資產價格'
                ], 400);
            }

            // 計算到期時間
            $now = Carbon::now();
            $expiry = Carbon::parse($option->expiry_date);
            $timeToExpiry = max(0.001, $now->diffInDays($expiry) / 365);

            // 取得無風險利率
            $riskFreeRate = floatval($request->input('risk_free_rate', 0.015));

            // 計算隱含波動率
            $marketPrice = floatval($option->latestPrice->close);

            $iv = $this->blackScholesService->calculateImpliedVolatility(
                $marketPrice,
                $spotPrice,
                $option->strike_price,
                $timeToExpiry,
                $riskFreeRate,
                $option->option_type
            );

            if ($iv === null) {
                return response()->json([
                    'success' => false,
                    'message' => '無法計算隱含波動率'
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'option' => [
                        'id' => $option->id,
                        'option_code' => $option->option_code,
                        'option_type' => $option->option_type,
                        'strike_price' => $option->strike_price,
                        'expiry_date' => $option->expiry_date,
                    ],
                    'implied_volatility' => $iv,
                    'implied_volatility_percentage' => round($iv * 100, 2) . '%',
                    'market_price' => $marketPrice,
                    'spot_price' => $spotPrice,
                    'time_to_expiry' => round($timeToExpiry, 4),
                    'calculated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算隱含波動率錯誤', [
                'option_id' => $optionId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 波動率錐
     *
     * GET /api/volatility/cone/{stockId}
     */
    public function cone(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);
            $lookbackDays = $request->input('lookback_days', 252);

            // 取得歷史價格
            $prices = StockPrice::where('stock_id', $stock->id)
                ->orderBy('trade_date', 'desc')
                ->limit($lookbackDays + 60)
                ->get()
                ->sortBy('trade_date')
                ->values();

            if ($prices->count() < 30) {
                return response()->json([
                    'success' => false,
                    'message' => '歷史資料不足'
                ], 400);
            }

            // 計算不同期間的波動率
            $periods = [10, 20, 30, 60];
            $coneData = [];

            foreach ($periods as $period) {
                $volatilities = [];

                for ($i = $period; $i < $prices->count(); $i++) {
                    $subset = $prices->slice($i - $period, $period + 1)->values();

                    // 計算該期間的波動率
                    $returns = [];
                    for ($j = 1; $j < $subset->count(); $j++) {
                        if ($subset[$j - 1]->close > 0) {
                            $returns[] = log($subset[$j]->close / $subset[$j - 1]->close);
                        }
                    }

                    if (count($returns) >= 2) {
                        $mean = array_sum($returns) / count($returns);
                        $variance = array_sum(array_map(fn($r) => pow($r - $mean, 2), $returns)) / (count($returns) - 1);
                        $volatilities[] = sqrt($variance) * sqrt(252);
                    }
                }

                if (!empty($volatilities)) {
                    sort($volatilities);
                    $count = count($volatilities);

                    $coneData[$period] = [
                        'period' => $period,
                        'current' => round(end($volatilities), 4),
                        'min' => round($volatilities[0], 4),
                        'percentile_25' => round($volatilities[intval($count * 0.25)], 4),
                        'median' => round($volatilities[intval($count * 0.5)], 4),
                        'percentile_75' => round($volatilities[intval($count * 0.75)], 4),
                        'max' => round($volatilities[$count - 1], 4),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'cone' => array_values($coneData),
                    'lookback_days' => $lookbackDays,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算波動率錐錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 波動率曲面
     *
     * GET /api/volatility/surface/{stockId}
     */
    public function surface(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);
            $tradeDate = $request->input('date', now()->format('Y-m-d'));

            // 取得該股票相關的選擇權
            $options = Option::where('underlying', $stock->symbol)
                ->orWhere('underlying', 'TXO')
                ->where('expiry_date', '>=', now())
                ->with(['prices' => function ($query) use ($tradeDate) {
                    $query->where('trade_date', $tradeDate)
                          ->whereNotNull('implied_volatility');
                }])
                ->get();

            $surfaceData = [];

            foreach ($options as $option) {
                $price = $option->prices->first();
                if ($price && $price->implied_volatility > 0) {
                    $surfaceData[] = [
                        'strike' => $option->strike_price,
                        'expiry' => $option->expiry_date,
                        'days_to_expiry' => Carbon::parse($option->expiry_date)->diffInDays(now()),
                        'option_type' => $option->option_type,
                        'iv' => round($price->implied_volatility, 4),
                        'iv_percentage' => round($price->implied_volatility * 100, 2),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'surface' => $surfaceData,
                    'trade_date' => $tradeDate,
                    'data_points' => count($surfaceData),
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算波動率曲面錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 波動率偏斜
     *
     * GET /api/volatility/skew/{stockId}
     */
    public function skew(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);
            $expiry = $request->input('expiry');

            $query = Option::where(function ($q) use ($stock) {
                    $q->where('underlying', $stock->symbol)
                      ->orWhere('underlying', 'TXO');
                })
                ->where('expiry_date', '>=', now());

            if ($expiry) {
                $query->where('expiry_date', $expiry);
            }

            $options = $query->orderBy('expiry_date')
                ->orderBy('strike_price')
                ->get();

            if ($options->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到相關選擇權資料'
                ], 404);
            }

            $nearestExpiry = $options->first()->expiry_date;
            $nearOptions = $options->where('expiry_date', $nearestExpiry);

            $stockPrice = $stock->latestPrice?->close ?? 0;

            $skewData = [];
            $strikes = $nearOptions->pluck('strike_price')->unique()->sort();

            foreach ($strikes as $strike) {
                $callOption = $nearOptions->where('strike_price', $strike)
                    ->where('option_type', 'call')
                    ->first();

                $putOption = $nearOptions->where('strike_price', $strike)
                    ->where('option_type', 'put')
                    ->first();

                $ivCall = $callOption?->latestPrice?->implied_volatility;
                $ivPut = $putOption?->latestPrice?->implied_volatility;

                $moneyness = $stockPrice > 0 ? ($strike / $stockPrice - 1) * 100 : 0;

                $skewData[] = [
                    'strike' => $strike,
                    'moneyness' => round($moneyness, 2),
                    'moneyness_label' => $moneyness > 0 ? 'OTM' : ($moneyness < 0 ? 'ITM' : 'ATM'),
                    'iv_call' => $ivCall ? round($ivCall * 100, 2) : null,
                    'iv_put' => $ivPut ? round($ivPut * 100, 2) : null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                        'current_price' => $stockPrice,
                    ],
                    'expiry' => $nearestExpiry,
                    'days_to_expiry' => Carbon::parse($nearestExpiry)->diffInDays(now()),
                    'skew_data' => $skewData,
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('計算波動率偏斜錯誤', [
                'stock_id' => $stockId,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GARCH 波動率預測
     *
     * GET /api/volatility/garch/{stockId}
     */
    public function garch(Request $request, int $stockId): JsonResponse
    {
        try {
            $stock = Stock::findOrFail($stockId);

            // GARCH 模型需要 Python 執行，這裡返回簡化版本
            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'message' => 'GARCH 模型請使用 Python 預測服務',
                    'endpoint' => '/api/predictions/garch'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '取得 GARCH 預測失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手動觸發波動率計算
     *
     * POST /api/volatility/calculate
     */
    public function calculate(Request $request): JsonResponse
    {
        try {
            $stockId = $request->input('stock_id');
            $periods = $request->input('periods', [10, 20, 30, 60]);

            $stock = Stock::findOrFail($stockId);

            $results = [];
            foreach ($periods as $period) {
                // 重新計算波動率
                Cache::forget("volatility:historical:{$stockId}:{$period}:*");

                $response = $this->historical(new Request(['period' => $period]), $stockId);
                $data = json_decode($response->getContent(), true);

                if ($data['success']) {
                    $results[$period] = $data['data'];
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'stock' => [
                        'id' => $stock->id,
                        'symbol' => $stock->symbol,
                        'name' => $stock->name,
                    ],
                    'calculations' => $results,
                    'calculated_at' => now()->toIso8601String(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '計算失敗: ' . $e->getMessage()
            ], 500);
        }
    }
}