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
 * - 到期損益計算
 * - Greeks 時間衰減分析
 * 
 * @version 2.0 改進版
 * - 調整 Moneyness 閾值至 0.5%
 * - 增加 Greeks 精度
 * - 新增到期損益計算
 * - 新增時間衰減分析
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
            return round($this->normalCDF($d1), 6); // 改進：提高精度至 6 位
        } else {
            return round($this->normalCDF($d1) - 1, 6);
        }
    }

    /**
     * 計算 Gamma
     * Gamma = N'(d1) / (S * σ * sqrt(T))
     * 
     * 改進：提高精度至 6 位小數，對 Gamma Scalping 策略很重要
     */
    public function calculateGamma(
        float $spotPrice,
        float $d1,
        float $volatility,
        float $timeToExpiry
    ): float {
        $nPrime = $this->normalPDF($d1);
        $gamma = $nPrime / ($spotPrice * $volatility * sqrt($timeToExpiry));
        
        return round($gamma, 6); // 改進：從 5 位提升至 6 位
    }

    /**
     * 計算 Theta (每日時間價值衰減)
     * 
     * Call Theta = -[S * N'(d1) * σ / (2 * sqrt(T))] - r * K * e^(-rT) * N(d2)
     * Put Theta = -[S * N'(d1) * σ / (2 * sqrt(T))] + r * K * e^(-rT) * N(-d2)
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
        $nPrime = $this->normalPDF($d1);
        $sqrtT = sqrt($timeToExpiry);
        
        // 第一項：時間價值衰減 (兩種選擇權相同)
        $term1 = -($spotPrice * $nPrime * $volatility) / (2 * $sqrtT);
        
        if (strtolower($optionType) === 'call') {
            // Call: 減去利息收益
            $term2 = -$riskFreeRate * $strikePrice * exp(-$riskFreeRate * $timeToExpiry) * $this->normalCDF($d2);
        } else {
            // Put: 加上利息收益
            $term2 = $riskFreeRate * $strikePrice * exp(-$riskFreeRate * $timeToExpiry) * $this->normalCDF(-$d2);
        }
        
        // 轉換為每日 Theta (年化值 / 365)
        $theta = ($term1 + $term2) / 365;
        
        return round($theta, 4);
    }

    /**
     * 計算 Vega
     * Vega = S * sqrt(T) * N'(d1)
     * 
     * 注意：返回的是 1% 波動率變動的影響
     */
    public function calculateVega(
        float $spotPrice,
        float $d1,
        float $timeToExpiry
    ): float {
        $nPrime = $this->normalPDF($d1);
        $vega = $spotPrice * sqrt($timeToExpiry) * $nPrime;
        
        // 轉換為每 1% 波動率變動的價值
        $vega = $vega / 100;
        
        return round($vega, 4);
    }

    /**
     * 計算 Rho
     * Call Rho = K * T * e^(-rT) * N(d2)
     * Put Rho = -K * T * e^(-rT) * N(-d2)
     * 
     * 注意：返回的是 1% 利率變動的影響
     */
    public function calculateRho(
        float $strikePrice,
        float $d2,
        float $timeToExpiry,
        float $riskFreeRate,
        string $optionType = 'call'
    ): float {
        $discountFactor = exp(-$riskFreeRate * $timeToExpiry);
        
        if (strtolower($optionType) === 'call') {
            $rho = $strikePrice * $timeToExpiry * $discountFactor * $this->normalCDF($d2);
        } else {
            $rho = -$strikePrice * $timeToExpiry * $discountFactor * $this->normalCDF(-$d2);
        }
        
        // 轉換為每 1% 利率變動的價值
        $rho = $rho / 100;
        
        return round($rho, 4);
    }

    /**
     * 計算隱含波動率 (使用 Newton-Raphson 法)
     *
     * @param float $marketPrice 市場價格
     * @param float $spotPrice 標的資產現價
     * @param float $strikePrice 履約價格
     * @param float $timeToExpiry 到期時間(年)
     * @param float $riskFreeRate 無風險利率
     * @param string $optionType 選擇權類型
     * @param float $tolerance 收斂容許誤差
     * @param int $maxIterations 最大迭代次數
     * @return float|null 隱含波動率，失敗返回 null
     */
    public function calculateImpliedVolatility(
        float $marketPrice,
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        string $optionType = 'call',
        float $tolerance = 0.0001,
        int $maxIterations = 100
    ): ?float {
        // 初始猜測值
        $sigma = 0.3; // 30% 波動率作為起點

        for ($i = 0; $i < $maxIterations; $i++) {
            try {
                // 計算當前波動率下的理論價格
                $theoreticalPrice = $this->calculatePrice(
                    $spotPrice,
                    $strikePrice,
                    $timeToExpiry,
                    $riskFreeRate,
                    $sigma,
                    $optionType
                );

                // 價格差異
                $priceDiff = $theoreticalPrice - $marketPrice;

                // 檢查是否收斂
                if (abs($priceDiff) < $tolerance) {
                    return round($sigma, 6);
                }

                // 計算 Vega (導數)
                $d1 = $this->calculateD1($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $sigma);
                $vega = $spotPrice * sqrt($timeToExpiry) * $this->normalPDF($d1);

                // 避免除以零
                if (abs($vega) < 1e-10) {
                    Log::warning('IV 計算 Vega 過小', ['sigma' => $sigma, 'vega' => $vega]);
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
     * 計算選擇權內在價值
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
     * 改進：調整閾值從 2% 降至 0.5%，更精確判斷 ATM
     *
     * @return string ITM (價內), ATM (價平), OTM (價外)
     */
    public function getMoneyness(
        float $spotPrice,
        float $strikePrice,
        string $optionType = 'call',
        float $threshold = 0.005 // 改進：從 0.02 調整為 0.005 (0.5%)
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
     * 新增：計算到期損益 (Payoff)
     * 
     * @param float $strikePrice 履約價
     * @param float $premium 權利金成本
     * @param string $optionType 選擇權類型
     * @param string $position 部位方向 (long/short)
     * @param array $spotPrices 標的價格陣列
     * @return array 損益陣列
     */
    public function calculatePayoff(
        float $strikePrice,
        float $premium,
        string $optionType = 'call',
        string $position = 'long',
        array $spotPrices = []
    ): array {
        $payoffs = [];
        $multiplier = strtolower($position) === 'long' ? 1 : -1;

        foreach ($spotPrices as $spot) {
            if (strtolower($optionType) === 'call') {
                // Call 到期價值 = max(S - K, 0)
                $intrinsic = max(0, $spot - $strikePrice);
            } else {
                // Put 到期價值 = max(K - S, 0)
                $intrinsic = max(0, $strikePrice - $spot);
            }

            // 損益 = (內在價值 - 權利金) * 部位方向
            $payoff = ($intrinsic - $premium) * $multiplier;
            $payoffs[] = round($payoff, 2);
        }

        return $payoffs;
    }

    /**
     * 新增：計算 Greeks 時間衰減曲線
     * 
     * @param float $spotPrice
     * @param float $strikePrice
     * @param float $currentTimeToExpiry 當前距到期時間(年)
     * @param float $riskFreeRate
     * @param float $volatility
     * @param string $optionType
     * @param int $points 計算點數
     * @return array 時間衰減數據
     */
    public function calculateTimeDecay(
        float $spotPrice,
        float $strikePrice,
        float $currentTimeToExpiry,
        float $riskFreeRate,
        float $volatility,
        string $optionType = 'call',
        int $points = 10
    ): array {
        $result = [
            'days' => [],
            'prices' => [],
            'deltas' => [],
            'gammas' => [],
            'thetas' => [],
            'vegas' => []
        ];

        $currentDays = $currentTimeToExpiry * 365;
        $interval = $currentDays / $points;

        for ($i = 0; $i <= $points; $i++) {
            $daysRemaining = max(1, $currentDays - ($i * $interval));
            $timeToExpiry = $daysRemaining / 365;

            $result['days'][] = round($daysRemaining);

            $price = $this->calculatePrice(
                $spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility, $optionType
            );
            $result['prices'][] = $price;

            $greeks = $this->calculateGreeks(
                $spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility, $optionType
            );

            $result['deltas'][] = $greeks['delta'];
            $result['gammas'][] = $greeks['gamma'];
            $result['thetas'][] = $greeks['theta'];
            $result['vegas'][] = $greeks['vega'];
        }

        return $result;
    }

    /**
     * 新增：批次計算不同股價下的選擇權價格 (用於精確繪圖)
     * 
     * @param float $strikePrice
     * @param float $timeToExpiry
     * @param float $riskFreeRate
     * @param float $volatility
     * @param string $optionType
     * @param array $spotPrices
     * @return array
     */
    public function batchCalculatePrices(
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility,
        string $optionType,
        array $spotPrices
    ): array {
        $prices = [];

        foreach ($spotPrices as $spot) {
            $prices[] = $this->calculatePrice(
                $spot, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility, $optionType
            );
        }

        return $prices;
    }

    /**
     * 計算 d1
     */
    private function calculateD1(
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
    private function calculateD2(float $d1, float $volatility, float $timeToExpiry): float
    {
        return $d1 - $volatility * sqrt($timeToExpiry);
    }

    /**
     * 標準常態分佈累積分佈函數 (CDF)
     * 使用 Abramowitz and Stegun 近似法
     */
    private function normalCDF(float $x): float
    {
        $a1 =  0.254829592;
        $a2 = -0.284496736;
        $a3 =  1.421413741;
        $a4 = -1.453152027;
        $a5 =  1.061405429;
        $p  =  0.3275911;

        $sign = $x < 0 ? -1 : 1;
        $x = abs($x) / sqrt(2);

        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return 0.5 * (1.0 + $sign * $y);
    }

    /**
     * 標準常態分佈機率密度函數 (PDF)
     */
    private function normalPDF(float $x): float
    {
        return exp(-0.5 * pow($x, 2)) / sqrt(2 * M_PI);
    }

    /**
     * 參數驗證
     */
    private function validateParameters(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $volatility
    ): void {
        if ($spotPrice <= 0) {
            throw new \InvalidArgumentException('標的資產價格必須大於零');
        }
        if ($strikePrice <= 0) {
            throw new \InvalidArgumentException('履約價格必須大於零');
        }
        if ($timeToExpiry <= 0) {
            throw new \InvalidArgumentException('到期時間必須大於零');
        }
        if ($volatility <= 0) {
            throw new \InvalidArgumentException('波動率必須大於零');
        }
    }
}