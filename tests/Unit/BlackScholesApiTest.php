<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BlackScholesApiTest extends TestCase
{
    // ==========================================
    // POST /api/black-scholes/calculate
    // ==========================================

    public function test_calculate_returns_success_with_valid_call_params(): void
    {
        $response = $this->postJson('/api/black-scholes/calculate', [
            'spot_price'    => 580,
            'strike_price'  => 600,
            'time_to_expiry'=> 0.25,
            'risk_free_rate'=> 0.015,
            'volatility'    => 0.25,
            'option_type'   => 'call',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure([
                     'data' => [
                         'theoretical_price',
                         'greeks' => ['delta', 'gamma', 'theta', 'vega', 'rho'],
                         'intrinsic_value',
                         'time_value',
                         'moneyness',
                     ]
                 ]);
    }

    public function test_calculate_returns_success_with_valid_put_params(): void
    {
        $response = $this->postJson('/api/black-scholes/calculate', [
            'spot_price'    => 580,
            'strike_price'  => 600,
            'time_to_expiry'=> 0.25,
            'risk_free_rate'=> 0.015,
            'volatility'    => 0.25,
            'option_type'   => 'put',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);
    }

    public function test_calculate_returns_422_when_missing_required_fields(): void
    {
        $response = $this->postJson('/api/black-scholes/calculate', [
            'spot_price' => 580,
            // missing strike_price, time_to_expiry, volatility, option_type
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    public function test_calculate_returns_422_with_invalid_option_type(): void
    {
        $response = $this->postJson('/api/black-scholes/calculate', [
            'spot_price'    => 580,
            'strike_price'  => 600,
            'time_to_expiry'=> 0.25,
            'volatility'    => 0.25,
            'option_type'   => 'invalid', // 不合法
        ]);

        $response->assertStatus(422);
    }

    public function test_calculate_returns_422_with_negative_spot_price(): void
    {
        $response = $this->postJson('/api/black-scholes/calculate', [
            'spot_price'    => -100,
            'strike_price'  => 600,
            'time_to_expiry'=> 0.25,
            'volatility'    => 0.25,
            'option_type'   => 'call',
        ]);

        $response->assertStatus(422);
    }

    public function test_theoretical_price_is_numeric_and_positive(): void
    {
        $response = $this->postJson('/api/black-scholes/calculate', [
            'spot_price'    => 100,
            'strike_price'  => 100,
            'time_to_expiry'=> 0.5,
            'risk_free_rate'=> 0.02,
            'volatility'    => 0.3,
            'option_type'   => 'call',
        ]);

        $response->assertStatus(200);
        $price = $response->json('data.theoretical_price');
        $this->assertIsNumeric($price);
        $this->assertGreaterThan(0, $price);
    }

    // ==========================================
    // POST /api/black-scholes/implied-volatility
    // ==========================================

    public function test_implied_volatility_returns_success(): void
    {
        $response = $this->postJson('/api/black-scholes/implied-volatility', [
            'spot_price'    => 100,
            'strike_price'  => 100,
            'time_to_expiry'=> 0.25,
            'risk_free_rate'=> 0.015,
            'market_price'  => 7.5,
            'option_type'   => 'call',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => ['implied_volatility']]);
    }

    public function test_implied_volatility_returns_422_when_missing_market_price(): void
    {
        $response = $this->postJson('/api/black-scholes/implied-volatility', [
            'spot_price'    => 100,
            'strike_price'  => 100,
            'time_to_expiry'=> 0.25,
            'option_type'   => 'call',
            // missing market_price
        ]);

        $response->assertStatus(422);
    }

    // ==========================================
    // POST /api/backtest/run
    // ==========================================

    public function test_backtest_run_returns_422_when_missing_fields(): void
    {
        $response = $this->postJson('/api/backtest/run', [
            'strategy_name' => 'sma_crossover',
            // missing stock_id, start_date, end_date
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    public function test_backtest_run_returns_422_with_invalid_strategy(): void
    {
        $response = $this->postJson('/api/backtest/run', [
            'stock_id'      => 1,
            'strategy_name' => 'not_a_strategy',
            'start_date'    => '2024-01-01',
            'end_date'      => '2024-06-30',
        ]);

        $response->assertStatus(422);
    }

    public function test_backtest_run_returns_422_when_end_before_start(): void
    {
        $response = $this->postJson('/api/backtest/run', [
            'stock_id'      => 1,
            'strategy_name' => 'sma_crossover',
            'start_date'    => '2024-06-01',
            'end_date'      => '2024-01-01', // end < start
        ]);

        $response->assertStatus(422);
    }

    // ==========================================
    // GET /api/backtest/strategies
    // ==========================================

    public function test_strategies_list_returns_array(): void
    {
        $response = $this->getJson('/api/backtest/strategies');

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['data' => [['name', 'display_name', 'description']]]);
    }

    public function test_strategies_list_includes_sma_crossover(): void
    {
        $response = $this->getJson('/api/backtest/strategies');

        $response->assertStatus(200);
        $strategies = collect($response->json('data'));
        $names = $strategies->pluck('name');
        $this->assertContains('sma_crossover', $names->toArray());
    }
}
