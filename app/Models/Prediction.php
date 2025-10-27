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
     * 取得特定日期的預測
     */
    public function scopeForDate($query, $targetDate)
    {
        return $query->where('target_date', $targetDate);
    }

    /**
     * 取得最新的預測
     */
    public function scopeLatest($query, $stockId, $modelType = null)
    {
        $query = $query->where('stock_id', $stockId);
        
        if ($modelType) {
            $query = $query->where('model_type', $modelType);
        }
        
        return $query->orderBy('prediction_date', 'desc')->first();
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
     * 驗證預測準確度（需要實際價格）
     */
    public function validatePrediction($actualPrice)
    {
        if (!$this->predicted_price || !$actualPrice) {
            return null;
        }

        $error = abs($actualPrice - $this->predicted_price) / $actualPrice;
        return 1 - $error; // 轉換為準確度
    }
}