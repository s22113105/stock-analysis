<?php

namespace App\Services;

use App\Models\OptionPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 選擇權分析服務 (最終寬容版)
 */
class OptionAnalysisService
{
    private function getLatestTradeDate(): string
    {
        $latest = DB::table('option_prices')->max('trade_date');
        return $latest ?: Carbon::now()->format('Y-m-d');
    }

    /**
     * 1. TXO 指數走勢圖 (移除所有嚴格檢查)
     */
    public function getTxoTrend(int $days = 30): array
    {
        try {
            $endDate = $this->getLatestTradeDate();
            $startDate = Carbon::parse($endDate)->subDays($days)->format('Y-m-d');

            // 修正：使用 COALESCE 確保數值不為 NULL
            $trendData = DB::table('option_prices')
                ->select(
                    'trade_date',
                    DB::raw('AVG(COALESCE(close, open, 0)) as avg_close'), // 優先取 close，沒有則取 open，再沒有補 0
                    DB::raw('MAX(high) as max_high'),
                    DB::raw('MIN(low) as min_low'),
                    DB::raw('SUM(volume) as total_volume')
                )
                ->whereBetween('trade_date', [$startDate, $endDate])
                ->groupBy('trade_date')
                ->orderBy('trade_date', 'asc')
                ->get();

            return [
                'success' => true,
                'data' => $trendData->map(function ($item) {
                    return [
                        'date' => $item->trade_date,
                        'close' => round((float)$item->avg_close, 2),
                        'high' => round((float)$item->max_high, 2),
                        'low' => round((float)$item->min_low, 2),
                        'volume' => (int)$item->total_volume
                    ];
                })
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 2. Call/Put 成交量分析
     */
    public function getVolumeAnalysis(?string $date = null): array
    {
        try {
            $date = $date ?: $this->getLatestTradeDate();
            $stats = DB::table('option_prices')
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->select('options.option_type', DB::raw('SUM(option_prices.volume) as total_volume'))
                ->where('option_prices.trade_date', $date)
                ->groupBy('options.option_type')
                ->get();

            $callVolume = $stats->firstWhere('option_type', 'call')->total_volume ?? 0;
            $putVolume = $stats->firstWhere('option_type', 'put')->total_volume ?? 0;
            $totalVolume = $callVolume + $putVolume;

            return [
                'success' => true,
                'data' => [
                    'date' => $date,
                    'call' => ['volume' => (int)$callVolume],
                    'put' => ['volume' => (int)$putVolume],
                    'total_volume' => (int)$totalVolume,
                    'put_call_volume_ratio' => $callVolume > 0 ? round($putVolume / $callVolume, 2) : 0
                ]
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 2c. 未平倉量(OI)分析
     */
    public function getOiAnalysis(?string $date = null): array
    {
        try {
            $date = $date ?: $this->getLatestTradeDate();
            $stats = DB::table('option_prices')
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->select('options.option_type', DB::raw('SUM(option_prices.open_interest) as total_oi'))
                ->where('option_prices.trade_date', $date)
                ->groupBy('options.option_type')
                ->get();

            $callOi = $stats->firstWhere('option_type', 'call')->total_oi ?? 0;
            $putOi = $stats->firstWhere('option_type', 'put')->total_oi ?? 0;
            $totalOi = $callOi + $putOi;

            return [
                'success' => true,
                'data' => [
                    'date' => $date,
                    'call' => ['oi' => (int)$callOi],
                    'put' => ['oi' => (int)$putOi],
                    'total_oi' => (int)$totalOi,
                    'put_call_oi_ratio' => $callOi > 0 ? round($putOi / $callOi, 2) : 0
                ]
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 3. 隱含波動率(IV)趨勢
     */
    public function getIvAnalysis(int $days = 30): array
    {
        try {
            $endDate = $this->getLatestTradeDate();
            $startDate = Carbon::parse($endDate)->subDays($days)->format('Y-m-d');
            $ivData = DB::table('option_prices')
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->select('option_prices.trade_date', 'options.option_type', DB::raw('AVG(option_prices.implied_volatility) as avg_iv'))
                ->whereBetween('option_prices.trade_date', [$startDate, $endDate])
                ->whereNotNull('option_prices.implied_volatility')
                ->groupBy('option_prices.trade_date', 'options.option_type')
                ->orderBy('option_prices.trade_date')
                ->get();

            $formatted = [];
            foreach ($ivData as $row) {
                $date = $row->trade_date;
                if (!isset($formatted[$date])) $formatted[$date] = ['date' => $date, 'call_iv' => 0, 'put_iv' => 0];
                if ($row->option_type == 'call') $formatted[$date]['call_iv'] = round($row->avg_iv, 2);
                else $formatted[$date]['put_iv'] = round($row->avg_iv, 2);
            }
            return ['success' => true, 'data' => array_values($formatted)];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 4. 價內外分佈 (OI 分佈圖)
     */
    public function getOiDistribution(?string $date = null): array
    {
        try {
            $date = $date ?: $this->getLatestTradeDate();
            $data = DB::table('option_prices')
                ->join('options', 'option_prices.option_id', '=', 'options.id')
                ->select('options.strike_price', 'options.option_type', DB::raw('SUM(option_prices.open_interest) as oi'))
                ->where('option_prices.trade_date', $date)
                ->groupBy('options.strike_price', 'options.option_type')
                ->orderBy('options.strike_price')
                ->get();

            $strikes = [];
            foreach ($data as $row) {
                $k = (int)$row->strike_price;
                if (!isset($strikes[$k])) $strikes[$k] = ['strike_price' => $k, 'call_oi' => 0, 'put_oi' => 0];
                if ($row->option_type == 'call') $strikes[$k]['call_oi'] = (int)$row->oi;
                else $strikes[$k]['put_oi'] = (int)$row->oi;
            }
            return ['success' => true, 'data' => collect($strikes)->sortByDesc(fn($i) => $i['call_oi'] + $i['put_oi'])->take(15)->sortBy('strike_price')->values()];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * 5. 市場情緒總覽
     */
    public function getSentiment(?string $date = null): array
    {
        try {
            $date = $date ?: $this->getLatestTradeDate();
            $vol = $this->getVolumeAnalysis($date)['data'];
            $oi = $this->getOiAnalysis($date)['data'];
            $avgIv = DB::table('option_prices')->where('trade_date', $date)->avg('implied_volatility');
            $pcr = $vol['put_call_volume_ratio'];

            $sentiment = ($pcr > 1.1) ? ['description' => '偏空', 'color' => 'error'] : (($pcr < 0.9) ? ['description' => '偏多', 'color' => 'success'] : ['description' => '中性', 'color' => 'grey']);

            return ['success' => true, 'data' => [
                'date' => $date,
                'sentiment' => $sentiment,
                'put_call_volume_ratio' => $pcr,
                'avg_iv' => $avgIv ? round($avgIv, 4) : 0,
                'iv_level' => ['description' => '正常', 'color' => 'warning'],
                'total_volume' => $vol['total_volume'],
                'total_oi' => $oi['total_oi']
            ]];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
