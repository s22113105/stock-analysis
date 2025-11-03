<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prediction extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_id',
        'prediction_date',
        'target_date',
        'model_type',
        'predicted_price',
        'predicted_volatility',
        'confidence_upper',
        'confidence_lower',
        'accuracy',
        'model_params',
    ];

    protected $casts = [
        'prediction_date' => 'date',
        'target_date' => 'date',
        'predicted_price' => 'decimal:2',
        'predicted_volatility' => 'decimal:4',
        'confidence_upper' => 'decimal:2',
        'confidence_lower' => 'decimal:2',
        'accuracy' => 'decimal:4',
        'model_params' => 'array',
    ];

    /**
     * 所屬的股票
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * 依模型類型查詢
     */
    public function scopeByModel($query, string $modelType)
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * 依預測日期查詢
     */
    public function scopeForPredictionDate($query, $date)
    {
        return $query->where('prediction_date', $date);
    }

    /**
     * 依目標日期查詢
     */
    public function scopeForTargetDate($query, $date)
    {
        return $query->where('target_date', $date);
    }

    /**
     * 取得最新的預測
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('prediction_date', 'desc');
    }

    /**
     * 計算預測區間
     */
    public function getPredictionRangeAttribute()
    {
        if ($this->confidence_upper && $this->confidence_lower) {
            return $this->confidence_upper - $this->confidence_lower;
        }
        return null;
    }

    /**
     * 計算預測誤差
     */
    public function calculateError()
    {
        // 取得實際價格
        $actualPrice = StockPrice::where('stock_id', $this->stock_id)
            ->where('trade_date', $this->target_date)
            ->value('close_price');

        if (!$actualPrice || !$this->predicted_price) {
            return null;
        }

        // 計算絕對誤差
        $absoluteError = abs($actualPrice - $this->predicted_price);

        // 計算百分比誤差
        $percentageError = ($absoluteError / $actualPrice) * 100;

        // 計算平方誤差
        $squaredError = pow($absoluteError, 2);

        return [
            'actual_price' => $actualPrice,
            'predicted_price' => $this->predicted_price,
            'absolute_error' => $absoluteError,
            'percentage_error' => $percentageError,
            'squared_error' => $squaredError,
            'is_within_confidence' => $this->isWithinConfidence($actualPrice),
        ];
    }

    /**
     * 檢查實際價格是否在信賴區間內
     */
    private function isWithinConfidence($actualPrice)
    {
        if (!$this->confidence_upper || !$this->confidence_lower) {
            return null;
        }

        return $actualPrice >= $this->confidence_lower &&
               $actualPrice <= $this->confidence_upper;
    }

    /**
     * 計算模型績效指標
     */
    public static function calculateModelPerformance($stockId, $modelType, $days = 30)
    {
        $predictions = self::where('stock_id', $stockId)
            ->where('model_type', $modelType)
            ->where('prediction_date', '>=', now()->subDays($days))
            ->get();

        $errors = [];
        $withinConfidence = 0;
        $total = 0;

        foreach ($predictions as $prediction) {
            $error = $prediction->calculateError();
            if ($error) {
                $errors[] = $error;
                if ($error['is_within_confidence']) {
                    $withinConfidence++;
                }
                $total++;
            }
        }

        if (empty($errors)) {
            return null;
        }

        // 計算 RMSE
        $rmse = sqrt(array_sum(array_column($errors, 'squared_error')) / count($errors));

        // 計算 MAE
        $mae = array_sum(array_column($errors, 'absolute_error')) / count($errors);

        // 計算 MAPE
        $mape = array_sum(array_column($errors, 'percentage_error')) / count($errors);

        // 計算信賴區間命中率
        $confidenceHitRate = $total > 0 ? ($withinConfidence / $total) * 100 : 0;

        return [
            'model_type' => $modelType,
            'period_days' => $days,
            'total_predictions' => count($errors),
            'rmse' => round($rmse, 4),
            'mae' => round($mae, 4),
            'mape' => round($mape, 2),
            'confidence_hit_rate' => round($confidenceHitRate, 2),
        ];
    }

    /**
     * 取得預測趨勢
     */
    public function getTrendAttribute()
    {
        $currentPrice = $this->stock->latestPrice->close_price ?? 0;

        if ($currentPrice > 0 && $this->predicted_price) {
            $change = (($this->predicted_price - $currentPrice) / $currentPrice) * 100;

            if ($change > 1) return 'bullish';
            if ($change < -1) return 'bearish';
            return 'neutral';
        }

        return null;
    }
}
