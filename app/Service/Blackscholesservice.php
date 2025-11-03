<?php

namespace App\Services;

use App\Models\Option;
use App\Models\Stock;

class BlackScholesService
{
    private const PI = 3.14159265359;
    private const E = 2.71828182846;
    
    /**
     * 計算 Black-Scholes 選擇權價格
     */
    public function calculatePrice(
        float $spotPrice,
        float $strikePrice,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility,
        string $optionType = 'call'
    ): array {
        // 計算 d1 和 d2
        $d1 = $this->calculateD1($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility);
        $d2 = $this->calculateD2($d1, $volatility, $timeToExpiry);
        
        // 計算選擇權價格
        if ($optionType === 'call') {
            $price = $this->calculateCallPrice($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $d1, $d2);
        } else {
            $price = $this->calculatePutPrice($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $d1, $d2);
        }
        
        // 計算 Greeks
        $greeks = $this->calculateGreeks($spotPrice, $strikePrice, $timeToExpiry, $riskFreeRate, $volatility, $d1, $d2, $optionType);
        
        return [
            'price' => round($price, 4),
            'greeks' => $greeks,
            'd1' => round($d1, 6),
            'd2' => round($d2, 6),
        ];
    }
    
    /**
     * 計算隱含波動率 (使用二分搜尋法)
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
        $lowerBound = 0.001;
        $upperBound = 5.0;
        $iteration = 0;
        
        while ($iteration < $maxIterations) {
            $midVolatility = ($lowerBound + $upperBound) / 2;
            
            $theoreticalPrice = $this->calculatePrice(
                $spotPrice,
                $strikePrice,
                $timeToExpiry,
                $riskFreeRate,
                $midVolatility,
                $optionType
            )['price'];
            
            $difference = $theoreticalPrice - $marketPrice;
            
            if (abs($difference) < $tolerance) {
                return round($midVolatility, 4);
            }
            
            if ($difference > 0) {
                $upperBound = $midVolatility;
            } else {
                $lowerBound = $midVolatility;
            }
            
            $iteration++;
        }
        
        return null; // 無法收斂
    }
    
    /**
     * 計算 d1
     */
    private function calculateD1(float $S, float $K, float $T, float $r, float $sigma): float
    {
        if ($T <= 0 || $sigma <= 0) {
            return 0;
        }
        
        $numerator = log($S / $K) + ($r + 0.5 * pow($sigma, 2)) * $T;
        $denominator = $sigma * sqrt($T);
        
        return $numerator / $denominator;
    }
    
    /**
     * 計算 d2
     */
    private function calculateD2(float $d1, float $sigma, float $T): float
    {
        return $d1 - $sigma * sqrt($T);
    }
    
    /**
     * 計算 Call 選擇權價格
     */
    private function calculateCallPrice(float $S, float $K, float $T, float $r, float $d1, float $d2): float
    {
        $discountFactor = exp(-$r * $T);
        return $S * $this->normalCDF($d1) - $K * $discountFactor * $this->normalCDF($d2);
    }
    
    /**
     * 計算 Put 選擇權價格
     */
    private function calculatePutPrice(float $S, float $K, float $T, float $r, float $d1, float $d2): float
    {
        $discountFactor = exp(-$r * $T);
        return $K * $discountFactor * $this->normalCDF(-$d2) - $S * $this->normalCDF(-$d1);
    }
    
    /**
     * 計算 Greeks
     */
    private function calculateGreeks(
        float $S,
        float $K,
        float $T,
        float $r,
        float $sigma,
        float $d1,
        float $d2,
        string $optionType
    ): array {
        $sqrtT = sqrt($T);
        $discountFactor = exp(-$r * $T);
        
        // Delta
        if ($optionType === 'call') {
            $delta = $this->normalCDF($d1);
        } else {
            $delta = $this->normalCDF($d1) - 1;
        }
        
        // Gamma
        $gamma = $this->normalPDF($d1) / ($S * $sigma * $sqrtT);
        
        // Theta
        $term1 = -($S * $this->normalPDF($d1) * $sigma) / (2 * $sqrtT);
        if ($optionType === 'call') {
            $theta = $term1 - $r * $K * $discountFactor * $this->normalCDF($d2);
        } else {
            $theta = $term1 + $r * $K * $discountFactor * $this->normalCDF(-$d2);
        }
        $theta /= 365; // 每日 Theta
        
        // Vega
        $vega = $S * $this->normalPDF($d1) * $sqrtT / 100; // 每 1% 波動率變化
        
        // Rho
        if ($optionType === 'call') {
            $rho = $K * $T * $discountFactor * $this->normalCDF($d2) / 100;
        } else {
            $rho = -$K * $T * $discountFactor * $this->normalCDF(-$d2) / 100;
        }
        
        return [
            'delta' => round($delta, 5),
            'gamma' => round($gamma, 5),
            'theta' => round($theta, 5),
            'vega' => round($vega, 5),
            'rho' => round($rho, 5),
        ];
    }
    
    /**
     * 標準常態分佈的累積分佈函數 (CDF)
     */
    private function normalCDF(float $x): float
    {
        $t = 1 / (1 + 0.2316419 * abs($x));
        $d = 0.3989423 * exp(-$x * $x / 2);
        $p = $d * $t * (0.3193815 + $t * (-0.3565638 + $t * (1.781478 + $t * (-1.821256 + $t * 1.330274))));
        
        if ($x > 0) {
            return 1 - $p;
        } else {
            return $p;
        }
    }
    
    /**
     * 標準常態分佈的機率密度函數 (PDF)
     */
    private function normalPDF(float $x): float
    {
        return exp(-0.5 * $x * $x) / sqrt(2 * self::PI);
    }
    
    /**
     * 批量計算選擇權鏈的理論價格
     */
    public function calculateOptionChain(
        float $spotPrice,
        array $strikes,
        float $timeToExpiry,
        float $riskFreeRate,
        float $volatility
    ): array {
        $chain = [];
        
        foreach ($strikes as $strike) {
            $callPrice = $this->calculatePrice($spotPrice, $strike, $timeToExpiry, $riskFreeRate, $volatility, 'call');
            $putPrice = $this->calculatePrice($spotPrice, $strike, $timeToExpiry, $riskFreeRate, $volatility, 'put');
            
            $chain[] = [
                'strike' => $strike,
                'call' => $callPrice,
                'put' => $putPrice,
                'moneyness' => $spotPrice / $strike,
            ];
        }
        
        return $chain;
    }
    
    /**
     * 計算波動率微笑
     */
    public function calculateVolatilitySmile(
        float $spotPrice,
        array $optionData, // [ ['strike' => x, 'market_price' => y, 'type' => 'call/put'], ... ]
        float $timeToExpiry,
        float $riskFreeRate
    ): array {
        $smile = [];
        
        foreach ($optionData as $option) {
            $iv = $this->calculateImpliedVolatility(
                $option['market_price'],
                $spotPrice,
                $option['strike'],
                $timeToExpiry,
                $riskFreeRate,
                $option['type'] ?? 'call'
            );
            
            if ($iv !== null) {
                $smile[] = [
                    'strike' => $option['strike'],
                    'implied_volatility' => $iv,
                    'moneyness' => $spotPrice / $option['strike'],
                ];
            }
        }
        
        return $smile;
    }
}