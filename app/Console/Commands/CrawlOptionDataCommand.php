<?php

namespace App\Console\Commands;

use App\Jobs\FetchOptionDataJob;
use App\Jobs\FetchWarrantDataJob;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CrawlOptionDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:options
                            {--date= : 指定日期 (YYYY-MM-DD)}
                            {--symbol= : 指定股票代碼}
                            {--sync : 同步執行（不使用佇列）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '執行選擇權資料爬蟲';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $symbol = $this->option('symbol');
        $sync = $this->option('sync');

        $this->info("開始執行選擇權資料爬蟲...");
        $this->info("日期: {$date}");

        if ($symbol) {
            $this->info("股票代碼: {$symbol}");
        }

        try {
            // 爬取選擇權資料
            $this->crawlOptionData($date, $symbol, $sync);

            // 爬取權證資料
            $this->crawlWarrantData($date, $sync);

            $this->info('選擇權爬蟲任務已排入佇列或執行完成！');

            if (!$sync) {
                $this->info('提示：使用 php artisan queue:work 來處理佇列任務');
            }

        } catch (\Exception $e) {
            $this->error('爬蟲執行失敗: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * 爬取選擇權資料
     */
    protected function crawlOptionData($date, $symbol, $sync)
    {
        $this->info('正在爬取選擇權資料...');

        $job = new FetchOptionDataJob($date, $symbol);

        if ($sync) {
            dispatch_sync($job);
            $this->info('選擇權資料爬取完成！');
        } else {
            dispatch($job);
            $this->info('選擇權爬蟲任務已加入佇列');
        }
    }

    /**
     * 爬取權證資料
     */
    protected function crawlWarrantData($date, $sync)
    {
        $this->info('正在爬取權證資料...');

        $job = new FetchWarrantDataJob($date);

        if ($sync) {
            dispatch_sync($job);
            $this->info('權證資料爬取完成！');
        } else {
            dispatch($job);
            $this->info('權證爬蟲任務已加入佇列');
        }
    }
}
