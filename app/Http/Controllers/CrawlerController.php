<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

/**
 * 爬蟲管理控制器
 * * 用於手動觸發爬蟲任務、查看執行狀態與日誌
 */
class CrawlerController extends Controller
{
    /**
     * 執行股票爬蟲
     * * POST /api/crawler/stocks
     */
    public function crawlStocks(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'symbol' => 'nullable|string',
            'sync' => 'nullable|boolean'
        ]);

        try {
            $date = $request->input('date', now()->format('Y-m-d'));
            $symbol = $request->input('symbol');
            $sync = $request->boolean('sync', false);

            $params = [
                '--date' => $date,
            ];

            if ($symbol) {
                $params['--symbol'] = $symbol;
            }

            if ($sync) {
                $params['--sync'] = true;
            }

            // 呼叫 Artisan 指令
            // 注意: 如果是異步執行(預設)，這裡會很快返回；如果是同步(--sync)，會等待執行完畢
            $exitCode = Artisan::call('crawler:stocks', $params);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => $sync ? '爬蟲任務執行完畢' : '爬蟲任務已加入佇列',
                'data' => [
                    'date' => $date,
                    'symbol' => $symbol,
                    'sync' => $sync,
                    'exit_code' => $exitCode,
                    'output' => trim($output)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('觸發股票爬蟲失敗', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => '執行失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 執行選擇權爬蟲
     * * POST /api/crawler/options
     */
    public function crawlOptions(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'sync' => 'nullable|boolean'
        ]);

        try {
            $date = $request->input('date', now()->format('Y-m-d'));
            $sync = $request->boolean('sync', false);

            $params = [
                '--date' => $date,
            ];

            if ($sync) {
                $params['--sync'] = true;
            }

            // 呼叫選擇權爬蟲指令
            $exitCode = Artisan::call('crawler:options', $params);
            $output = Artisan::output();

            return response()->json([
                'success' => true,
                'message' => $sync ? '選擇權爬蟲執行完畢' : '選擇權爬蟲已加入佇列',
                'data' => [
                    'date' => $date,
                    'sync' => $sync,
                    'exit_code' => $exitCode,
                    'output' => trim($output)
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('觸發選擇權爬蟲失敗', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => '執行失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 取得爬蟲系統狀態
     * * GET /api/crawler/status
     */
    public function status(): JsonResponse
    {
        try {
            // 檢查 Queue 狀態 (簡單檢查 failed_jobs 表)
            $failedJobs = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
            $pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')->count();

            // 檢查 Log 檔案最後更新時間
            $logPath = storage_path('logs/crawler.log');
            $lastRun = null;
            $logSize = 0;

            if (File::exists($logPath)) {
                $lastRun = Carbon::createFromTimestamp(File::lastModified($logPath))->toDateTimeString();
                $logSize = round(File::size($logPath) / 1024, 2) . ' KB';
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'queue_status' => [
                        'pending_jobs' => $pendingJobs,
                        'failed_jobs' => $failedJobs,
                    ],
                    'crawler_log' => [
                        'exists' => File::exists($logPath),
                        'last_update' => $lastRun,
                        'size' => $logSize
                    ],
                    'server_time' => now()->toDateTimeString(),
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '無法取得狀態: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 讀取爬蟲日誌
     * * GET /api/crawler/logs
     */
    public function logs(Request $request): JsonResponse
    {
        $request->validate([
            'lines' => 'nullable|integer|min:10|max:500'
        ]);

        try {
            $lines = $request->input('lines', 50);
            $logPath = storage_path('logs/crawler.log');

            // 如果 crawler.log 不存在，回退讀取 laravel.log
            if (!File::exists($logPath)) {
                $logPath = storage_path('logs/laravel.log');
            }

            if (!File::exists($logPath)) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => '尚無日誌記錄'
                ]);
            }

            // 讀取最後 N 行
            $content = $this->tailFile($logPath, $lines);

            return response()->json([
                'success' => true,
                'data' => [
                    'file' => basename($logPath),
                    'logs' => $content
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => '讀取日誌失敗: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * 讀取檔案最後 N 行的輔助方法
     */
    private function tailFile($filepath, $lines = 50)
    {
        $handle = fopen($filepath, "r");
        $linecounter = $lines;
        $pos = -2;
        $beginning = false;
        $text = [];
        
        while ($linecounter > 0) {
            $t = " ";
            while ($t != "\n") {
                if (fseek($handle, $pos, SEEK_END) == -1) {
                    $beginning = true; 
                    break; 
                }
                $t = fgetc($handle);
                $pos--;
            }
            $linecounter--;
            if ($beginning) {
                rewind($handle);
            }
            $text[$lines - $linecounter - 1] = fgets($handle);
            if ($beginning) break;
        }
        fclose($handle);
        
        return array_reverse($text);
    }
}