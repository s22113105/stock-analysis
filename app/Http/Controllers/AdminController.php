<?php

namespace App\Http\Controllers;

use App\Jobs\FetchStockPricesJob;
use App\Jobs\FetchOptionPricesJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * 後台管理控制器
 * 
 * 處理系統管理、Job 手動觸發、監控等功能
 */
class AdminController extends Controller
{
    /**
     * 取得系統總覽
     * 
     * GET /api/admin/overview
     */
    public function overview(): JsonResponse
    {
        try {
            $overview = [
                // 系統資訊
                'system' => [
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'environment' => config('app.env'),
                    'debug_mode' => config('app.debug'),
                    'timezone' => config('app.timezone'),
                ],

                // 資料庫統計
                'database' => [
                    'stocks' => DB::table('stocks')->count(),
                    'stock_prices' => DB::table('stock_prices')->count(),
                    'options' => DB::table('options')->count(),
                    'option_prices' => DB::table('option_prices')->count(),
                    'predictions' => DB::table('predictions')->count(),
                    'backtest_results' => DB::table('backtest_results')->count(),
                    'database_size' => $this->getDatabaseSize(),
                ],

                // Redis 狀態
                'redis' => [
                    'connected' => $this->checkRedisConnection(),
                    'memory_usage' => $this->getRedisMemoryUsage(),
                    'keys_count' => $this->getRedisKeysCount(),
                ],

                // Queue 狀態
                'queue' => [
                    'driver' => config('queue.default'),
                    'pending_jobs' => $this->getQueueJobsCount('default'),
                    'failed_jobs' => DB::table('failed_jobs')->count(),
                ],

                // 最後更新時間
                'last_updates' => [
                    'stock_prices' => DB::table('stock_prices')->max('updated_at'),
                    'option_prices' => DB::table('option_prices')->max('updated_at'),
                    'predictions' => DB::table('predictions')->max('updated_at'),
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $overview
            ]);
        } catch (\Exception $e) {
            Log::error('系統總覽查詢失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手動觸發股票資料爬蟲
     * 
     * POST /api/admin/jobs/trigger-stock-crawler
     */
    public function triggerStockCrawler(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'symbol' => 'nullable|string',
            'date' => 'nullable|date',
            'sync' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $symbol = $request->input('symbol');
            $date = $request->input('date', now()->format('Y-m-d'));
            $sync = $request->input('sync', false);

            if ($symbol) {
                // 單一股票
                FetchStockPricesJob::dispatch($symbol, $date, $sync);
                
                return response()->json([
                    'success' => true,
                    'message' => "股票 {$symbol} 爬蟲任務已加入佇列",
                    'data' => [
                        'symbol' => $symbol,
                        'date' => $date,
                        'sync' => $sync
                    ]
                ]);
            } else {
                // 全部股票
                $exitCode = Artisan::call('crawler:stocks', [
                    '--date' => $date,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => '所有股票爬蟲任務已觸發',
                    'exit_code' => $exitCode
                ]);
            }
        } catch (\Exception $e) {
            Log::error('股票爬蟲觸發失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '觸發失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 手動觸發選擇權資料爬蟲
     * 
     * POST /api/admin/jobs/trigger-option-crawler
     */
    public function triggerOptionCrawler(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $date = $request->input('date', now()->format('Y-m-d'));

            $exitCode = Artisan::call('crawler:options', [
                '--date' => $date,
            ]);

            return response()->json([
                'success' => true,
                'message' => '選擇權爬蟲任務已觸發',
                'exit_code' => $exitCode,
                'date' => $date
            ]);
        } catch (\Exception $e) {
            Log::error('選擇權爬蟲觸發失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '觸發失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得 Queue 任務列表
     * 
     * GET /api/admin/jobs/queue
     */
    public function queueJobs(): JsonResponse
    {
        try {
            $jobs = [
                'default' => [
                    'pending' => $this->getQueueJobsCount('default'),
                    'processing' => $this->getQueueJobsCount('default', 'reserved'),
                ],
                'failed' => DB::table('failed_jobs')
                    ->orderBy('failed_at', 'desc')
                    ->limit(20)
                    ->get()
                    ->map(function ($job) {
                        return [
                            'id' => $job->id,
                            'connection' => $job->connection,
                            'queue' => $job->queue,
                            'exception' => substr($job->exception, 0, 200) . '...',
                            'failed_at' => $job->failed_at,
                        ];
                    }),
            ];

            return response()->json([
                'success' => true,
                'data' => $jobs
            ]);
        } catch (\Exception $e) {
            Log::error('Queue 任務查詢失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '查詢失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 重試失敗的 Job
     * 
     * POST /api/admin/jobs/retry/{id}
     */
    public function retryFailedJob(int $id): JsonResponse
    {
        try {
            $exitCode = Artisan::call('queue:retry', ['id' => [$id]]);

            return response()->json([
                'success' => true,
                'message' => 'Job 已重新加入佇列',
                'exit_code' => $exitCode
            ]);
        } catch (\Exception $e) {
            Log::error('重試 Job 失敗', [
                'job_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '重試失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 清除快取
     * 
     * POST /api/admin/cache/clear
     */
    public function clearCache(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:all,config,route,view,cache',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $type = $request->input('type');
            $commands = [];

            switch ($type) {
                case 'all':
                    $commands = ['config:clear', 'route:clear', 'view:clear', 'cache:clear'];
                    break;
                case 'config':
                    $commands = ['config:clear'];
                    break;
                case 'route':
                    $commands = ['route:clear'];
                    break;
                case 'view':
                    $commands = ['view:clear'];
                    break;
                case 'cache':
                    $commands = ['cache:clear'];
                    break;
            }

            foreach ($commands as $command) {
                Artisan::call($command);
            }

            return response()->json([
                'success' => true,
                'message' => "已清除 {$type} 快取",
                'commands' => $commands
            ]);
        } catch (\Exception $e) {
            Log::error('清除快取失敗', [
                'type' => $request->input('type'),
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '清除失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得系統日誌
     * 
     * GET /api/admin/logs
     */
    public function logs(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lines' => 'nullable|integer|min:10|max:1000',
            'level' => 'nullable|in:emergency,alert,critical,error,warning,notice,info,debug',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $lines = $request->input('lines', 100);
            $level = $request->input('level');

            $logFile = storage_path('logs/laravel.log');
            
            if (!file_exists($logFile)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Log 檔案不存在'
                ], 404);
            }

            $logLines = file($logFile);
            $recentLogs = array_slice($logLines, -$lines);

            // 如果指定 level，進行過濾
            if ($level) {
                $recentLogs = array_filter($recentLogs, function($line) use ($level) {
                    return stripos($line, ".$level") !== false;
                });
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'logs' => array_values($recentLogs),
                    'total' => count($recentLogs),
                    'file' => $logFile,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('讀取日誌失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '讀取失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 執行資料庫維護
     * 
     * POST /api/admin/database/optimize
     */
    public function optimizeDatabase(): JsonResponse
    {
        try {
            // 執行資料庫優化命令
            Artisan::call('optimize');
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');

            return response()->json([
                'success' => true,
                'message' => '資料庫優化完成'
            ]);
        } catch (\Exception $e) {
            Log::error('資料庫優化失敗', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => '優化失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    // ============ 私有輔助方法 ============

    /**
     * 檢查 Redis 連線
     */
    private function checkRedisConnection(): bool
    {
        try {
            Redis::connection()->ping();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 取得 Redis 記憶體使用量
     */
    private function getRedisMemoryUsage(): ?string
    {
        try {
            $info = Redis::connection()->info('memory');
            return $info['used_memory_human'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 取得 Redis Keys 數量
     */
    private function getRedisKeysCount(): int
    {
        try {
            return Redis::connection()->dbSize();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 取得資料庫大小
     */
    private function getDatabaseSize(): ?string
    {
        try {
            $database = config('database.connections.mysql.database');
            $result = DB::select("
                SELECT 
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
                FROM information_schema.TABLES 
                WHERE table_schema = ?
            ", [$database]);

            return $result[0]->size_mb . ' MB';
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 取得 Queue Jobs 數量
     */
    private function getQueueJobsCount(string $queue, string $type = 'ready'): int
    {
        try {
            if (config('queue.default') === 'redis') {
                $key = config('queue.connections.redis.queue', 'default');
                $prefix = config('database.redis.options.prefix');
                
                if ($type === 'ready') {
                    return Redis::connection()->llen($prefix . 'queues:' . $key);
                } elseif ($type === 'reserved') {
                    return Redis::connection()->zcard($prefix . 'queues:' . $key . ':reserved');
                }
            }
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }
}