<?php

namespace Tests\Unit;

use App\Models\Stock;
use App\Services\BacktestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * BacktestService 單元測試
 *
 * 使用 RefreshDatabase 讓每個測試前資料庫乾淨，
 * 並建立真實的 App\Models\Stock 以符合 Service 型別宣告。
 */
class BacktestServiceTest extends TestCase
{
    use RefreshDatabase;

    protected BacktestService $service;
    protected Stock $stock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BacktestService();

        // 建立真實 Stock Model（RefreshDatabase 確保每次乾淨）
        $this->stock = Stock::create([
            'symbol'    => '2330',
            'name'      => '台積電',
            'exchange'  => 'TWSE',
            'is_active' => true,
        ]);
    }

    // ==========================================
    // 輔助：建立假價格資料
    // ==========================================

    /**
     * 產生 N 天遞增趨勢的假股價 Collection
     */
    private function makePrices(int $days = 60, float $start = 100.0, float $step = 0.5): Collection
    {
        $items = [];
        $date  = new \DateTime('2024-01-02');

        for ($i = 0; $i < $days; $i++) {
            while (in_array((int) $date->format('N'), [6, 7])) {
                $date->modify('+1 day');
            }
            $close   = round($start + $i * $step, 2);
            $items[] = (object) [
                'trade_date' => $date->format('Y-m-d'),
                'open'       => $close - 0.5,
                'high'       => $close + 1.0,
                'low'        => $close - 1.0,
                'close'      => $close,
                'volume'     => 10000,
            ];
            $date->modify('+1 day');
        }

        return collect($items);
    }

    /**
     * 產生震盪區間的假股價
     */
    private function makeOscillatingPrices(int $days = 100): Collection
    {
        $items = [];
        $date  = new \DateTime('2024-01-02');

        for ($i = 0; $i < $days; $i++) {
            while (in_array((int) $date->format('N'), [6, 7])) {
                $date->modify('+1 day');
            }
            $close   = round(100 + 10 * sin($i * 0.2), 2);
            $items[] = (object) [
                'trade_date' => $date->format('Y-m-d'),
                'open'       => $close - 0.5,
                'high'       => $close + 1.5,
                'low'        => $close - 1.5,
                'close'      => $close,
                'volume'     => 10000,
            ];
            $date->modify('+1 day');
        }

        return collect($items);
    }

    // ==========================================
    // 測試：performance 結果欄位
    // ==========================================

    public function test_performance_returns_required_keys(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(60),
            'sma_crossover',
            100000,
            ['short_period' => 5, 'long_period' => 20]
        );

        foreach ([
            'initial_capital', 'final_capital', 'total_return',
            'annual_return', 'sharpe_ratio', 'max_drawdown',
            'win_rate', 'total_trades',
        ] as $key) {
            $this->assertArrayHasKey($key, $result['performance'], "Missing key: {$key}");
        }
    }

    public function test_initial_capital_preserved_in_performance(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(60),
            'sma_crossover',
            200000,
            ['short_period' => 5, 'long_period' => 20]
        );

        $this->assertEquals(200000, $result['performance']['initial_capital']);
    }

    public function test_win_rate_between_0_and_100(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makeOscillatingPrices(100),
            'sma_crossover',
            100000,
            ['short_period' => 5, 'long_period' => 20]
        );

        $this->assertGreaterThanOrEqual(0,  $result['performance']['win_rate']);
        $this->assertLessThanOrEqual(100,   $result['performance']['win_rate']);
    }

    public function test_max_drawdown_is_non_negative(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(60),
            'sma_crossover',
            100000,
            ['short_period' => 5, 'long_period' => 20]
        );

        $this->assertGreaterThanOrEqual(0, $result['performance']['max_drawdown']);
    }

    public function test_total_trades_is_non_negative_integer(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(60),
            'sma_crossover',
            100000,
            ['short_period' => 5, 'long_period' => 20]
        );

        $this->assertIsInt($result['performance']['total_trades']);
        $this->assertGreaterThanOrEqual(0, $result['performance']['total_trades']);
    }

    public function test_uptrend_final_capital_is_positive(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(80, 100, 1.5),
            'sma_crossover',
            100000,
            ['short_period' => 5, 'long_period' => 20]
        );

        $this->assertGreaterThan(0, $result['performance']['final_capital']);
    }

    // ==========================================
    // 測試：未知策略拋出例外
    // ==========================================

    public function test_unknown_strategy_throws_exception(): void
    {
        $this->expectException(\Exception::class);

        $this->service->runBacktest(
            $this->stock,
            $this->makePrices(60),
            'unknown_strategy',
            100000
        );
    }

    // ==========================================
    // 測試：資料不足時無交易
    // ==========================================

    public function test_empty_trades_with_too_few_prices(): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(5),
            'sma_crossover',
            100000,
            ['short_period' => 5, 'long_period' => 20]
        );

        $this->assertEquals(0, $result['performance']['total_trades']);
    }

    // ==========================================
    // 測試：各策略可正常執行（data provider）
    // ==========================================

    /** @dataProvider strategyProvider */
    public function test_strategy_runs_without_error(string $strategy, array $params): void
    {
        $result = $this->service->runBacktest(
            $this->stock,
            $this->makePrices(80),
            $strategy,
            100000,
            $params
        );

        $this->assertArrayHasKey('performance', $result);
        $this->assertArrayHasKey('trades', $result);
    }

    public static function strategyProvider(): array
    {
        return [
            'sma_crossover'   => ['sma_crossover',   ['short_period' => 5, 'long_period' => 20]],
            'macd'            => ['macd',             ['fast_period' => 12, 'slow_period' => 26, 'signal_period' => 9]],
            'rsi'             => ['rsi',              ['period' => 14, 'oversold' => 30, 'overbought' => 70]],
            'bollinger_bands' => ['bollinger_bands',  ['period' => 20, 'std_dev' => 2]],
        ];
    }
}
