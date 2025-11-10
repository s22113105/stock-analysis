<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'strategy_name',
        'stock_id',
        'start_date',
        'end_date',
        'initial_capital',
        'final_capital',
        'total_return',
        'annual_return',
        'sharpe_ratio',
        'sortino_ratio',
        'max_drawdown',
        'win_rate',
        'total_trades',
        'winning_trades',
        'losing_trades',
        'avg_win',
        'avg_loss',
        'profit_factor',
        'volatility',
        'strategy_parameters',
        'equity_curve',
        'trade_history',
        'performance_metrics',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'initial_capital' => 'decimal:2',
        'final_capital' => 'decimal:2',
        'total_return' => 'decimal:2',
        'annual_return' => 'decimal:2',
        'sharpe_ratio' => 'decimal:4',
        'sortino_ratio' => 'decimal:4',
        'max_drawdown' => 'decimal:2',
        'win_rate' => 'decimal:2',
        'avg_win' => 'decimal:2',
        'avg_loss' => 'decimal:2',
        'profit_factor' => 'decimal:4',
        'volatility' => 'decimal:4',
        'strategy_parameters' => 'array',
        'equity_curve' => 'array',
        'trade_history' => 'array',
        'performance_metrics' => 'array',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 依策略查詢
     */
    public function scopeByStrategy($query, string $strategyName)
    {
        return $query->where('strategy_name', $strategyName);
    }

    /**
     * 依日期範圍查詢
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->where('start_date', '>=', $startDate)
                     ->where('end_date', '<=', $endDate);
    }

    /**
     * 計算獲利
     */
    public function getProfitAttribute(): float
    {
        return $this->final_capital - $this->initial_capital;
    }

    /**
     * 計算報酬率
     */
    public function getReturnPercentAttribute(): float
    {
        return $this->total_return;
    }

    /**
     * 計算盈虧比
     */
    public function getProfitLossRatioAttribute(): ?float
    {
        if ($this->avg_loss != 0) {
            return abs($this->avg_win / $this->avg_loss);
        }
        return null;
    }

    /**
     * 計算績效評分
     */
    public function getPerformanceScoreAttribute(): float
    {
        $score = 0;
        
        // 報酬率權重: 40%
        $score += ($this->total_return / 100) * 40;
        
        // 夏普比率權重: 30%
        if ($this->sharpe_ratio) {
            $score += min($this->sharpe_ratio, 3) / 3 * 30;
        }
        
        // 勝率權重: 20%
        if ($this->win_rate) {
            $score += ($this->win_rate / 100) * 20;
        }
        
        // 回撤懲罰: 10%
        if ($this->max_drawdown) {
            $score += (1 - min(abs($this->max_drawdown) / 50, 1)) * 10;
        }
        
        return round($score, 2);
    }
}