<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataFetchLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_name',
        'status',
        'fetch_date',
        'started_at',
        'completed_at',
        'records_fetched',
        'records_inserted',
        'records_updated',
        'records_failed',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'fetch_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'records_fetched' => 'integer',
        'records_inserted' => 'integer',
        'records_updated' => 'integer',
        'records_failed' => 'integer',
        'metadata' => 'array',
    ];

    /**
     * 依狀態查詢
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * 查詢成功的記錄
     */
    public function scopeSuccess($query)
    {
        return $query->where('status', 'success');
    }

    /**
     * 查詢失敗的記錄
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * 依 Job 名稱查詢
     */
    public function scopeByJob($query, string $jobName)
    {
        return $query->where('job_name', $jobName);
    }

    /**
     * 取得最近的記錄
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * 計算執行時間（秒）
     */
    public function getExecutionTimeAttribute()
    {
        if ($this->started_at && $this->completed_at) {
            return $this->completed_at->diffInSeconds($this->started_at);
        }
        return null;
    }

    /**
     * 計算成功率
     */
    public function getSuccessRateAttribute()
    {
        if ($this->records_fetched > 0) {
            $successful = $this->records_inserted + $this->records_updated;
            return ($successful / $this->records_fetched) * 100;
        }
        return 0;
    }

    /**
     * 標記為開始執行
     */
    public function markAsStarted()
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * 標記為成功完成
     */
    public function markAsSuccess($recordsFetched = 0, $recordsInserted = 0, $recordsUpdated = 0)
    {
        $this->update([
            'status' => 'success',
            'completed_at' => now(),
            'records_fetched' => $recordsFetched,
            'records_inserted' => $recordsInserted,
            'records_updated' => $recordsUpdated,
        ]);
    }

    /**
     * 標記為失敗
     */
    public function markAsFailed(string $errorMessage, $recordsFailed = 0)
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'records_failed' => $recordsFailed,
            'error_message' => $errorMessage,
        ]);
    }
}