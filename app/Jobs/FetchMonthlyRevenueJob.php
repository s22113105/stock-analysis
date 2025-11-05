<?php

namespace App\Jobs;

use App\Models\Stock;
use App\Services\TwseApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchMonthlyRevenueJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任務最大嘗試次數
     */
    public $tries = 3;

    /**
     * 任務超時時間
     */
    public $timeout = 600;

    /**
     * Execute the job.
     */
    public function handle(TwseApiService $twseApi)
    {
        Log::info('開始執行月營收資料爬蟲');

        try {
            DB::beginTransaction();

            // 取得月營收資料
            $revenueData = $twseApi->getMonthlyRevenue();

            $updateCount = 0;

            foreach ($revenueData as $data) {
                $stock = Stock::where('symbol', $data['symbol'])->first();

                if (!$stock) {
                    // 如果股票不存在，先建立
                    $stock = Stock::create([
                        'symbol' => $data['symbol'],
                        'name' => $data['name'],
                        'is_active' => true
                    ]);
                }

                // 將月營收資料存入資料庫
                // 這裡可以建立專門的 monthly_revenue 表格
                // 或存入 stock 的 meta_data
                $metaData = $stock->meta_data ?? [];

                if (!isset($metaData['monthly_revenue'])) {
                    $metaData['monthly_revenue'] = [];
                }

                // 保留最近12個月的資料
                $metaData['monthly_revenue'][$data['year_month']] = [
                    'revenue' => $data['revenue'],
                    'revenue_mom' => $data['revenue_mom'],
                    'revenue_yoy' => $data['revenue_yoy'],
                    'revenue_ytd' => $data['revenue_ytd'],
                    'revenue_ytd_yoy' => $data['revenue_ytd_yoy'],
                    'updated_at' => now()->toDateTimeString()
                ];

                // 只保留最近12個月
                $metaData['monthly_revenue'] = collect($metaData['monthly_revenue'])
                    ->sortKeysDesc()
                    ->take(12)
                    ->toArray();

                $stock->update(['meta_data' => $metaData]);
                $updateCount++;
            }

            DB::commit();

            Log::info('月營收資料爬蟲執行完成', [
                'updated_count' => $updateCount
            ]);

            // 觸發相關事件（需要先建立 Event 類別）
            // event(new \App\Events\MonthlyRevenueUpdated());

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('月營收資料爬蟲執行失敗', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 任務失敗時的處理
     */
    public function failed(\Throwable $exception)
    {
        Log::error('月營收資料爬蟲任務失敗', [
            'error' => $exception->getMessage()
        ]);
    }
}
