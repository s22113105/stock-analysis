<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacktestResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'strategy_name',
        'start_date',
        'end_date',
        'initial_capital',
        'final_capital',
        'total_return',
        'sharpe_ratio',
        'max_drawdown',
        'total_trades',
        'winning_trades',
        'losing_trades',
        'win_rate',
        'avg_profit',
        'avg_loss',
        'strategy_params',
        'daily_returns',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'initial_capital' => 'decimal:2',
        'final_capital' => 'decimal:2',
        'total_return' => 'decimal:4',
        'sharpe_ratio' => 'decimal:4',
        'max_drawdown' => 'decimal:4',
        'total_trades' => 'integer',
        'winning_trades' => 'integer',
        'losing_trades' => 'integer',
        'win_rate' => 'decimal:4',
        'avg_profit' => 'decimal:2',
        'avg_loss' => 'decimal:2',
        'strategy_params' => 'array',
        'daily_returns' => 'array',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 依策略名稱查詢
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
     * 計算獲利金額
     */
    public function getProfitAttribute()
    {
        return $this->final_capital - $this->initial_capital;
    }

    /**
     * 計算獲利率百分比
     */
    public function getReturnPercentAttribute()
    {
        if ($this->initial_capital > 0) {
            return ($this->profit / $this->initial_capital) * 100;
        }
        return 0;
    }

    /**
     * 計算賺賠比
     */
    public function getProfitLossRatioAttribute()
    {
        if ($this->avg_loss != 0) {
            return abs($this->avg_profit / $this->avg_loss);
        }
        return null;
    }

    /**
     * 取得績效評分
     */
    public function getPerformanceScoreAttribute()
    {
        $score = 0;
        
        // 總報酬率權重 40%
        $score += $this->total_return * 40;
        
        // 夏普比率權重 30%
        if ($this->sharpe_ratio) {
            $score += $this->sharpe_ratio * 30;
        }
        
        // 勝率權重 20%
        if ($this->win_rate) {
            $score += $this->win_rate * 20;
        }
        
        // 最大回撤權重 10% (負向指標)
        if ($this->max_drawdown) {
            $score -= abs($this->max_drawdown) * 10;
        }
        
        return round($score, 2);
    }
}