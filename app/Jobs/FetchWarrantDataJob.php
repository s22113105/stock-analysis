<?php

namespace App\Jobs;

use App\Models\Option;
use App\Models\Stock;
use App\Models\OptionPrice;
use App\Services\TwseApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class FetchWarrantDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $date;

    /**
     * 任務最大嘗試次數
     */
    public $tries = 3;

    /**
     * 任務超時時間
     */
    public $timeout = 600;

    /**
     * Create a new job instance.
     */
    public function __construct($date = null)
    {
        $this->date = $date ?: now()->format('Y-m-d');
    }

    /**
     * Execute the job.
     */
    public function handle(TwseApiService $twseApi)
    {
        Log::info('開始執行權證資料爬蟲', ['date' => $this->date]);

        try {
            DB::beginTransaction();

            // 取得權證基本資料
            $warrantData = $twseApi->getWarrantData();

            $insertCount = 0;
            $updateCount = 0;

            foreach ($warrantData as $data) {
                // 找到對應的標的股票
                $stock = Stock::where('symbol', $data['underlying_symbol'])->first();

                if (!$stock) {
                    Log::warning('找不到標的股票', ['symbol' => $data['underlying_symbol']]);
                    continue;
                }

                // 將權證資料對應到選擇權格式
                $optionType = $this->mapWarrantType($data['type']);

                if (!$optionType) {
                    continue;
                }

                // 建立或更新選擇權資料
                $option = Option::updateOrCreate(
                    [
                        'option_code' => $data['warrant_code']
                    ],
                    [
                        'stock_id' => $stock->id,
                        'option_type' => $optionType,
                        'strike_price' => $data['strike_price'],
                        'expiry_date' => $data['expiry_date'],
                        'contract_size' => $data['conversion_ratio'] * 1000, // 權證通常以千股為單位
                        'exercise_style' => 'european', // 台灣權證多為歐式
                        'is_active' => Carbon::parse($data['expiry_date'])->isFuture(),
                        'meta_data' => [
                            'warrant_name' => $data['warrant_name'],
                            'issuer' => $data['issuer'],
                            'issue_date' => $data['issue_date'],
                            'conversion_ratio' => $data['conversion_ratio'],
                            'data_source' => 'twse_warrant'
                        ]
                    ]
                );

                if ($option->wasRecentlyCreated) {
                    $insertCount++;
                } else {
                    $updateCount++;
                }

                // 這裡也可以同時爬取權證的價格資料
                // 並存入 option_prices 表
                $this->fetchWarrantPrice($option, $data['warrant_code']);
            }

            DB::commit();

            Log::info('權證資料爬蟲執行完成', [
                'date' => $this->date,
                'inserted' => $insertCount,
                'updated' => $updateCount
            ]);

            // 觸發相關事件（需要先建立 Event 類別）
            // event(new \App\Events\WarrantDataUpdated($this->date));

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('權證資料爬蟲執行失敗', [
                'date' => $this->date,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * 將權證類型對應到選擇權類型
     */
    protected function mapWarrantType($warrantType)
    {
        $typeMap = [
            '認購' => 'call',
            '認售' => 'put',
            '認購權證' => 'call',
            '認售權證' => 'put',
            'Call' => 'call',
            'Put' => 'put'
        ];

        return $typeMap[$warrantType] ?? null;
    }

    /**
     * 爬取權證價格資料
     */
    protected function fetchWarrantPrice(Option $option, $warrantCode)
    {
        // 這裡可以實作爬取權證價格的邏輯
        // 例如從另一個 API 端點取得權證的成交價、買賣價等

        try {
            // 暫時使用模擬資料
            $priceData = [
                'option_id' => $option->id,
                'trade_date' => $this->date,
                'last' => rand(100, 500) / 100,
                'bid' => rand(90, 480) / 100,
                'ask' => rand(110, 520) / 100,
                'volume' => rand(1000, 100000),
                'open_interest' => rand(10000, 1000000),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            OptionPrice::updateOrCreate(
                [
                    'option_id' => $option->id,
                    'trade_date' => $this->date
                ],
                $priceData
            );
        } catch (\Exception $e) {
            Log::warning('權證價格資料爬取失敗', [
                'warrant_code' => $warrantCode,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * 任務失敗時的處理
     */
    public function failed(\Throwable $exception)
    {
        Log::error('權證資料爬蟲任務失敗', [
            'date' => $this->date,
            'error' => $exception->getMessage()
        ]);
    }
}
