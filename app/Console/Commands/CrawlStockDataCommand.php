<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\FetchStockDataJob;

class CrawlStockDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:stocks
                            {--date= : 指定日期 (Y-m-d)}
                            {--symbol= : 指定股票代碼}
                            {--sync : 同步執行}';

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
        $sync = $this->option('sync');

        $this->info('開始執行股票資料爬蟲...');
        $this->info("日期: {$date}");

        if ($symbol) {
            $this->info("股票代碼: {$symbol}");
        }

        try {
            $job = new FetchStockDataJob($date, $symbol);

            if ($sync) {
                dispatch($job)->onConnection('sync');
                $this->info('股票資料爬蟲執行完成！');
            } else {
                dispatch($job);
                $this->info('股票資料爬蟲已加入佇列！');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('執行失敗: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
