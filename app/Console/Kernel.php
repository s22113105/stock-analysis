<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Jobs\FetchStockDataJob;
use App\Jobs\FetchMonthlyRevenueJob;
use App\Jobs\FetchWarrantDataJob;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 每個交易日下午 3:30 執行股票價格資料爬蟲
        $schedule->job(new FetchStockDataJob())
            ->weekdays()
            ->dailyAt('15:30')
            ->withoutOverlapping()
            ->onSuccess(function () {
                \Log::info('Daily stock price crawler completed successfully');
            })
            ->onFailure(function () {
                \Log::error('Daily stock price crawler failed');
                // 發送通知給管理員
            });

        // 每個交易日下午 4:00 執行權證資料爬蟲
        $schedule->job(new FetchWarrantDataJob())
            ->weekdays()
            ->dailyAt('16:00')
            ->withoutOverlapping();

        // 每月 10 號下午 6:00 執行月營收資料爬蟲
        $schedule->job(new FetchMonthlyRevenueJob())
            ->monthlyOn(10, '18:00')
            ->withoutOverlapping();

        // 每日清晨 5:00 清理過期的快取
        $schedule->command('cache:prune-stale-tags')
            ->dailyAt('05:00');

        // 每週日凌晨 2:00 執行資料庫備份
        $schedule->command('backup:run --only-db')
            ->weekly()
            ->sundays()
            ->at('02:00');

        // 每日凌晨 1:00 清理 7 天前的日誌
        $schedule->command('log:clear --keep=7')
            ->dailyAt('01:00');

        // 每小時檢查佇列健康狀態
        $schedule->command('queue:monitor redis:default --max=100')
            ->everyThirtyMinutes();

        // 定期重新計算波動率（每個交易日收盤後）
        $schedule->call(function () {
            dispatch(new \App\Jobs\CalculateVolatilityJob());
        })->weekdays()->dailyAt('16:30');

        // 定期檢查選擇權到期日（每日早上 9:00）
        $schedule->call(function () {
            dispatch(new \App\Jobs\CheckOptionExpiryJob());
        })->dailyAt('09:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
