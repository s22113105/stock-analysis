<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\StockPrice;
use App\Models\BacktestResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * 回測服務
 * 
 * 功能：
 * - SMA 交叉策略
 * - MACD 策略
 * - RSI 策略
 * - 布林通道策略
 * - 績效計算
 */
class BacktestService
{
    /**
     * 執行回測
     *
     * @param Stock $stock 股票
     * @param Collection $prices 價格資料
     * @param string $strategyName 策略名稱
     * @param float $initialCapital 初始資金
     * @param array $parameters 策略參數
     * @return array 回測結果
     */
    public function runBacktest(
        Stock $stock,
        Collection $prices,
        string $strategyName,
        float $initialCapital = 100000,
        array $parameters = []
    ): array {
        // 根據策略類型執行回測
        $trades = match($strategyName) {
            'sma_crossover' => $this->smaCrossoverStrategy($prices, $parameters),
            'macd' => $this->macdStrategy($prices, $parameters),
            'rsi' => $this->rsiStrategy($prices, $parameters),
            'bollinger_bands' => $this->bollingerBandsStrategy($prices, $parameters),
            'covered_call' => $this->coveredCallStrategy($prices, $parameters),
            'protective_put' => $this->protectivePutStrategy($prices, $parameters),
            default => throw new \Exception("未知的策略: {$strategyName}")
        };

        // 計算績效
        $performance = $this->calculatePerformance($trades, $initialCapital, $prices);

        return [
            'trades' => $trades,
            'performance' => $performance
        ];
    }

    /**
     * SMA 交叉策略
     */
    protected function smaCrossoverStrategy(Collection $prices, array $parameters): array
    {
        $shortPeriod = $parameters['short_period'] ?? 20;
        $longPeriod = $parameters['long_period'] ?? 50;
        
        $trades = [];
        $position = null;

        foreach ($prices as $index => $price) {
            if ($index < $longPeriod) {
                continue;
            }

            // 計算 SMA
            $shortSMA = $prices->slice($index - $shortPeriod, $shortPeriod)
                ->avg('close');
            $longSMA = $prices->slice($index - $longPeriod, $longPeriod)
                ->avg('close');

            $prevShortSMA = $prices->slice($index - $shortPeriod - 1, $shortPeriod)
                ->avg('close');
            $prevLongSMA = $prices->slice($index - $longPeriod - 1, $longPeriod)
                ->avg('close');

            // 黃金交叉 - 買入
            if ($prevShortSMA <= $prevLongSMA && $shortSMA > $longSMA && !$position) {
                $position = [
                    'type' => 'buy',
                    'date' => $price->trade_date,
                    'price' => $price->close,
                    'shares' => 1000,
                ];
            }
            // 死亡交叉 - 賣出
            elseif ($prevShortSMA >= $prevLongSMA && $shortSMA < $longSMA && $position) {
                $trades[] = [
                    'entry_date' => $position['date'],
                    'entry_price' => $position['price'],
                    'exit_date' => $price->trade_date,
                    'exit_price' => $price->close,
                    'shares' => $position['shares'],
                    'profit' => ($price->close - $position['price']) * $position['shares'],
                    'return' => (($price->close - $position['price']) / $position['price']) * 100,
                ];
                $position = null;
            }
        }

        // 平倉未結束的部位
        if ($position) {
            $lastPrice = $prices->last();
            $trades[] = [
                'entry_date' => $position['date'],
                'entry_price' => $position['price'],
                'exit_date' => $lastPrice->trade_date,
                'exit_price' => $lastPrice->close,
                'shares' => $position['shares'],
                'profit' => ($lastPrice->close - $position['price']) * $position['shares'],
                'return' => (($lastPrice->close - $position['price']) / $position['price']) * 100,
            ];
        }

        return $trades;
    }

    /**
     * MACD 策略
     */
    protected function macdStrategy(Collection $prices, array $parameters): array
    {
        $fastPeriod = $parameters['fast_period'] ?? 12;
        $slowPeriod = $parameters['slow_period'] ?? 26;
        $signalPeriod = $parameters['signal_period'] ?? 9;

        $trades = [];
        $position = null;

        // 計算 MACD
        $macdData = $this->calculateMACD($prices, $fastPeriod, $slowPeriod, $signalPeriod);

        foreach ($prices as $index => $price) {
            if ($index < $slowPeriod + $signalPeriod) {
                continue;
            }

            $macd = $macdData[$index]['macd'] ?? 0;
            $signal = $macdData[$index]['signal'] ?? 0;
            $prevMacd = $macdData[$index - 1]['macd'] ?? 0;
            $prevSignal = $macdData[$index - 1]['signal'] ?? 0;

            // MACD 向上穿越信號線 - 買入
            if ($prevMacd <= $prevSignal && $macd > $signal && !$position) {
                $position = [
                    'type' => 'buy',
                    'date' => $price->trade_date,
                    'price' => $price->close,
                    'shares' => 1000,
                ];
            }
            // MACD 向下穿越信號線 - 賣出
            elseif ($prevMacd >= $prevSignal && $macd < $signal && $position) {
                $trades[] = [
                    'entry_date' => $position['date'],
                    'entry_price' => $position['price'],
                    'exit_date' => $price->trade_date,
                    'exit_price' => $price->close,
                    'shares' => $position['shares'],
                    'profit' => ($price->close - $position['price']) * $position['shares'],
                    'return' => (($price->close - $position['price']) / $position['price']) * 100,
                ];
                $position = null;
            }
        }

        // 平倉
        if ($position) {
            $lastPrice = $prices->last();
            $trades[] = [
                'entry_date' => $position['date'],
                'entry_price' => $position['price'],
                'exit_date' => $lastPrice->trade_date,
                'exit_price' => $lastPrice->close,
                'shares' => $position['shares'],
                'profit' => ($lastPrice->close - $position['price']) * $position['shares'],
                'return' => (($lastPrice->close - $position['price']) / $position['price']) * 100,
            ];
        }

        return $trades;
    }

    /**
     * RSI 策略
     */
    protected function rsiStrategy(Collection $prices, array $parameters): array
    {
        $period = $parameters['period'] ?? 14;
        $oversold = $parameters['oversold'] ?? 30;
        $overbought = $parameters['overbought'] ?? 70;

        $trades = [];
        $position = null;

        // 計算 RSI
        $rsiData = $this->calculateRSI($prices, $period);

        foreach ($prices as $index => $price) {
            if ($index < $period) {
                continue;
            }

            $rsi = $rsiData[$index] ?? 50;

            // RSI < 超賣線 - 買入
            if ($rsi < $oversold && !$position) {
                $position = [
                    'type' => 'buy',
                    'date' => $price->trade_date,
                    'price' => $price->close,
                    'shares' => 1000,
                ];
            }
            // RSI > 超買線 - 賣出
            elseif ($rsi > $overbought && $position) {
                $trades[] = [
                    'entry_date' => $position['date'],
                    'entry_price' => $position['price'],
                    'exit_date' => $price->trade_date,
                    'exit_price' => $price->close,
                    'shares' => $position['shares'],
                    'profit' => ($price->close - $position['price']) * $position['shares'],
                    'return' => (($price->close - $position['price']) / $position['price']) * 100,
                ];
                $position = null;
            }
        }

        // 平倉
        if ($position) {
            $lastPrice = $prices->last();
            $trades[] = [
                'entry_date' => $position['date'],
                'entry_price' => $position['price'],
                'exit_date' => $lastPrice->trade_date,
                'exit_price' => $lastPrice->close,
                'shares' => $position['shares'],
                'profit' => ($lastPrice->close - $position['price']) * $position['shares'],
                'return' => (($lastPrice->close - $position['price']) / $position['price']) * 100,
            ];
        }

        return $trades;
    }

    /**
     * 布林通道策略
     */
    protected function bollingerBandsStrategy(Collection $prices, array $parameters): array
    {
        $period = $parameters['period'] ?? 20;
        $stdDev = $parameters['std_dev'] ?? 2;

        $trades = [];
        $position = null;

        foreach ($prices as $index => $price) {
            if ($index < $period) {
                continue;
            }

            // 計算布林通道
            $recentPrices = $prices->slice($index - $period, $period);
            $sma = $recentPrices->avg('close');
            $std = $this->calculateStdDev($recentPrices->pluck('close')->toArray());
            
            $upperBand = $sma + ($stdDev * $std);
            $lowerBand = $sma - ($stdDev * $std);

            // 價格觸及下軌 - 買入
            if ($price->close <= $lowerBand && !$position) {
                $position = [
                    'type' => 'buy',
                    'date' => $price->trade_date,
                    'price' => $price->close,
                    'shares' => 1000,
                ];
            }
            // 價格觸及上軌 - 賣出
            elseif ($price->close >= $upperBand && $position) {
                $trades[] = [
                    'entry_date' => $position['date'],
                    'entry_price' => $position['price'],
                    'exit_date' => $price->trade_date,
                    'exit_price' => $price->close,
                    'shares' => $position['shares'],
                    'profit' => ($price->close - $position['price']) * $position['shares'],
                    'return' => (($price->close - $position['price']) / $position['price']) * 100,
                ];
                $position = null;
            }
        }

        // 平倉
        if ($position) {
            $lastPrice = $prices->last();
            $trades[] = [
                'entry_date' => $position['date'],
                'entry_price' => $position['price'],
                'exit_date' => $lastPrice->trade_date,
                'exit_price' => $lastPrice->close,
                'shares' => $position['shares'],
                'profit' => ($lastPrice->close - $position['price']) * $position['shares'],
                'return' => (($lastPrice->close - $position['price']) / $position['price']) * 100,
            ];
        }

        return $trades;
    }

    /**
     * Covered Call 策略
     */
    protected function coveredCallStrategy(Collection $prices, array $parameters): array
    {
        // 簡化版本 - 實際應該結合選擇權資料
        return [];
    }

    /**
     * Protective Put 策略
     */
    protected function protectivePutStrategy(Collection $prices, array $parameters): array
    {
        // 簡化版本 - 實際應該結合選擇權資料
        return [];
    }

    /**
     * 計算績效指標
     */
    protected function calculatePerformance(array $trades, float $initialCapital, Collection $prices): array
    {
        if (empty($trades)) {
            return [
                'initial_capital' => $initialCapital,
                'final_capital' => $initialCapital,
                'total_return' => 0,
                'annual_return' => 0,
                'sharpe_ratio' => 0,
                'max_drawdown' => 0,
                'win_rate' => 0,
                'total_trades' => 0,
            ];
        }

        // 計算總獲利
        $totalProfit = array_sum(array_column($trades, 'profit'));
        $finalCapital = $initialCapital + $totalProfit;
        $totalReturn = ($totalProfit / $initialCapital) * 100;

        // 計算年化報酬
        $startDate = Carbon::parse($trades[0]['entry_date']);
        $endDate = Carbon::parse($trades[count($trades) - 1]['exit_date']);
        $years = $startDate->diffInDays($endDate) / 365;
        $annualReturn = $years > 0 ? (pow(($finalCapital / $initialCapital), (1 / $years)) - 1) * 100 : 0;

        // 計算勝率
        $winningTrades = array_filter($trades, fn($t) => $t['profit'] > 0);
        $losingTrades = array_filter($trades, fn($t) => $t['profit'] <= 0);
        $winRate = count($trades) > 0 ? (count($winningTrades) / count($trades)) * 100 : 0;

        // 計算平均獲利/虧損
        $avgWin = count($winningTrades) > 0 ? 
            array_sum(array_column($winningTrades, 'profit')) / count($winningTrades) : 0;
        $avgLoss = count($losingTrades) > 0 ? 
            array_sum(array_column($losingTrades, 'profit')) / count($losingTrades) : 0;

        // 計算獲利因子
        $totalWin = array_sum(array_column($winningTrades, 'profit'));
        $totalLoss = abs(array_sum(array_column($losingTrades, 'profit')));
        $profitFactor = $totalLoss > 0 ? $totalWin / $totalLoss : 0;

        // 計算最大回撤
        $maxDrawdown = $this->calculateMaxDrawdown($trades, $initialCapital);

        // 計算夏普比率
        $returns = array_column($trades, 'return');
        $sharpeRatio = $this->calculateSharpeRatio($returns);

        // 計算索提諾比率
        $sortinoRatio = $this->calculateSortinoRatio($returns);

        // 生成權益曲線
        $equityCurve = $this->generateEquityCurve($trades, $initialCapital);

        return [
            'initial_capital' => $initialCapital,
            'final_capital' => round($finalCapital, 2),
            'total_return' => round($totalReturn, 2),
            'annual_return' => round($annualReturn, 2),
            'sharpe_ratio' => round($sharpeRatio, 4),
            'sortino_ratio' => round($sortinoRatio, 4),
            'max_drawdown' => round($maxDrawdown, 2),
            'win_rate' => round($winRate, 2),
            'total_trades' => count($trades),
            'winning_trades' => count($winningTrades),
            'losing_trades' => count($losingTrades),
            'avg_win' => round($avgWin, 2),
            'avg_loss' => round($avgLoss, 2),
            'profit_factor' => round($profitFactor, 4),
            'equity_curve' => $equityCurve,
        ];
    }

    /**
     * 計算 MACD
     */
    protected function calculateMACD(Collection $prices, int $fastPeriod, int $slowPeriod, int $signalPeriod): array
    {
        $macdData = [];
        $emaFast = [];
        $emaSlow = [];
        $macdLine = [];
        $signalLine = [];

        // 計算 EMA
        $multiplierFast = 2 / ($fastPeriod + 1);
        $multiplierSlow = 2 / ($slowPeriod + 1);
        $multiplierSignal = 2 / ($signalPeriod + 1);

        foreach ($prices as $index => $price) {
            // Fast EMA
            if ($index === 0) {
                $emaFast[$index] = $price->close;
            } else {
                $emaFast[$index] = ($price->close * $multiplierFast) + ($emaFast[$index - 1] * (1 - $multiplierFast));
            }

            // Slow EMA
            if ($index === 0) {
                $emaSlow[$index] = $price->close;
            } else {
                $emaSlow[$index] = ($price->close * $multiplierSlow) + ($emaSlow[$index - 1] * (1 - $multiplierSlow));
            }

            // MACD Line
            $macdLine[$index] = $emaFast[$index] - $emaSlow[$index];

            // Signal Line
            if ($index < $signalPeriod) {
                $signalLine[$index] = $macdLine[$index];
            } else {
                $signalLine[$index] = ($macdLine[$index] * $multiplierSignal) + ($signalLine[$index - 1] * (1 - $multiplierSignal));
            }

            $macdData[$index] = [
                'macd' => $macdLine[$index],
                'signal' => $signalLine[$index],
                'histogram' => $macdLine[$index] - $signalLine[$index],
            ];
        }

        return $macdData;
    }

    /**
     * 計算 RSI
     */
    protected function calculateRSI(Collection $prices, int $period): array
    {
        $rsiData = [];
        $gains = [];
        $losses = [];

        foreach ($prices as $index => $price) {
            if ($index === 0) {
                $rsiData[$index] = 50;
                continue;
            }

            $change = $price->close - $prices[$index - 1]->close;
            $gains[$index] = $change > 0 ? $change : 0;
            $losses[$index] = $change < 0 ? abs($change) : 0;

            if ($index < $period) {
                $rsiData[$index] = 50;
                continue;
            }

            // 計算平均獲利/虧損
            $avgGain = array_sum(array_slice($gains, $index - $period + 1, $period)) / $period;
            $avgLoss = array_sum(array_slice($losses, $index - $period + 1, $period)) / $period;

            if ($avgLoss == 0) {
                $rsiData[$index] = 100;
            } else {
                $rs = $avgGain / $avgLoss;
                $rsiData[$index] = 100 - (100 / (1 + $rs));
            }
        }

        return $rsiData;
    }

    /**
     * 計算標準差
     */
    protected function calculateStdDev(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $values)) / $count;

        return sqrt($variance);
    }

    /**
     * 計算最大回撤
     */
    protected function calculateMaxDrawdown(array $trades, float $initialCapital): float
    {
        $equity = $initialCapital;
        $peak = $initialCapital;
        $maxDrawdown = 0;

        foreach ($trades as $trade) {
            $equity += $trade['profit'];
            
            if ($equity > $peak) {
                $peak = $equity;
            }

            $drawdown = (($peak - $equity) / $peak) * 100;
            
            if ($drawdown > $maxDrawdown) {
                $maxDrawdown = $drawdown;
            }
        }

        return $maxDrawdown;
    }

    /**
     * 計算夏普比率
     */
    protected function calculateSharpeRatio(array $returns): float
    {
        if (empty($returns)) {
            return 0;
        }

        $avgReturn = array_sum($returns) / count($returns);
        $stdDev = $this->calculateStdDev($returns);

        if ($stdDev == 0) {
            return 0;
        }

        // 假設無風險利率為 1.5%
        $riskFreeRate = 1.5;

        return ($avgReturn - $riskFreeRate) / $stdDev;
    }

    /**
     * 計算索提諾比率
     */
    protected function calculateSortinoRatio(array $returns): float
    {
        if (empty($returns)) {
            return 0;
        }

        $avgReturn = array_sum($returns) / count($returns);
        
        // 只計算負報酬的標準差
        $negativeReturns = array_filter($returns, fn($r) => $r < 0);
        
        if (empty($negativeReturns)) {
            return 0;
        }

        $downSideDev = $this->calculateStdDev($negativeReturns);

        if ($downSideDev == 0) {
            return 0;
        }

        $riskFreeRate = 1.5;

        return ($avgReturn - $riskFreeRate) / $downSideDev;
    }

    /**
     * 生成權益曲線
     */
    protected function generateEquityCurve(array $trades, float $initialCapital): array
    {
        $curve = [];
        $equity = $initialCapital;

        foreach ($trades as $trade) {
            $equity += $trade['profit'];
            $curve[] = [
                'date' => $trade['exit_date'],
                'equity' => round($equity, 2),
            ];
        }

        return $curve;
    }
}