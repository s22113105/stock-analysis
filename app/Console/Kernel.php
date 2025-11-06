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
        // 每天下午 1:30 執行股票資料爬蟲
        // 台股收盤時間是 13:30,爬蟲會在收盤後立即執行
        $schedule->command('crawler:stocks')
            ->dailyAt('13:30')
            ->weekdays() // 只在平日執行 (週一到週五)
            ->timezone('Asia/Taipei')
            ->runInBackground() // 背景執行
            ->withoutOverlapping() // 避免重複執行
            ->appendOutputTo(storage_path('logs/crawler.log')); // 記錄輸出

        // 如果要更保險,可以在下午 2:00 再執行一次 (給 TWSE API 更新時間)
        $schedule->command('crawler:stocks')
            ->dailyAt('14:00')
            ->weekdays()
            ->timezone('Asia/Taipei')
            ->runInBackground()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/crawler.log'));

        // 每週一早上 8:00 更新公司基本資料
        // $schedule->command('crawler:company-info')
        //     ->weeklyOn(1, '08:00')
        //     ->timezone('Asia/Taipei');

        // 每天晚上 11:00 清理過期的快取
        $schedule->command('cache:prune-stale-tags')
            ->daily()
            ->timezone('Asia/Taipei');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
