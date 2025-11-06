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

        $this->info('========================================');
        $this->info('開始執行股票資料爬蟲');
        $this->info('========================================');
        $this->info("日期: {$date}");

        if ($symbol) {
            $this->info("股票代碼: {$symbol}");
        } else {
            $this->info("模式: 全部股票");
        }

        $this->info('========================================');
        $this->newLine();

        try {
            // 注意參數順序: date 在前, symbol 在後
            $job = new FetchStockDataJob($date, $symbol);

            if ($sync) {
                $this->info('⏳ 同步執行中...');
                dispatch($job)->onConnection('sync');
                $this->newLine();
                $this->info('✅ 股票資料爬蟲執行完成！');
            } else {
                dispatch($job);
                $this->info('✅ 股票資料爬蟲已加入佇列！');
                $this->info('💡 提示: 請確保 queue worker 正在執行');
                $this->info('   指令: php artisan queue:work');
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->newLine();
            $this->error('❌ 執行失敗: ' . $e->getMessage());
            $this->error('請查看 log 檔案以取得更多資訊');
            return Command::FAILURE;
        }
    }
}
