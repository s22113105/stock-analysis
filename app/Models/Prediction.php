<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Prediction extends Model
{
    use HasFactory;

    /**
     * ✅ fillable 對齊 migration 欄位
     * migration 使用 morphs('predictable')，無 stock_id
     */
    protected $fillable = [
        'predictable_type',   // 多型：App\Models\Stock | App\Models\Option
        'predictable_id',     // 多型：對應的 id
        'model_type',         // LSTM / ARIMA / GARCH
        'prediction_date',    // 預測執行日期
        'prediction_days',    // 預測天數
        'predicted_price',    // 預測價格
        'predicted_volatility', // 預測波動率
        'upper_bound',        // 預測上界
        'lower_bound',        // 預測下界
        'confidence_level',   // 信心水準 (%)
        'mse',
        'rmse',
        'mae',
        'accuracy',           // 準確率 (%)
        'model_parameters',   // 模型參數 JSON
        'prediction_series',  // 預測序列 JSON
        'notes',
    ];

    /**
     * ✅ casts 對齊 migration 欄位名稱
     */
    protected $casts = [
        'prediction_date'     => 'date',
        'prediction_days'     => 'integer',
        'predicted_price'     => 'decimal:4',
        'predicted_volatility'=> 'decimal:6',
        'upper_bound'         => 'decimal:4',
        'lower_bound'         => 'decimal:4',
        'confidence_level'    => 'decimal:2',
        'mse'                 => 'decimal:6',
        'rmse'                => 'decimal:6',
        'mae'                 => 'decimal:6',
        'accuracy'            => 'decimal:2',
        'model_parameters'    => 'array',
        'prediction_series'   => 'array',
    ];

    // ==========================================
    // 關聯
    // ==========================================

    /**
     * ✅ 多型關聯：可對應 Stock 或 Option
     * 取代原本的 belongsTo(Stock::class)
     */
    public function predictable(): MorphTo
    {
        return $this->morphTo();
    }

    // ==========================================
    // Scopes
    // ==========================================

    /**
     * 依模型類型篩選
     */
    public function scopeByModel($query, string $modelType)
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * 依預測日期篩選
     */
    public function scopeForPredictionDate($query, $date)
    {
        return $query->where('prediction_date', $date);
    }

    /**
     * 依目標物件篩選（多型）
     */
    public function scopeForPredictable($query, string $type, int $id)
    {
        return $query->where('predictable_type', $type)
                     ->where('predictable_id', $id);
    }

    /**
     * 只取股票預測
     */
    public function scopeStockPredictions($query)
    {
        return $query->where('predictable_type', Stock::class);
    }

    /**
     * 只取選擇權預測
     */
    public function scopeOptionPredictions($query)
    {
        return $query->where('predictable_type', Option::class);
    }

    /**
     * 最新排序
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('prediction_date', 'desc');
    }

    // ==========================================
    // 輔助方法
    // ==========================================

    /**
     * 取得預測區間寬度
     */
    public function getPredictionRangeAttribute(): ?float
    {
        if ($this->upper_bound && $this->lower_bound) {
            return (float) $this->upper_bound - (float) $this->lower_bound;
        }
        return null;
    }

    /**
     * ✅ 計算預測誤差（對齊 morphs，支援 Stock 與 Option）
     * 原本硬綁 stock_id，改為透過多型關聯取得標的
     */
    public function calculateError(): ?array
    {
        if (!$this->predicted_price) {
            return null;
        }

        // 透過多型關聯取得標的
        $predictable = $this->predictable;

        if (!$predictable) {
            return null;
        }

        // 取得實際收盤價（Stock 或 Option 的最新價格）
        $actualPrice = null;

        if ($predictable instanceof Stock) {
            $actualPrice = StockPrice::where('stock_id', $predictable->id)
                ->where('trade_date', $this->prediction_date)
                ->value('close');
        } elseif ($predictable instanceof Option) {
            $actualPrice = OptionPrice::where('option_id', $predictable->id)
                ->where('trade_date', $this->prediction_date)
                ->value('close');
        }

        if (!$actualPrice) {
            return null;
        }

        $absoluteError   = abs($actualPrice - $this->predicted_price);
        $percentageError = ($absoluteError / $actualPrice) * 100;
        $squaredError    = pow($absoluteError, 2);

        return [
            'actual_price'        => $actualPrice,
            'predicted_price'     => (float) $this->predicted_price,
            'absolute_error'      => $absoluteError,
            'percentage_error'    => round($percentageError, 4),
            'squared_error'       => $squaredError,
            'is_within_confidence'=> $this->isWithinConfidence($actualPrice),
        ];
    }

    /**
     * 實際價格是否在信賴區間內
     */
    private function isWithinConfidence(float $actualPrice): ?bool
    {
        if (!$this->upper_bound || !$this->lower_bound) {
            return null;
        }

        return $actualPrice >= (float) $this->lower_bound
            && $actualPrice <= (float) $this->upper_bound;
    }

    /**
     * ✅ 計算模型績效指標（改用多型欄位，移除 stock_id）
     */
    public static function calculateModelPerformance(
        string $predictableType,
        int $predictableId,
        string $modelType,
        int $days = 30
    ): ?array {
        $predictions = self::where('predictable_type', $predictableType)
            ->where('predictable_id', $predictableId)
            ->where('model_type', $modelType)
            ->where('prediction_date', '>=', now()->subDays($days))
            ->get();

        $errors           = [];
        $withinConfidence = 0;
        $total            = 0;

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

        $rmse = sqrt(array_sum(array_column($errors, 'squared_error')) / count($errors));
        $mae  = array_sum(array_column($errors, 'absolute_error'))  / count($errors);
        $mape = array_sum(array_column($errors, 'percentage_error')) / count($errors);
        $confidenceHitRate = $total > 0 ? ($withinConfidence / $total) * 100 : 0;

        return [
            'model_type'           => $modelType,
            'period_days'          => $days,
            'total_predictions'    => count($errors),
            'rmse'                 => round($rmse, 4),
            'mae'                  => round($mae, 4),
            'mape'                 => round($mape, 2),
            'confidence_hit_rate'  => round($confidenceHitRate, 2),
        ];
    }

    /**
     * ✅ 取得預測趨勢（改用多型關聯，移除 $this->stock）
     */
    public function getTrendAttribute(): ?string
    {
        if (!$this->predicted_price) {
            return null;
        }

        $predictable = $this->predictable;

        if (!$predictable) {
            return null;
        }

        $currentPrice = null;

        if ($predictable instanceof Stock) {
            $currentPrice = optional($predictable->latestPrice)->close;
        } elseif ($predictable instanceof Option) {
            $currentPrice = optional($predictable->latestPrice)->close;
        }

        if (!$currentPrice || $currentPrice <= 0) {
            return null;
        }

        $change = (((float) $this->predicted_price - $currentPrice) / $currentPrice) * 100;

        if ($change > 1)  return 'bullish';
        if ($change < -1) return 'bearish';
        return 'neutral';
    }
}
