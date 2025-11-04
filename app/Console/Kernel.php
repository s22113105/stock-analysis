<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // 股票資料爬蟲 - 每個交易日下午 3:30 執行
        $schedule->command('crawler:stocks --type=price')
            ->weekdays()
            ->dailyAt('15:30')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/crawler.log'));

        // 月營收資料爬蟲 - 每月 10 號晚上 8 點執行
        $schedule->command('crawler:stocks --type=revenue')
            ->monthlyOn(10, '20:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/crawler.log'));

        // 選擇權資料爬蟲 - 每個交易日下午 3:45 執行
        $schedule->command('crawler:options')
            ->weekdays()
            ->dailyAt('15:45')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/crawler.log'));

        // 權證資料爬蟲 - 每個交易日下午 4:00 執行
        $schedule->command('crawler:stocks --type=warrant')
            ->weekdays()
            ->dailyAt('16:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/crawler.log'));

        // 清理過期的選擇權資料 - 每週日凌晨 2 點執行
        $schedule->command('options:clean-expired')
            ->weekly()
            ->sundays()
            ->at('02:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/maintenance.log'));

        // 計算波動率 - 每個交易日下午 4:30 執行
        $schedule->command('volatility:calculate')
            ->weekdays()
            ->dailyAt('16:30')
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/volatility.log'));

        // 備份資料庫 - 每日凌晨 1 點執行
        $schedule->command('backup:database')
            ->daily()
            ->at('01:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/backup.log'));

        // 清理日誌檔案 - 每月 1 號凌晨 3 點執行
        $schedule->command('logs:clear')
            ->monthlyOn(1, '03:00')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/maintenance.log'));

        // Queue 重啟 - 每小時執行一次，避免記憶體洩漏
        $schedule->command('queue:restart')
            ->hourly()
            ->withoutOverlapping();

        // 失敗任務重試 - 每 30 分鐘執行一次
        $schedule->command('queue:retry all')
            ->everyThirtyMinutes()
            ->withoutOverlapping();
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
