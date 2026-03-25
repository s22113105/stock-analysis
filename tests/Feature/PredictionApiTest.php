<?php

namespace Tests\Feature;

use App\Models\Prediction;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PredictionController Feature Test
 *
 * 測試 /api/predictions/* 各端點的 HTTP 行為，
 * 不實際執行 Python 模型（Service 層透過 mock 回傳固定資料）。
 */
class PredictionApiTest extends TestCase
{
    use RefreshDatabase;

    // ==========================================
    // GET /api/predictions/history
    // ==========================================

    public function test_history_returns_paginated_list(): void
    {
        // 建立股票與預測記錄
        $stock = Stock::create([
            'symbol'    => '2330',
            'name'      => '台積電',
            'exchange'  => 'TWSE',
            'is_active' => true,
        ]);

        Prediction::create([
            'predictable_type'  => Stock::class,
            'predictable_id'    => $stock->id,
            'model_type'        => 'lstm',
            'prediction_date'   => now()->toDateString(),
            'prediction_days'   => 1,
            'predicted_price'   => 600.00,
            'predicted_volatility' => 0.25,
            'upper_bound'       => 620.00,
            'lower_bound'       => 580.00,
            'confidence_level'  => 95,
        ]);

        $response = $this->getJson('/api/predictions/history');

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure([
                     'data' => ['data', 'current_page', 'total'],
                 ]);
    }

    public function test_history_filters_by_model_type(): void
    {
        $stock = Stock::create([
            'symbol'    => '2317',
            'name'      => '鴻海',
            'exchange'  => 'TWSE',
            'is_active' => true,
        ]);

        // 建立 lstm 和 arima 各一筆
        foreach (['lstm', 'arima'] as $model) {
            Prediction::create([
                'predictable_type'  => Stock::class,
                'predictable_id'    => $stock->id,
                'model_type'        => $model,
                'prediction_date'   => now()->toDateString(),
                'prediction_days'   => 1,
                'predicted_price'   => 100.00,
                'confidence_level'  => 95,
            ]);
        }

        $response = $this->getJson('/api/predictions/history?model_type=lstm');

        $response->assertStatus(200);
        $items = $response->json('data.data');
        foreach ($items as $item) {
            $this->assertEquals('lstm', $item['model_type']);
        }
    }

    public function test_history_returns_422_with_invalid_model_type(): void
    {
        $response = $this->getJson('/api/predictions/history?model_type=invalid');
        $response->assertStatus(422);
    }

    // ==========================================
    // GET /api/predictions/{id}
    // ==========================================

    public function test_show_returns_prediction_detail(): void
    {
        $stock = Stock::create([
            'symbol'    => '2330',
            'name'      => '台積電',
            'exchange'  => 'TWSE',
            'is_active' => true,
        ]);

        $prediction = Prediction::create([
            'predictable_type'  => Stock::class,
            'predictable_id'    => $stock->id,
            'model_type'        => 'arima',
            'prediction_date'   => now()->toDateString(),
            'prediction_days'   => 1,
            'predicted_price'   => 610.00,
            'confidence_level'  => 95,
        ]);

        $response = $this->getJson("/api/predictions/{$prediction->id}");

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.id', $prediction->id)
                 ->assertJsonPath('data.model_type', 'arima');
    }

    public function test_show_returns_404_for_nonexistent_id(): void
    {
        $response = $this->getJson('/api/predictions/99999');
        $response->assertStatus(404);
    }

    // ==========================================
    // POST /api/predictions/run — 參數驗證
    // ==========================================

    public function test_run_returns_422_without_target(): void
    {
        $response = $this->postJson('/api/predictions/run', [
            'model_type' => 'lstm',
            // 沒有 stock_symbol 也沒有 underlying
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    public function test_run_returns_422_without_model_type(): void
    {
        $response = $this->postJson('/api/predictions/run', [
            'stock_symbol' => '2330',
            // 沒有 model_type
        ]);

        $response->assertStatus(422);
    }

    public function test_run_returns_422_with_invalid_model_type(): void
    {
        $response = $this->postJson('/api/predictions/run', [
            'stock_symbol' => '2330',
            'model_type'   => 'random_forest', // 不合法
        ]);

        $response->assertStatus(422);
    }

    public function test_run_returns_422_with_prediction_days_too_large(): void
    {
        $response = $this->postJson('/api/predictions/run', [
            'stock_symbol'    => '2330',
            'model_type'      => 'lstm',
            'prediction_days' => 999, // 超過最大值 30
        ]);

        $response->assertStatus(422);
    }

    // ==========================================
    // POST /api/predictions/lstm|arima|garch — 捷徑路由
    // ==========================================

    public function test_lstm_shortcut_returns_422_without_target(): void
    {
        $response = $this->postJson('/api/predictions/lstm', []);
        // 沒 stock_symbol/underlying → 422
        $response->assertStatus(422);
    }

    public function test_arima_shortcut_returns_422_without_target(): void
    {
        $response = $this->postJson('/api/predictions/arima', []);
        $response->assertStatus(422);
    }

    public function test_garch_shortcut_returns_422_without_target(): void
    {
        $response = $this->postJson('/api/predictions/garch', []);
        $response->assertStatus(422);
    }
}
