<?php

namespace Tests\Unit;

use App\Services\BlackScholesService;
use PHPUnit\Framework\TestCase;

class BlackScholesServiceTest extends TestCase
{
    protected BlackScholesService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BlackScholesService();
    }

    // ==========================================
    // calculatePrice — Call 定價
    // ==========================================

    public function test_call_price_is_positive(): void
    {
        $price = $this->service->calculatePrice(100, 100, 0.25, 0.015, 0.3, 'call');
        $this->assertGreaterThan(0, $price);
    }

    public function test_put_price_is_positive(): void
    {
        $price = $this->service->calculatePrice(100, 100, 0.25, 0.015, 0.3, 'put');
        $this->assertGreaterThan(0, $price);
    }

    public function test_call_price_deep_in_the_money(): void
    {
        // 深度價內 Call，價格應接近 S - K * e^(-rT)
        $price = $this->service->calculatePrice(150, 100, 0.25, 0.015, 0.3, 'call');
        $this->assertGreaterThan(49, $price); // 至少高於內在價值 50
    }

    public function test_put_price_deep_in_the_money(): void
    {
        // 深度價內 Put
        $price = $this->service->calculatePrice(50, 100, 0.25, 0.015, 0.3, 'put');
        $this->assertGreaterThan(49, $price);
    }

    public function test_call_price_deep_out_of_money_is_near_zero(): void
    {
        // 深度價外 Call，價格應接近 0
        $price = $this->service->calculatePrice(50, 200, 0.01, 0.015, 0.3, 'call');
        $this->assertLessThan(0.01, $price);
    }

    public function test_put_call_parity(): void
    {
        // Put-Call Parity: C - P = S - K * e^(-rT)
        $S = 100; $K = 100; $T = 0.25; $r = 0.015; $sigma = 0.3;

        $call = $this->service->calculatePrice($S, $K, $T, $r, $sigma, 'call');
        $put  = $this->service->calculatePrice($S, $K, $T, $r, $sigma, 'put');

        $parity = $S - $K * exp(-$r * $T);
        $this->assertEqualsWithDelta($parity, $call - $put, 0.01);
    }

    public function test_higher_volatility_gives_higher_price(): void
    {
        $lowVol  = $this->service->calculatePrice(100, 100, 0.25, 0.015, 0.2, 'call');
        $highVol = $this->service->calculatePrice(100, 100, 0.25, 0.015, 0.5, 'call');
        $this->assertGreaterThan($lowVol, $highVol);
    }

    public function test_longer_time_gives_higher_price(): void
    {
        $shortTime = $this->service->calculatePrice(100, 100, 0.1, 0.015, 0.3, 'call');
        $longTime  = $this->service->calculatePrice(100, 100, 1.0, 0.015, 0.3, 'call');
        $this->assertGreaterThan($shortTime, $longTime);
    }

    // ==========================================
    // calculateGreeks
    // ==========================================

    public function test_call_delta_between_zero_and_one(): void
    {
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.015, 0.3, 'call');
        $this->assertGreaterThan(0, $greeks['delta']);
        $this->assertLessThan(1, $greeks['delta']);
    }

    public function test_put_delta_between_minus_one_and_zero(): void
    {
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.015, 0.3, 'put');
        $this->assertLessThan(0, $greeks['delta']);
        $this->assertGreaterThan(-1, $greeks['delta']);
    }

    public function test_gamma_is_positive(): void
    {
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.015, 0.3, 'call');
        $this->assertGreaterThan(0, $greeks['gamma']);
    }

    public function test_vega_is_positive(): void
    {
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.015, 0.3, 'call');
        $this->assertGreaterThan(0, $greeks['vega']);
    }

    public function test_call_theta_is_negative(): void
    {
        // Theta 通常為負（時間流逝使期權價值下降）
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.015, 0.3, 'call');
        $this->assertLessThan(0, $greeks['theta']);
    }

    public function test_atm_call_delta_near_half(): void
    {
        // ATM Call 的 Delta 應接近 0.5
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.0, 0.3, 'call');
        $this->assertEqualsWithDelta(0.5, $greeks['delta'], 0.05);
    }

    public function test_greeks_returns_all_five_keys(): void
    {
        $greeks = $this->service->calculateGreeks(100, 100, 0.25, 0.015, 0.3, 'call');
        foreach (['delta', 'gamma', 'theta', 'vega', 'rho'] as $key) {
            $this->assertArrayHasKey($key, $greeks);
        }
    }

    // ==========================================
    // calculateImpliedVolatility
    // ==========================================

    public function test_implied_volatility_round_trip(): void
    {
        // 用已知波動率算出價格，再反推 IV，應還原
        $knownVol = 0.3;
        $marketPrice = $this->service->calculatePrice(100, 100, 0.25, 0.015, $knownVol, 'call');

        $iv = $this->service->calculateImpliedVolatility(
            $marketPrice, 100, 100, 0.25, 0.015, 'call'
        );

        $this->assertNotNull($iv);
        $this->assertEqualsWithDelta($knownVol, $iv, 0.005);
    }

    public function test_implied_volatility_put_round_trip(): void
    {
        $knownVol = 0.25;
        $marketPrice = $this->service->calculatePrice(100, 105, 0.5, 0.015, $knownVol, 'put');

        $iv = $this->service->calculateImpliedVolatility(
            $marketPrice, 100, 105, 0.5, 0.015, 'put'
        );

        $this->assertNotNull($iv);
        $this->assertEqualsWithDelta($knownVol, $iv, 0.005);
    }
}
