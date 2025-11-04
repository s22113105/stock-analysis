<?php

namespace App\Console\Commands;

use App\Jobs\FetchStockDataJob;
use App\Jobs\FetchMonthlyRevenueJob;
use App\Jobs\FetchWarrantDataJob;
use Illuminate\Console\Command;
use Carbon\Carbon;

class CrawlStockDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:stocks
                            {--date= : 指定日期 (YYYY-MM-DD)}
                            {--symbol= : 指定股票代碼}
                            {--type=all : 資料類型 (all|price|revenue|warrant)}
                            {--sync : 同步執行（不使用佇列）}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '執行股票資料爬蟲';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $symbol = $this->option('symbol');
        $type = $this->option('type');
        $sync = $this->option('sync');

        $this->info("開始執行股票資料爬蟲...");
        $this->info("日期: {$date}");

        if ($symbol) {
            $this->info("股票代碼: {$symbol}");
        }

        $this->info("資料類型: {$type}");

        try {
            switch ($type) {
                case 'price':
                    $this->crawlStockPrice($date, $symbol, $sync);
                    break;

                case 'revenue':
                    $this->crawlMonthlyRevenue($sync);
                    break;

                case 'warrant':
                    $this->crawlWarrantData($date, $sync);
                    break;

                case 'all':
                default:
                    $this->crawlAllData($date, $symbol, $sync);
                    break;
            }

            $this->info('爬蟲任務已排入佇列或執行完成！');

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
     * 爬取股票價格資料
     */
    protected function crawlStockPrice($date, $symbol, $sync)
    {
        $this->info('正在爬取股票價格資料...');

        $job = new FetchStockDataJob($date, $symbol);

        if ($sync) {
            dispatch_sync($job);
            $this->info('股票價格資料爬取完成！');
        } else {
            dispatch($job);
            $this->info('股票價格爬蟲任務已加入佇列');
        }
    }

    /**
     * 爬取月營收資料
     */
    protected function crawlMonthlyRevenue($sync)
    {
        $this->info('正在爬取月營收資料...');

        $job = new FetchMonthlyRevenueJob();

        if ($sync) {
            dispatch_sync($job);
            $this->info('月營收資料爬取完成！');
        } else {
            dispatch($job);
            $this->info('月營收爬蟲任務已加入佇列');
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

    /**
     * 爬取所有資料
     */
    protected function crawlAllData($date, $symbol, $sync)
    {
        $this->info('正在爬取所有資料...');

        // 建立進度條
        $bar = $this->output->createProgressBar(3);
        $bar->start();

        // 股票價格資料
        $stockJob = new FetchStockDataJob($date, $symbol);
        if ($sync) {
            dispatch_sync($stockJob);
        } else {
            dispatch($stockJob);
        }
        $bar->advance();

        // 月營收資料（每月10號後執行）
        if (Carbon::parse($date)->day >= 10) {
            $revenueJob = new FetchMonthlyRevenueJob();
            if ($sync) {
                dispatch_sync($revenueJob);
            } else {
                dispatch($revenueJob);
            }
        }
        $bar->advance();

        // 權證資料
        $warrantJob = new FetchWarrantDataJob($date);
        if ($sync) {
            dispatch_sync($warrantJob);
        } else {
            dispatch($warrantJob);
        }
        $bar->advance();

        $bar->finish();
        $this->newLine();

        if ($sync) {
            $this->info('所有資料爬取完成！');
        } else {
            $this->info('所有爬蟲任務已加入佇列');
        }
    }
}
