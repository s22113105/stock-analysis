<?php

namespace App\Services;

use App\Models\Option;
use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 選擇權分析服務
 *
 * 提供 TXO 選擇權的各種分析功能
 */
class OptionAnalysisService
{
    /**
     * 取得 TXO 收盤價走勢
     *
     * @param int $days 天數
     * @return array
     */
    public function getTxoTrend(int $days = 30): array
    {
        try {
            // 計算起始日期
            $startDate = Carbon::now()->subDays($days)->format('Y-m-d');

            // 取得 ATM (價平) 選擇權的收盤價作為代表
            // 策略: 找出每日成交量最大的選擇權作為代表
            $trendData = OptionPrice::select(
                'trade_date',
                DB::raw('AVG(close) as avg_close'),
                DB::raw('MAX(close) as max_close'),
                DB::raw('MIN(close) as min_close'),
                DB::raw('SUM(volume) as total_volume')
            )
                ->whereHas('option', function ($query) {
                    $query->where('underlying', 'TXO')
                        ->where('is_active', true);
                })
                ->where('trade_date', '>=', $startDate)
                ->groupBy('trade_date')
                ->orderBy('trade_date', 'asc')
                ->get();

            return [
                'success' => true,
                'data' => $trendData->map(function ($item) {
                    return [
                        'date' => $item->trade_date,
                        'close' => round($item->avg_close, 2),
                        'high' => round($item->max_close, 2),
                        'low' => round($item->min_close, 2),
                        'volume' => (int) $item->total_volume
                    ];
                }),
                'period' => $days,
                'start_date' => $startDate,
                'end_date' => Carbon::now()->format('Y-m-d')
            ];
        } catch (\Exception $e) {
            Log::error('取得 TXO 走勢失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '取得走勢資料失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 取得成交量分析 (Call vs Put)
     *
     * @param string|null $date 交易日期
     * @return array
     */
    public function getVolumeAnalysis(?string $date = null): array
    {
        try {
            $date = $date ?? Carbon::now()->format('Y-m-d');

            // 取得 Call 和 Put 的成交量統計
            $volumeData = OptionPrice::select(
                'options.option_type',
                DB::raw('SUM(option_prices.volume) as total_volume'),
                DB::raw('COUNT(option_prices.id) as contract_count'),
                DB::raw('AVG(option_prices.volume) as avg_volume')
            )
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->where('options.underlying', 'TXO')
                ->where('option_prices.trade_date', $date)
                ->groupBy('options.option_type')
                ->get();

            $callData = $volumeData->firstWhere('option_type', 'call');
            $putData = $volumeData->firstWhere('option_type', 'put');

            $callVolume = $callData ? (int) $callData->total_volume : 0;
            $putVolume = $putData ? (int) $putData->total_volume : 0;
            $totalVolume = $callVolume + $putVolume;

            // 計算比例
            $callRatio = $totalVolume > 0 ? round(($callVolume / $totalVolume) * 100, 2) : 0;
            $putRatio = $totalVolume > 0 ? round(($putVolume / $totalVolume) * 100, 2) : 0;

            return [
                'success' => true,
                'data' => [
                    'date' => $date,
                    'call' => [
                        'volume' => $callVolume,
                        'ratio' => $callRatio,
                        'contract_count' => $callData ? (int) $callData->contract_count : 0,
                        'avg_volume' => $callData ? round($callData->avg_volume, 0) : 0
                    ],
                    'put' => [
                        'volume' => $putVolume,
                        'ratio' => $putRatio,
                        'contract_count' => $putData ? (int) $putData->contract_count : 0,
                        'avg_volume' => $putData ? round($putData->avg_volume, 0) : 0
                    ],
                    'total_volume' => $totalVolume,
                    'put_call_volume_ratio' => $callVolume > 0 ? round($putVolume / $callVolume, 2) : 0
                ]
            ];
        } catch (\Exception $e) {
            Log::error('取得成交量分析失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '取得成交量分析失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 取得未平倉量分析 (OI Analysis)
     *
     * @param string|null $date 交易日期
     * @return array
     */
    public function getOiAnalysis(?string $date = null): array
    {
        try {
            $date = $date ?? Carbon::now()->format('Y-m-d');

            // 取得 Call 和 Put 的未平倉量統計
            $oiData = OptionPrice::select(
                'options.option_type',
                DB::raw('SUM(option_prices.open_interest) as total_oi'),
                DB::raw('COUNT(option_prices.id) as contract_count'),
                DB::raw('AVG(option_prices.open_interest) as avg_oi')
            )
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->where('options.underlying', 'TXO')
                ->where('option_prices.trade_date', $date)
                ->whereNotNull('option_prices.open_interest')
                ->groupBy('options.option_type')
                ->get();

            $callData = $oiData->firstWhere('option_type', 'call');
            $putData = $oiData->firstWhere('option_type', 'put');

            $callOI = $callData ? (int) $callData->total_oi : 0;
            $putOI = $putData ? (int) $putData->total_oi : 0;
            $totalOI = $callOI + $putOI;

            // 計算比例
            $callRatio = $totalOI > 0 ? round(($callOI / $totalOI) * 100, 2) : 0;
            $putRatio = $totalOI > 0 ? round(($putOI / $totalOI) * 100, 2) : 0;

            // 取得前一日數據用於計算變化
            $previousDate = Carbon::parse($date)->subDay()->format('Y-m-d');
            $previousOiData = OptionPrice::select(
                'options.option_type',
                DB::raw('SUM(option_prices.open_interest) as total_oi')
            )
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->where('options.underlying', 'TXO')
                ->where('option_prices.trade_date', $previousDate)
                ->whereNotNull('option_prices.open_interest')
                ->groupBy('options.option_type')
                ->get();

            $prevCallOI = $previousOiData->firstWhere('option_type', 'call')?->total_oi ?? 0;
            $prevPutOI = $previousOiData->firstWhere('option_type', 'put')?->total_oi ?? 0;

            return [
                'success' => true,
                'data' => [
                    'date' => $date,
                    'call' => [
                        'oi' => $callOI,
                        'ratio' => $callRatio,
                        'change' => $callOI - $prevCallOI,
                        'change_percent' => $prevCallOI > 0 ? round((($callOI - $prevCallOI) / $prevCallOI) * 100, 2) : 0
                    ],
                    'put' => [
                        'oi' => $putOI,
                        'ratio' => $putRatio,
                        'change' => $putOI - $prevPutOI,
                        'change_percent' => $prevPutOI > 0 ? round((($putOI - $prevPutOI) / $prevPutOI) * 100, 2) : 0
                    ],
                    'total_oi' => $totalOI,
                    'put_call_oi_ratio' => $callOI > 0 ? round($putOI / $callOI, 2) : 0
                ]
            ];
        } catch (\Exception $e) {
            Log::error('取得未平倉量分析失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '取得未平倉量分析失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 取得隱含波動率分析 (IV Analysis)
     *
     * @param int $days 天數
     * @return array
     */
    public function getIvAnalysis(int $days = 30): array
    {
        try {
            $startDate = Carbon::now()->subDays($days)->format('Y-m-d');

            // 取得每日平均 IV
            $ivTrend = OptionPrice::select(
                'trade_date',
                'options.option_type',
                DB::raw('AVG(option_prices.implied_volatility) as avg_iv'),
                DB::raw('MIN(option_prices.implied_volatility) as min_iv'),
                DB::raw('MAX(option_prices.implied_volatility) as max_iv')
            )
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->where('options.underlying', 'TXO')
                ->where('trade_date', '>=', $startDate)
                ->whereNotNull('option_prices.implied_volatility')
                ->groupBy('trade_date', 'options.option_type')
                ->orderBy('trade_date', 'asc')
                ->get();

            // 整理數據格式
            $formattedData = [];
            foreach ($ivTrend as $item) {
                $date = $item->trade_date;
                if (!isset($formattedData[$date])) {
                    $formattedData[$date] = [
                        'date' => $date,
                        'call_iv' => null,
                        'put_iv' => null
                    ];
                }

                if ($item->option_type === 'call') {
                    $formattedData[$date]['call_iv'] = round($item->avg_iv, 4);
                } else {
                    $formattedData[$date]['put_iv'] = round($item->avg_iv, 4);
                }
            }

            return [
                'success' => true,
                'data' => array_values($formattedData),
                'period' => $days
            ];
        } catch (\Exception $e) {
            Log::error('取得 IV 分析失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '取得 IV 分析失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 取得市場情緒總覽
     *
     * @param string|null $date 交易日期
     * @return array
     */
    public function getSentiment(?string $date = null): array
    {
        try {
            $date = $date ?? Carbon::now()->format('Y-m-d');

            // 取得成交量分析
            $volumeAnalysis = $this->getVolumeAnalysis($date);

            // 取得未平倉量分析
            $oiAnalysis = $this->getOiAnalysis($date);

            // 取得當日平均 IV
            $avgIv = OptionPrice::join('options', 'option_prices.option_id', '=', 'options.id')
                ->where('options.underlying', 'TXO')
                ->where('option_prices.trade_date', $date)
                ->whereNotNull('option_prices.implied_volatility')
                ->avg('option_prices.implied_volatility');

            // 判斷市場情緒
            $putCallVolumeRatio = $volumeAnalysis['data']['put_call_volume_ratio'] ?? 0;
            $sentiment = $this->calculateSentiment($putCallVolumeRatio, $avgIv);

            return [
                'success' => true,
                'data' => [
                    'date' => $date,
                    'sentiment' => $sentiment,
                    'put_call_volume_ratio' => $putCallVolumeRatio,
                    'put_call_oi_ratio' => $oiAnalysis['data']['put_call_oi_ratio'] ?? 0,
                    'avg_iv' => $avgIv ? round($avgIv, 4) : null,
                    'iv_level' => $this->getIvLevel($avgIv),
                    'total_volume' => $volumeAnalysis['data']['total_volume'] ?? 0,
                    'total_oi' => $oiAnalysis['data']['total_oi'] ?? 0,
                    'volume_breakdown' => [
                        'call' => $volumeAnalysis['data']['call'] ?? null,
                        'put' => $volumeAnalysis['data']['put'] ?? null
                    ],
                    'oi_breakdown' => [
                        'call' => $oiAnalysis['data']['call'] ?? null,
                        'put' => $oiAnalysis['data']['put'] ?? null
                    ]
                ]
            ];
        } catch (\Exception $e) {
            Log::error('取得市場情緒失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '取得市場情緒失敗: ' . $e->getMessage()
            ];
        }
    }

    /**
     * 計算市場情緒
     *
     * @param float $putCallRatio Put/Call Ratio
     * @param float|null $iv 隱含波動率
     * @return array
     */
    private function calculateSentiment(float $putCallRatio, ?float $iv): array
    {
        $sentiment = 'neutral';
        $description = '市場中性';
        $color = 'grey';

        // 基於 Put/Call Ratio 判斷
        if ($putCallRatio > 1.2) {
            $sentiment = 'bearish';
            $description = '偏空';
            $color = 'error';
        } elseif ($putCallRatio < 0.8) {
            $sentiment = 'bullish';
            $description = '偏多';
            $color = 'success';
        }

        // 如果有 IV 數據,可以調整判斷
        if ($iv !== null) {
            if ($iv > 0.3) {
                $description .= ' (高波動)';
            } elseif ($iv < 0.15) {
                $description .= ' (低波動)';
            }
        }

        return [
            'type' => $sentiment,
            'description' => $description,
            'color' => $color
        ];
    }

    /**
     * 判斷 IV 水平
     *
     * @param float|null $iv 隱含波動率
     * @return array
     */
    private function getIvLevel(?float $iv): array
    {
        if ($iv === null) {
            return [
                'level' => 'unknown',
                'description' => '無數據',
                'color' => 'grey'
            ];
        }

        if ($iv < 0.15) {
            return [
                'level' => 'low',
                'description' => '低波動',
                'color' => 'success'
            ];
        } elseif ($iv < 0.25) {
            return [
                'level' => 'medium',
                'description' => '中等波動',
                'color' => 'warning'
            ];
        } else {
            return [
                'level' => 'high',
                'description' => '高波動',
                'color' => 'error'
            ];
        }
    }

    /**
     * 取得 OI 分佈 (依履約價)
     *
     * @param string|null $date 交易日期
     * @param int $limit 顯示數量
     * @return array
     */
    public function getOiDistribution(?string $date = null, int $limit = 20): array
    {
        try {
            $date = $date ?? Carbon::now()->format('Y-m-d');

            // 取得 OI 分佈數據
            $distribution = OptionPrice::select(
                'options.strike_price',
                'options.option_type',
                DB::raw('SUM(option_prices.open_interest) as total_oi'),
                DB::raw('SUM(option_prices.volume) as total_volume')
            )
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->where('options.underlying', 'TXO')
                ->where('option_prices.trade_date', $date)
                ->whereNotNull('option_prices.open_interest')
                ->groupBy('options.strike_price', 'options.option_type')
                ->orderBy('total_oi', 'desc')
                ->limit($limit)
                ->get();

            // 整理數據
            $strikeData = [];
            foreach ($distribution as $item) {
                $strike = $item->strike_price;
                if (!isset($strikeData[$strike])) {
                    $strikeData[$strike] = [
                        'strike_price' => $strike,
                        'call_oi' => 0,
                        'put_oi' => 0,
                        'call_volume' => 0,
                        'put_volume' => 0
                    ];
                }

                if ($item->option_type === 'call') {
                    $strikeData[$strike]['call_oi'] = (int) $item->total_oi;
                    $strikeData[$strike]['call_volume'] = (int) $item->total_volume;
                } else {
                    $strikeData[$strike]['put_oi'] = (int) $item->total_oi;
                    $strikeData[$strike]['put_volume'] = (int) $item->total_volume;
                }
            }

            // 排序並取前 N 個
            $sortedData = collect($strikeData)->sortByDesc(function ($item) {
                return $item['call_oi'] + $item['put_oi'];
            })->values()->take($limit);

            return [
                'success' => true,
                'data' => $sortedData,
                'date' => $date
            ];
        } catch (\Exception $e) {
            Log::error('取得 OI 分佈失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => '取得 OI 分佈失敗: ' . $e->getMessage()
            ];
        }
    }
}
