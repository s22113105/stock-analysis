<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Black-Scholes 選擇權定價模型服務
 * 
 * 功能：
 * - 計算歐式選擇權理論價格
 * - 計算 Greeks (Delta, Gamma, Theta, Vega, Rho)
 * - 計算隱含波動率 (Implied Volatility)
 */
class BlackScholesService
{
    /**
     * 計算選擇權理論價格
     *
     * @param float $spotPrice 標的資產現價
     * @param float $strikePrice 履約價格
     * @param float $timeToExpiry 到期時間(年)
     * @param float $riskFreeRate 無風險利率
     * @param float $volatility 波動率
     * @param string $optionType 選擇權類型 (call/put)
     * @return float 理論價格
     */
    public function calculatePrice(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility,
        string $optionType = 'call'
    ): float {
        // 參數驗證
        $this->validateParameters($spotPrice, $strikePrice, $timeToExpiry, $volatility);

        // 計算 d1 和 d2
        $d1 = $this->calculateD1($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility);
        $d2 = $this->calculateD2($d1, $volatility, $timeToExpiry);

        if (strtolower($optionType) === 'call') {
            // Call Option: C = S * N(d1) - K * e^(-rT) * N(d2)
            $price = $spotPrice * $this->normalCDF($d1) 
                   - $strikePrice * exp(-$riskFreeRate * $timeToExpiry) * $this->normalCDF($d2);
        } else {
            // Put Option: P = K * e^(-rT) * N(-d2) - S * N(-d1)
            $price = $strikePrice * exp(-$riskFreeRate * $timeToExpiry) * $this->normalCDF(-$d2) 
                   - $spotPrice * $this->normalCDF(-$d1);
        }

        return round($price, 4);
    }

    /**
     * 計算所有 Greeks
     *
     * @param float $spotPrice
     * @param float $strikePrice
     * @param float $timeToExpiry
     * @param float $riskFreeRate
     * @param float $volatility
     * @param string $optionType
     * @return array [delta, gamma, theta, vega, rho]
     */
    public function calculateGreeks(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility,
        string $optionType = 'call'
    ): array {
        $d1 = $this->calculateD1($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility);
        $d2 = $this->calculateD2($d1, $volatility, $timeToExpiry);

        return [
            'delta' => $this->calculateDelta($d1, $optionType),
            'gamma' => $this->calculateGamma($spotPrice, $d1, $volatility, $timeToExpiry),
            'theta' => $this->calculateTheta($spotPrice, $strikePrice, $d1, $d2, $timeToExpiry, $riskFreeRate, $volatility, $optionType),
            'vega' => $this->calculateVega($spotPrice, $d1, $timeToExpiry),
            'rho' => $this->calculateRho($strikePrice, $d2, $timeToExpiry, $riskFreeRate, $optionType),
        ];
    }

    /**
     * 計算 Delta
     * Call: N(d1), Put: N(d1) - 1
     */
    public function calculateDelta(float $d1, string $optionType = 'call'): float
    {
        if (strtolower($optionType) === 'call') {
            return round($this->normalCDF($d1), 5);
        } else {
            return round($this->normalCDF($d1) - 1, 5);
        }
    }

    /**
     * 計算 Gamma
     * Gamma = N'(d1) / (S * σ * sqrt(T))
     */
    public function calculateGamma(
        float $spotPrice,
        float $d1,
        float $volatility,
        float $timeToExpiry
    ): float {
        $nPrime = $this->normalPDF($d1);
        $gamma = $nPrime / ($spotPrice * $volatility * sqrt($timeToExpiry));
        
        return round($gamma, 5);
    }

    /**
     * 計算 Theta (每日時間價值衰減)
     */
    public function calculateTheta(
        float $spotPrice,
        float $strikePrice,
        float $d1,
        float $d2,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility,
        string $optionType = 'call'
    ): float {
        $nPrimeD1 = $this->normalPDF($d1);
        $sqrtT = sqrt($timeToExpiry);

        if (strtolower($optionType) === 'call') {
            $theta = (-$spotPrice * $nPrimeD1 * $volatility / (2 * $sqrtT))
                   - $riskFreeRate * $strikePrice * exp(-$riskFreeRate * $timeToExpiry) * $this->normalCDF($d2);
        } else {
            $theta = (-$spotPrice * $nPrimeD1 * $volatility / (2 * $sqrtT))
                   + $riskFreeRate * $strikePrice * exp(-$riskFreeRate * $timeToExpiry) * $this->normalCDF(-$d2);
        }

        // 轉換為每日 Theta (除以 365)
        return round($theta / 365, 5);
    }

    /**
     * 計算 Vega (波動率敏感度)
     * Vega = S * N'(d1) * sqrt(T) / 100
     */
    public function calculateVega(
        float $spotPrice,
        float $d1,
        float $timeToExpiry
    ): float {
        $vega = $spotPrice * $this->normalPDF($d1) * sqrt($timeToExpiry) / 100;
        
        return round($vega, 5);
    }

    /**
     * 計算 Rho (利率敏感度)
     */
    public function calculateRho(
        float $strikePrice,
        float $d2,
        float $timeToExpiry,
        float $riskFreeRate,
        string $optionType = 'call'
    ): float {
        if (strtolower($optionType) === 'call') {
            $rho = $strikePrice * $timeToExpiry * exp(-$riskFreeRate * $timeToExpiry) 
                 * $this->normalCDF($d2) / 100;
        } else {
            $rho = -$strikePrice * $timeToExpiry * exp(-$riskFreeRate * $timeToExpiry) 
                 * $this->normalCDF(-$d2) / 100;
        }

        return round($rho, 5);
    }

    /**
     * 使用牛頓法計算隱含波動率
     *
     * @param float $marketPrice 市場價格
     * @param float $spotPrice 標的資產現價
     * @param float $strikePrice 履約價格
     * @param float $timeToExpiry 到期時間(年)
     * @param float $riskFreeRate 無風險利率
     * @param string $optionType 選擇權類型
     * @param float $initialGuess 初始猜測值
     * @param int $maxIterations 最大迭代次數
     * @param float $tolerance 容差
     * @return float|null 隱含波動率，失敗返回 null
     */
    public function calculateImpliedVolatility(
        float $marketPrice,
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        string $optionType = 'call',
        float $initialGuess = 0.3,
        int $maxIterations = 100,
        float $tolerance = 0.0001
    ): ?float {
        // 檢查價內價值
        $intrinsicValue = $this->calculateIntrinsicValue($spotPrice, $strikePrice, $optionType);
        
        if ($marketPrice < $intrinsicValue) {
            Log::warning('市場價格低於內在價值', [
                'market_price' => $marketPrice,
                'intrinsic_value' => $intrinsicValue
            ]);
            return null;
        }

        $sigma = $initialGuess;

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                // 計算理論價格
                $theoreticalPrice = $this->calculatePrice(
                    $spotPrice,
                    $strikePrice,
                    $timeToExpiry,
                    $riskFreeRate,
                    $sigma,
                    $optionType
                );

                // 計算價格差異
                $priceDiff = $theoreticalPrice - $marketPrice;

                // 如果差異小於容差，返回結果
                if (abs($priceDiff) < $tolerance) {
                    return round($sigma, 6);
                }

                // 計算 Vega 用於牛頓法
                $d1 = $this->calculateD1($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $sigma);
                $vega = $this->calculateVega($spotPrice, $d1, $timeToExpiry) * 100; // 乘以 100 因為之前除以 100

                // 避免除以零
                if ($vega < 0.0001) {
                    Log::warning('Vega 值過小', ['vega' => $vega]);
                    return null;
                }

                // 牛頓法更新
                $sigma = $sigma - $priceDiff / $vega;

                // 確保波動率在合理範圍內
                $sigma = max(0.001, min($sigma, 5.0)); // 0.1% 到 500%

            } catch (\Exception $e) {
                Log::error('隱含波動率計算錯誤', [
                    'iteration' => $i,
                    'sigma' => $sigma,
                    'error' => $e->getMessage()
                ]);
                return null;
            }
        }

        Log::warning('隱含波動率計算未收斂', [
            'iterations' => $maxIterations,
            'final_sigma' => $sigma
        ]);

        return null;
    }

    /**
     * 計算選擇權價內價值
     */
    public function calculateIntrinsicValue(
        float $spotPrice,
        float $strikePrice,
        string $optionType = 'call'
    ): float {
        if (strtolower($optionType) === 'call') {
            return max(0, $spotPrice - $strikePrice);
        } else {
            return max(0, $strikePrice - $spotPrice);
        }
    }

    /**
     * 計算選擇權時間價值
     */
    public function calculateTimeValue(
        float $optionPrice,
        float $spotPrice,
        float $strikePrice,
        string $optionType = 'call'
    ): float {
        $intrinsicValue = $this->calculateIntrinsicValue($spotPrice, $strikePrice, $optionType);
        return max(0, $optionPrice - $intrinsicValue);
    }

    /**
     * 判斷選擇權價性 (Moneyness)
     *
     * @return string ITM (價內), ATM (價平), OTM (價外)
     */
    public function getMoneyness(
        float $spotPrice,
        float $strikePrice,
        string $optionType = 'call',
        float $threshold = 0.02
    ): string {
        $ratio = $spotPrice / $strikePrice;

        if (abs($ratio - 1.0) <= $threshold) {
            return 'ATM'; // At The Money
        }

        if (strtolower($optionType) === 'call') {
            return $ratio > 1.0 ? 'ITM' : 'OTM'; // In/Out The Money
        } else {
            return $ratio < 1.0 ? 'ITM' : 'OTM';
        }
    }

    /**
     * 計算 d1
     */
    protected function calculateD1(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility
    ): float {
        $numerator = log($spotPrice / $strikePrice) 
                   + ($riskFreeRate + 0.5 * pow($volatility, 2)) * $timeToExpiry;
        $denominator = $volatility * sqrt($timeToExpiry);

        return $numerator / $denominator;
    }

    /**
     * 計算 d2
     */
    protected function calculateD2(
        float $d1,
        float $volatility,
        float $timeToExpiry
    ): float {
        return $d1 - $volatility * sqrt($timeToExpiry);
    }

    /**
     * 標準常態累積分佈函數 (CDF)
     * 使用 Abramowitz and Stegun 近似法
     */
    protected function normalCDF(float $x): float
    {
        if ($x < -7.0) return 0.0;
        if ($x > 7.0) return 1.0;

        // 使用誤差函數近似
        $erfValue = $this->erf($x / sqrt(2.0));
        return 0.5 * (1.0 + $erfValue);
    }

    /**
     * 標準常態機率密度函數 (PDF)
     */
    protected function normalPDF(float $x): float
    {
        return exp(-0.5 * $x * $x) / sqrt(2.0 * M_PI);
    }

    /**
     * 誤差函數 (Error Function)
     * 使用 Abramowitz and Stegun 公式 7.1.26
     */
    protected function erf(float $x): float
    {
        // 常數
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        // 保存符號
        $sign = ($x < 0) ? -1 : 1;
        $x = abs($x);

        // A&S formula 7.1.26
        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * 參數驗證
     */
    protected function validateParameters(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $volatility
    ): void {
        if ($spotPrice <= 0) {
            throw new \InvalidArgumentException('標的價格必須大於 0');
        }

        if ($strikePrice <= 0) {
            throw new \InvalidArgumentException('履約價格必須大於 0');
        }

        if ($timeToExpiry <= 0) {
            throw new \InvalidArgumentException('到期時間必須大於 0');
        }

        if ($volatility <= 0 || $volatility > 10) {
            throw new \InvalidArgumentException('波動率必須在 0 到 10 之間');
        }
    }
}