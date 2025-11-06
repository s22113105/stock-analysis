<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\FetchOptionDataJob;

class CrawlOptionDataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:options
                            {--date= : 指定日期 (Y-m-d)}
                            {--sync : 同步執行}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '執行選擇權資料爬蟲 (臺指選擇權 TXO)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $date = $this->option('date') ?: now()->format('Y-m-d');
        $sync = $this->option('sync');

        $this->info('========================================');
        $this->info('開始執行選擇權資料爬蟲 (TXO)');
        $this->info('========================================');
        $this->info("日期: {$date}");
        $this->info('標的: 臺指選擇權 (TXO)');
        $this->info('========================================');
        $this->newLine();

        try {
            $job = new FetchOptionDataJob($date);

            if ($sync) {
                $this->info('⏳ 同步執行中...');
                dispatch($job)->onConnection('sync');
                $this->newLine();
                $this->info('✅ 選擇權資料爬蟲執行完成！');
            } else {
                dispatch($job);
                $this->info('✅ 選擇權資料爬蟲已加入佇列！');
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
